/*
 * Prueba E2E en navegador real (Playwright + Chromium) de los flujos
 * principales: registro, compra completa, historial, cancelación y panel
 * administrativo (productos, variantes, imágenes, inventario, pedidos, caja).
 *
 * Requisitos: servidor en BASE_URL (por defecto http://127.0.0.1:8080),
 * datos de prueba (php scripts/seed_demo.php) y Playwright instalado.
 *   node tests/e2e/flujo_completo.js
 */
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); } catch (_) {
  ({ chromium } = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'));
}

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const SHOTS = process.env.SHOTS_DIR || '';
const ADMIN = { email: 'admin@firecat.test', password: process.env.DEMO_PASSWORD || 'FireCat-Demo-2026' };
const results = [];
const ok = (name) => { results.push(['OK', name]); console.log('  ✔ ' + name); };
const fail = (name, err) => { results.push(['FALLO', name]); console.log('  ✘ ' + name + ' → ' + err); };

async function step(name, fn) {
  try { await fn(); ok(name); } catch (e) { fail(name, e.message.split('\n')[0]); }
}

(async () => {
  const browser = await chromium.launch();
  const newCtx = (w = 1366, h = 900) => browser.newContext({ viewport: { width: w, height: h }, ignoreHTTPSErrors: true });
  const jsErrors = [];
  const watch = (page) => {
    page.on('pageerror', (e) => jsErrors.push(e.message));
    page.on('response', (r) => { if (r.status() >= 500) jsErrors.push(`HTTP ${r.status()} ${r.url()}`); });
  };
  const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true }); };

  // ------------------------------------------------------------ CLIENTE
  console.log('\nCliente');
  const ctx = await newCtx();
  const page = await ctx.newPage();
  watch(page);
  const email = `e2e.${Date.now()}@firecat.test`;
  let orderUrl = '';

  await step('Registro de cuenta nueva (Supabase Auth)', async () => {
    await page.goto(BASE + '/registro');
    await page.fill('#nombre', 'Prueba');
    await page.fill('#apellido', 'Automatizada');
    await page.fill('#email', email);
    await page.fill('#password', 'Clave12345');
    await page.fill('#password_confirmacion', 'Clave12345');
    await page.check('#acepta_terminos');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.waitForSelector('text=Tu cuenta fue creada');
  });

  await step('Búsqueda y filtros del catálogo', async () => {
    await page.goto(BASE + '/catalogo?q=hoodie&disponible=1');
    const n = await page.locator('.product-card').count();
    if (n < 1) throw new Error('sin resultados');
    await shot(page, 'catalogo');
  });

  await step('Detalle: seleccionar talla/color y agregar al carrito', async () => {
    await page.goto(BASE + '/producto/hoodie-layered-urban');
    await page.click('.color-btn:not([disabled])');
    await page.click('.size-btn:not([disabled])');
    await page.click('[data-add-btn]');
    await page.waitForSelector('.toast-fc:has-text("Agregado al carrito")');
    const count = await page.textContent('[data-cart-count]');
    if (Number(count) < 1) throw new Error('contador no actualizado');
    await shot(page, 'producto');
  });

  await step('Carrito: modificar cantidad', async () => {
    await page.goto(BASE + '/carrito');
    await Promise.all([page.waitForNavigation(), page.click('[data-cart-qty] [data-step="1"]')]);
    const val = await page.inputValue('[data-cart-qty] input');
    if (val !== '2') throw new Error('cantidad = ' + val);
    await shot(page, 'carrito');
  });

  await step('Checkout: crear dirección y confirmar pedido', async () => {
    await page.goto(BASE + '/checkout');
    await page.fill('#nuevaDireccion [name=telefono]', '3001234567');
    await page.fill('#nuevaDireccion [name=direccion]', 'Calle 100 # 15-20');
    await page.fill('#nuevaDireccion [name=ciudad]', 'Bogotá');
    await page.fill('#nuevaDireccion [name=departamento]', 'Cundinamarca');
    await Promise.all([page.waitForNavigation(), page.click('#nuevaDireccion button[type=submit]')]);
    await shot(page, 'checkout');
    await Promise.all([page.waitForURL(/\/pedido\/.+\/confirmacion/), page.click('button[form=checkoutForm]')]);
    await page.waitForSelector('text=¡Gracias por tu compra!');
    orderUrl = page.url();
    await shot(page, 'confirmacion');
  });

  await step('Historial y cancelación del pedido (repone inventario)', async () => {
    await page.goto(BASE + '/cuenta/pedidos');
    await page.click('.order-row');
    page.once('dialog', (d) => d.accept());
    await Promise.all([page.waitForNavigation(), page.click('text=Cancelar pedido')]);
    await page.waitForSelector('text=Tu pedido fue cancelado');
    await shot(page, 'pedido-cancelado');
  });

  await step('Cliente NO accede al panel administrativo (403)', async () => {
    const r = await page.goto(BASE + '/admin');
    if (r.status() !== 403) throw new Error('status ' + r.status());
  });

  await step('Cerrar sesión', async () => {
    await page.goto(BASE + '/cuenta');
    await page.click('.account-nav button[type=submit]');
    await page.waitForSelector('text=Cerraste sesión');
  });

  // ------------------------------------------------------------ ADMIN
  console.log('\nAdministrador');
  const actx = await newCtx(1440, 900);
  const admin = await actx.newPage();
  watch(admin);

  await step('Login de administrador redirige al dashboard', async () => {
    await admin.goto(BASE + '/login');
    await admin.fill('#email', ADMIN.email);
    await admin.fill('#password', ADMIN.password);
    await Promise.all([admin.waitForNavigation(), admin.click('button[type=submit]')]);
    if (!admin.url().endsWith('/admin')) throw new Error(admin.url());
    await admin.waitForTimeout(600);
    await shot(admin, 'admin-dashboard');
  });

  for (const [p, name] of [['/admin/productos', 'productos'], ['/admin/categorias', 'categorias'], ['/admin/variantes', 'variantes'],
    ['/admin/inventario', 'inventario'], ['/admin/pedidos', 'pedidos'], ['/admin/clientes', 'clientes'],
    ['/admin/reportes', 'reportes'], ['/admin/configuracion', 'configuracion'], ['/admin/caja', 'caja']]) {
    await step('Página ' + p, async () => {
      const r = await admin.goto(BASE + p);
      if (r.status() !== 200) throw new Error('status ' + r.status());
      await admin.waitForTimeout(300);
      await shot(admin, 'admin-' + name);
    });
  }

  let productUrl = '';
  await step('Crear producto', async () => {
    await admin.goto(BASE + '/admin/productos/nuevo');
    await admin.fill('#nombre', 'Camiseta Oversize E2E ' + Date.now());
    await admin.selectOption('#categoria_id', { label: 'Camisetas' });
    await admin.fill('#descripcion', 'Producto creado por la prueba E2E.');
    await admin.fill('#precio', '80000');
    await admin.selectOption('#estado', 'activo');
    await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Crear producto")')]);
    await admin.waitForSelector('text=Producto creado');
    productUrl = admin.url();
  });

  await step('Agregar variantes (M/Negro stock 15, L/Blanco stock 8)', async () => {
    const form = admin.locator('form[data-action$="/variants"]');
    await form.locator('[name=talla_id]').selectOption({ label: 'M' });
    await form.locator('[name=color_id]').selectOption({ label: 'Negro' });
    await form.locator('[name=stock_inicial]').fill('15');
    await Promise.all([admin.waitForNavigation(), form.locator('button[type=submit]').click()]);
    const form2 = admin.locator('form[data-action$="/variants"]');
    await form2.locator('[name=talla_id]').selectOption({ label: 'L' });
    await form2.locator('[name=color_id]').selectOption({ label: 'Blanco' });
    await form2.locator('[name=stock_inicial]').fill('8');
    await Promise.all([admin.waitForNavigation(), form2.locator('button[type=submit]').click()]);
    const pills = await admin.locator('.stock-pill').allTextContents();
    if (!pills.includes('15') || !pills.includes('8')) throw new Error('stock ' + pills.join(','));
  });

  await step('Subir imagen válida (JPG)', async () => {
    await admin.setInputFiles('input[name=imagen]', path.join(__dirname, '../../public/assets/img/productos/17.jpg'));
    await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Subir imagen")')]);
    if (await admin.locator('.image-tile').count() !== 1) throw new Error('imagen no listada');
    await shot(admin, 'admin-producto');
  });

  await step('Rechazar archivo PHP disfrazado de imagen', async () => {
    const fs = require('fs');
    const fake = path.join(require('os').tmpdir(), 'shell.jpg');
    fs.writeFileSync(fake, '<?php system($_GET["c"]); ?>');
    await admin.setInputFiles('input[name=imagen]', fake);
    await admin.click('button:has-text("Subir imagen")');
    await admin.waitForSelector('.toast-fc.is-error');
  });

  await step('Ajuste de inventario con kardex', async () => {
    await admin.goto(BASE + '/admin/inventario?q=E2E');
    const form = admin.locator('form[data-action="/api/admin/inventory/adjust"]').first();
    await form.locator('[name=cantidad]').fill('5');
    await form.locator('[name=motivo]').fill('Compra a proveedor (E2E)');
    await Promise.all([admin.waitForNavigation(), form.locator('button[type=submit]').click()]);
    await admin.waitForSelector('text=Compra a proveedor (E2E)');
  });

  await step('Cambiar estado de un pedido pendiente', async () => {
    await admin.goto(BASE + '/admin/pedidos?estado=pendiente');
    await admin.click('table a.fw-600');
    await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Guardar estado")')]);
    await admin.waitForSelector('.badge-estado--info');
    await shot(admin, 'admin-pedido');
  });

  await step('Venta en tienda (caja) con cálculo de cambio', async () => {
    await admin.goto(BASE + '/admin/caja');
    await admin.fill('[data-pos-search]', 'E2E');
    await admin.waitForSelector('.pos-item:not([disabled])');
    await admin.click('.pos-item:not([disabled])');
    await admin.fill('[data-pos-paid]', '100000');
    await admin.click('[data-pos-submit]');
    await admin.waitForSelector('.toast-fc:has-text("registrada")');
  });

  await step('Vista móvil del dashboard', async () => {
    const m = await (await newCtx(390, 844)).newPage();
    await m.context().addCookies(await actx.cookies());
    await m.goto(BASE + '/admin');
    await shot(m, 'admin-movil');
  });

  await browser.close();
  const failed = results.filter((r) => r[0] !== 'OK').length;
  if (jsErrors.length) { console.log('\nErrores JS/HTTP detectados:\n  ' + jsErrors.join('\n  ')); }
  console.log(`\n${results.length - failed}/${results.length} pasos correctos${jsErrors.length ? ' · ' + jsErrors.length + ' errores JS/HTTP' : ''}`);
  process.exit(failed || jsErrors.length ? 1 : 0);
})();
