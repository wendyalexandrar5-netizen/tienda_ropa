/**
 * Prueba en navegador (Playwright/Chromium) del pago en DOS PESTAÑAS.
 *
 * Requiere: Apache con la tienda, la BD importada, el simulador local de Wompi
 *   php -S 127.0.0.1:8099 tests/mock_wompi/router.php
 * y un config/config.local.php temporal que apunte api_url a http://127.0.0.1:8099/v1
 * con llaves de prueba ficticias (ver README, sección "Probar sin Internet").
 *
 * Como este equipo de pruebas no tiene acceso a checkout.wompi.co, la petición del navegador
 * a esa URL se intercepta y se responde con una página de marcador: la prueba verifica que la
 * tienda abre EXACTAMENTE la URL real y firmada del Web Checkout. La aprobación se simula
 * enviando un webhook FIRMADO al backend, igual que haría Wompi.
 *
 * Uso: node tests/navegador_pagos_dos_pestanas.js http://127.0.0.1:8081/tienda_ropa [carpeta_capturas]
 */
'use strict';
const { chromium } = require('playwright');
const crypto = require('crypto');

const BASE = (process.argv[2] || 'http://localhost/tienda_ropa').replace(/\/$/, '');
const CAPTURAS = process.argv[3] || null;
const MOCK = 'http://127.0.0.1:8099';
const SECRETO_INTEGRIDAD = 'test_integrity_mock';
const SECRETO_EVENTOS = 'test_events_mock';

let ok = 0; const fallos = [];
function prueba(nombre, cond, detalle) {
  if (cond) { ok++; console.log('  ✔ ' + nombre); } else { fallos.push(nombre); console.log('  ✘ ' + nombre + (detalle ? ' → ' + detalle : '')); }
}
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');
async function json(url, cuerpo) {
  const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo) });
  return { status: r.status, data: await r.json().catch(() => null) };
}
function eventoFirmado(tx, valido = true) {
  const ts = Math.floor(Date.now() / 1000);
  const props = ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'];
  const cadena = tx.id + tx.status + tx.amount_in_cents + ts + SECRETO_EVENTOS;
  return {
    event: 'transaction.updated', data: { transaction: tx }, environment: 'test', timestamp: ts, sent_at: new Date().toISOString(),
    signature: { properties: props, checksum: valido ? sha(cadena) : sha('manipulado' + cadena) },
  };
}
async function captura(page, nombre) {
  if (CAPTURAS) await page.screenshot({ path: CAPTURAS + '/' + nombre + '.png', fullPage: true });
}
async function agregarAlCarrito(context, varianteId) {
  const html = await (await context.request.get(BASE + '/producto.php?id=1')).text();
  const csrf = (html.match(/name="_csrf" value="([^"]+)"/) || [])[1];
  await context.request.post(BASE + '/carrito.php', { form: { _csrf: csrf, accion: 'agregar', variante_id: String(varianteId), cantidad: '1', retorno: 'carrito.php' } });
}
async function irAResumen(page, metodo, extra) {
  await page.goto(BASE + '/checkout.php');
  await page.fill('#nombre', 'Cliente Demo');
  await page.fill('#telefono', '3001234567');
  await page.fill('#direccion', 'Calle 10 # 20-30');
  await page.fill('#ciudad', 'Bogotá');
  await page.check('#mp-' + metodo);
  if (extra) await extra(page);
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar pedido")')]);
}

(async () => {
  await fetch(MOCK + '/mock/reset');
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  // Respuesta local para la pasarela externa (sin Internet en el entorno de pruebas).
  const urlsPasarela = [];
  await context.route(/^https:\/\/(checkout\.wompi\.co|pse-banco\.simulado\.test)\//, (route) => {
    urlsPasarela.push(route.request().url());
    route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8',
      body: '<!doctype html><title>Pasarela (marcador de prueba)</title><h1>Pasarela externa</h1><p>' + route.request().url().replace(/</g, '&lt;') + '</p>' });
  });

  const page = await context.newPage();
  let navegacionesComprobante = 0;
  page.on('framenavigated', (f) => { if (f === page.mainFrame() && f.url().includes('pago_comprobante.php')) navegacionesComprobante++; });

  console.log('\n1. Login y carrito');
  await page.goto(BASE + '/login.php');
  await page.fill('#email', 'cliente@firecat.com');
  await page.fill('#password', 'Cliente123*');
  await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]')]);
  prueba('Inicia sesión el cliente', !page.url().includes('login.php'), page.url());
  await agregarAlCarrito(context, 56);

  console.log('\n2. Wompi Web Checkout en dos pestañas');
  await irAResumen(page, 'wompi');
  prueba('Resumen muestra el botón «Pagar … con Wompi»', await page.isVisible('#btn-pagar:has-text("Wompi")'));
  await captura(page, '01_resumen_wompi');
  const [popup] = await Promise.all([context.waitForEvent('page'), page.click('#btn-pagar')]);
  await popup.waitForURL(/checkout\.wompi\.co/, { timeout: 15000 });
  await page.waitForURL(/pago_comprobante\.php\?token=/, { timeout: 10000 });
  prueba('Pestaña nueva abre el Web Checkout REAL de Wompi', popup.url().startsWith('https://checkout.wompi.co/p/?'), popup.url());
  const q = new URL(popup.url()).searchParams;
  const ref = q.get('reference'); const centavos = q.get('amount-in-cents');
  prueba('URL con llave pública de PRUEBAS', q.get('public-key') === 'pub_test_mock');
  prueba('Firma de integridad correcta (SHA256 referencia+monto+moneda+secreto)', q.get('signature:integrity') === sha(ref + centavos + 'COP' + SECRETO_INTEGRIDAD));
  prueba('redirect-url apunta a pago_retorno.php de la tienda', (q.get('redirect-url') || '').includes('/pago_retorno.php?ref=' + encodeURIComponent(ref)));
  prueba('Pestaña original pasa al comprobante (misma compra)', page.url().includes('pago_comprobante.php'));
  prueba('Pantalla de espera con el mensaje pedido', await page.isVisible('text=Estamos procesando tu solicitud de pago. En unos segundos podrás consultar el comprobante de tu pedido'));
  await captura(page, '02_espera');
  const tCarga = Date.now();
  await page.waitForSelector('#pago-comprobante:not([hidden])', { timeout: 15000 });
  prueba('El comprobante aparece tras la espera inicial (≈5 s)', Date.now() - tCarga >= 3500, (Date.now() - tCarga) + ' ms');
  prueba('Estado inicial «PAGO PENDIENTE» (esperar no aprueba)', (await page.textContent('#cp-etiqueta')).includes('PAGO PENDIENTE'));
  prueba('Mensaje de pendiente', (await page.textContent('#cp-mensaje-texto')).includes('Tu solicitud de pago fue recibida y está pendiente de confirmación'));
  const codigo = (await page.textContent('#cp-codigo')).trim();
  prueba('Comprobante con número de pedido, cliente y productos', /^FC-\d{6}$/.test(codigo) && (await page.textContent('#cp-cliente-nombre')).includes('Cliente Demo') && (await page.$$('#cp-items tr')).length >= 1);
  prueba('Referencia del pago coincide con la de la pasarela', (await page.textContent('#cp-fila-referencia [data-valor]')).trim() === ref);
  await captura(page, '03_comprobante_pendiente');

  // Parámetros manipulados en la URL de retorno (id inexistente): no aprueban nada
  const falsa = await context.newPage();
  await falsa.goto(BASE + '/pago_retorno.php?ref=' + encodeURIComponent(ref) + '&id=99999-falso-APPROVED&env=test&status=APPROVED');
  await falsa.close();
  await page.waitForTimeout(2500);
  prueba('Retorno con id/estado falsos en la URL NO marca PAGADO', (await page.textContent('#cp-etiqueta')).includes('PAGO PENDIENTE'));

  // El cliente paga en Wompi (simulado); webhook con firma inválida: rechazado
  const tx = (await json(MOCK + '/mock/transaccion', { reference: ref, amount_in_cents: Number(centavos), status: 'APPROVED', type: 'CARD' })).data.data;
  const malo = await json(BASE + '/webhooks/wompi.php', eventoFirmado(tx, false));
  prueba('Webhook con firma inválida → 401', malo.status === 401, String(malo.status));
  // Webhook con monto alterado (firmado con otro monto): no aplica
  const alterado = await json(BASE + '/webhooks/wompi.php', eventoFirmado(Object.assign({}, tx, { id: 'falso-' + Date.now(), amount_in_cents: 100 }), true));
  prueba('Webhook con monto distinto al pedido no se aplica', alterado.status === 200 && /monto/i.test(JSON.stringify(alterado.data)), JSON.stringify(alterado.data));

  // Webhook firmado válido → el comprobante se actualiza solo
  const bueno = await json(BASE + '/webhooks/wompi.php', eventoFirmado(tx, true));
  prueba('Webhook firmado aceptado (200)', bueno.status === 200, JSON.stringify(bueno.data));
  const navAntes = navegacionesComprobante;
  await page.waitForFunction(() => document.getElementById('cp-etiqueta').textContent.includes('PAGADO'), null, { timeout: 20000 });
  prueba('Se actualiza a «PAGADO» sin recargar la página', navegacionesComprobante === navAntes);
  prueba('Mensaje «¡Pago confirmado exitosamente!»', (await page.textContent('#cp-mensaje-texto')).includes('¡Pago confirmado exitosamente!'));
  prueba('Muestra el ID de transacción', (await page.textContent('#cp-fila-transaccion [data-valor]')).trim() === tx.id);
  await captura(page, '04_comprobante_pagado');

  // Wompi redirige la pestaña 1 a la URL de retorno; la pestaña sigue abierta
  await popup.goto(q.get('redirect-url') + '&id=' + encodeURIComponent(tx.id) + '&env=test');
  await popup.waitForSelector('#pago-comprobante:not([hidden])', { timeout: 15000 });
  prueba('Pestaña de la pasarela sigue abierta y muestra el mismo pedido', !popup.isClosed() && (await popup.textContent('#cp-codigo')).trim() === codigo);
  prueba('Ninguna pestaña se cerró automáticamente', !page.isClosed() && !popup.isClosed() && context.pages().length === 2);
  await popup.close();

  console.log('\n3. PSE (vía Wompi) rechazado y reintento sobre el MISMO pedido');
  await agregarAlCarrito(context, 56);
  await irAResumen(page, 'pse', async (p) => {
    await p.selectOption('#pse_banco', '2');
    await p.selectOption('#pse_tipo_documento', 'CC');
    await p.fill('#pse_documento', '1234567890');
    await p.check('#acepto_wompi');
  });
  prueba('Resumen PSE con botón «Pagar … con PSE»', await page.isVisible('#btn-pagar:has-text("PSE")'));
  const [popupPse] = await Promise.all([context.waitForEvent('page'), page.click('#btn-pagar')]);
  await popupPse.waitForURL(/pse-banco\.simulado\.test/, { timeout: 20000 });
  await page.waitForURL(/pago_comprobante\.php/, { timeout: 10000 });
  prueba('Pestaña nueva abre la URL del banco entregada por Wompi (async_payment_url)', popupPse.url().startsWith('https://pse-banco.simulado.test/pagar?tx='));
  await page.waitForSelector('#pago-comprobante:not([hidden])', { timeout: 15000 });
  const codigoPse = (await page.textContent('#cp-codigo')).trim();
  prueba('Pedido PSE pendiente con banco y transacción', (await page.textContent('#cp-etiqueta')).includes('PAGO PENDIENTE') && (await page.textContent('#cp-fila-banco [data-valor]')).includes('Banco que declina'));
  const txPse = (await page.textContent('#cp-fila-transaccion [data-valor]')).trim();
  await json(MOCK + '/mock/estado', { id: txPse, status: 'DECLINED' });
  // Sin webhook: la tienda lo detecta consultando la API desde el servidor
  await page.waitForFunction(() => document.getElementById('cp-etiqueta').textContent.includes('RECHAZADO'), null, { timeout: 25000 });
  prueba('Rechazo detectado por consulta del servidor y mostrado sin recargar', (await page.textContent('#cp-mensaje-texto')).includes('No fue posible completar el pago'));
  prueba('Ofrece «Reintentar el pago»', await page.isVisible('#cp-reintentar button'));
  await captura(page, '05_pse_rechazado');
  const [popupReintento] = await Promise.all([context.waitForEvent('page'), page.click('#cp-reintentar button')]);
  await popupReintento.waitForURL(/pse-banco\.simulado\.test/, { timeout: 20000 });
  await page.waitForFunction(() => document.getElementById('cp-etiqueta').textContent.includes('PAGO PENDIENTE'), null, { timeout: 20000 });
  prueba('Reintento: nueva pestaña de pago y el comprobante vuelve a «PAGO PENDIENTE»', true);
  prueba('El reintento conserva el mismo número de pedido', (await page.textContent('#cp-codigo')).trim() === codigoPse);
  prueba('Se registró un segundo intento de pago', (await page.textContent('#cp-fila-transaccion [data-valor]')).trim() !== txPse);
  await captura(page, '06_pse_reintento');

  // Ventana emergente bloqueada → el pago continúa en la misma pestaña
  console.log('\n4. Navegador que bloquea la pestaña nueva');
  await agregarAlCarrito(context, 56);
  await irAResumen(page, 'wompi');
  await page.evaluate(() => { window.open = () => null; });
  await Promise.all([page.waitForURL(/checkout\.wompi\.co/, { timeout: 15000 }), page.click('#btn-pagar')]);
  prueba('Sin pestaña nueva, la misma pestaña va al Web Checkout', page.url().startsWith('https://checkout.wompi.co/p/?'));

  await browser.close();
  console.log('\n' + ok + ' pruebas correctas, ' + fallos.length + ' fallidas');
  process.exit(fallos.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
