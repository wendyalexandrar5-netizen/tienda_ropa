/**
 * FIRE CAT · Pago en línea en dos pestañas (Wompi / PSE, ambiente sandbox).
 *
 *  - Checkout (#form-pago-en-linea): al pulsar «Pagar» se abre UNA pestaña nueva con la
 *    pasarela real (el formulario se envía a esa pestaña) y ESTA pestaña pasa al comprobante.
 *    Si el navegador bloquea la pestaña nueva, el pago continúa en esta misma pestaña.
 *  - Pestaña de la pasarela (data-redirigir): redirige a la URL de Wompi validada por el servidor.
 *  - Comprobante (#comprobante-pago): espera inicial de 5 s y luego consulta al backend
 *    periódicamente. El estado mostrado SIEMPRE viene del servidor; esperar no aprueba nada.
 * Ninguna pestaña se cierra automáticamente.
 */
(function () {
  'use strict';

  // 1. Checkout: abrir la pasarela en otra pestaña --------------------------------
  document.querySelectorAll('form[data-dos-pestanas]').forEach(function (formPago) {
    var enviando = false;
    formPago.addEventListener('submit', function (ev) {
      if (enviando) { ev.preventDefault(); return; }
      enviando = true;
      var boton = formPago.querySelector('[type="submit"]');
      if (boton) {
        boton.dataset.textoOriginal = boton.dataset.textoOriginal || boton.innerHTML;
        boton.disabled = true;
        boton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Abriendo ' + formPago.dataset.proveedor + '…';
      }
      var nombre = formPago.dataset.ventana;
      var ventana = null;
      try { ventana = window.open('', nombre); } catch (e) { ventana = null; }
      if (!ventana) {
        // Bloqueada: el pago sigue en esta pestaña (el formulario se envía normalmente).
        var aviso = formPago.querySelector('[data-aviso-ventana]');
        if (aviso) {
          aviso.textContent = 'Tu navegador bloqueó la pestaña nueva: el pago continuará en esta pestaña. Al volver de la pasarela verás tu comprobante.';
          aviso.classList.remove('d-none');
        }
        formPago.removeAttribute('target');
        return;
      }
      try {
        ventana.document.title = 'Conectando con ' + formPago.dataset.proveedor + '…';
        ventana.document.body.style.cssText = 'font-family:system-ui,sans-serif;background:#0d0d0d;color:#fff;display:grid;place-items:center;min-height:100vh;margin:0';
        ventana.document.body.textContent = 'Conectando con la pasarela de pago…';
      } catch (e) { /* sin acceso: no importa */ }
      formPago.target = nombre;
      // El envío lo hace el navegador (no se cancela el evento). Luego esta pestaña muestra el comprobante.
      window.setTimeout(function () {
        if (formPago.dataset.comprobanteUrl) {
          window.location.href = formPago.dataset.comprobanteUrl;
        } else {
          // Reintento desde el comprobante: se queda aquí y vuelve a consultar el estado.
          enviando = false;
          if (boton) { boton.disabled = false; boton.innerHTML = boton.dataset.textoOriginal || boton.innerHTML; }
          document.dispatchEvent(new CustomEvent('pago:reintentado'));
        }
      }, 400);
    });
  });

  // 2. Pestaña de la pasarela: redirección a la URL real de Wompi -----------------
  var redir = document.querySelector('[data-redirigir]');
  if (redir) {
    // La pasarela no necesita acceso a la pestaña de la tienda.
    try { window.opener = null; } catch (e) { /* noop */ }
    window.setTimeout(function () { window.location.replace(redir.dataset.redirigir); }, 700);
  }

  // 3. Comprobante con actualización automática -----------------------------------
  var raiz = document.getElementById('comprobante-pago');
  if (!raiz) return;

  var urlEstado = raiz.dataset.urlEstado;
  var espera = parseInt(raiz.dataset.espera || '5', 10) * 1000;
  var intervalo = Math.max(3, parseInt(raiz.dataset.intervalo || '6', 10)) * 1000;
  var carga = document.getElementById('pago-cargando');
  var vista = document.getElementById('pago-comprobante');
  var estadoVivo = document.getElementById('pago-estado-vivo');
  var temporizador = null;
  var intentosSinPedido = 0;
  var fallosRed = 0;
  var inicio = Date.now();

  function $(id) { return document.getElementById(id); }
  function texto(id, valor) { var n = $(id); if (n) n.textContent = valor == null || valor === '' ? '—' : String(valor); }
  function mostrarFila(id, valor) {
    var fila = $(id);
    if (!fila) return;
    fila.hidden = !valor;
    var v = fila.querySelector('[data-valor]');
    if (v) v.textContent = valor || '';
  }

  function pintar(d) {
    var p = d.pedido, pg = d.pago;
    texto('cp-codigo', p.codigo);
    texto('cp-fecha', p.fecha);
    texto('cp-estado-pedido', p.estado);
    texto('cp-metodo', p.metodo);
    texto('cp-cliente-nombre', p.cliente.nombre);
    texto('cp-cliente-email', p.cliente.email);
    texto('cp-cliente-telefono', p.cliente.telefono);
    texto('cp-cliente-direccion', p.cliente.direccion);
    texto('cp-subtotal', p.subtotal);
    texto('cp-envio', p.envio);
    texto('cp-total', p.total);
    mostrarFila('cp-fila-proveedor', pg.proveedor);
    mostrarFila('cp-fila-ambiente', pg.ambiente);
    mostrarFila('cp-fila-referencia', pg.referencia);
    mostrarFila('cp-fila-transaccion', pg.transaccion_id);
    mostrarFila('cp-fila-medio', pg.medio);
    mostrarFila('cp-fila-banco', pg.banco);
    mostrarFila('cp-fila-verificado', pg.verificado_en);
    mostrarFila('cp-fila-detalle', pg.detalle);

    var cuerpo = $('cp-items');
    cuerpo.textContent = '';
    p.items.forEach(function (i) {
      var tr = document.createElement('tr');
      [i.nombre + ' (' + i.variante + ')', String(i.cantidad), i.precio, i.subtotal].forEach(function (t, idx) {
        var td = document.createElement('td');
        td.textContent = t;
        if (idx > 0) td.className = idx === 1 ? 'text-center' : 'text-end';
        tr.appendChild(td);
      });
      cuerpo.appendChild(tr);
    });

    var etiqueta = $('cp-etiqueta');
    etiqueta.className = 'badge rounded-pill fs-6 text-bg-' + pg.color + (pg.color === 'warning' ? ' text-dark' : '');
    etiqueta.textContent = '';
    var ic = document.createElement('i');
    ic.className = 'bi ' + pg.icono + ' me-1';
    etiqueta.appendChild(ic);
    etiqueta.appendChild(document.createTextNode(pg.etiqueta));

    var alerta = $('cp-mensaje');
    var tipo = { aprobado: 'success', pendiente: 'warning', rechazado: 'danger', anulado: 'danger', error: 'danger' }[pg.clave] || 'secondary';
    alerta.className = 'alert alert-' + tipo + ' d-flex gap-2 align-items-center mb-0';
    texto('cp-mensaje-texto', pg.mensaje);
    $('cp-mensaje-icono').className = 'bi ' + pg.icono + ' fs-4';

    $('cp-seguimiento').hidden = pg.final;
    texto('cp-consultado', d.consultado_en);
    $('cp-reintentar').hidden = !d.acciones.reintentar;
    var ir = $('cp-ir-pasarela');
    ir.hidden = !d.acciones.ir_pasarela;
    if (d.acciones.ir_pasarela) ir.href = d.acciones.ir_pasarela;
    $('cp-detalle').href = p.url_detalle;
    $('cp-reintentar-token').value = p.token || '';
    $('cp-reintentar').hidden = !d.acciones.reintentar || !p.token;

    if (estadoVivo) estadoVivo.textContent = pg.etiqueta + '. ' + pg.mensaje;
    carga.hidden = true;
    vista.hidden = false;
  }

  function mostrarError(msg) {
    $('pago-cargando-error').hidden = false;
    texto('pago-cargando-error-texto', msg);
    $('pago-cargando-spinner').hidden = true;
  }

  function programar(ms) {
    window.clearTimeout(temporizador);
    temporizador = window.setTimeout(consultar, ms);
  }

  function consultar() {
    fetch(urlEstado, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401) throw new Error('sesion');
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (d) {
        fallosRed = 0;
        if (!d.encontrado) {
          if (d.error) { mostrarError(d.error); return; }
          // El pedido aún se está creando en la otra pestaña: se reintenta unos segundos más.
          if (++intentosSinPedido > 20) { mostrarError('Aún no encontramos el pedido. Revisa «Mis pedidos» o vuelve a intentarlo.'); return; }
          programar(1500);
          return;
        }
        pintar(d);
        if (!d.pago.final) programar(intervalo);
      })
      .catch(function (e) {
        if (e.message === 'sesion') { mostrarError('Tu sesión expiró. Inicia sesión para ver el comprobante.'); return; }
        fallosRed++;
        if (carga.hidden === false && fallosRed > 3) mostrarError('No pudimos consultar el estado. Reintentaremos automáticamente.');
        programar(Math.min(30000, intervalo * fallosRed));
      });
  }

  document.addEventListener('pago:reintentado', function () {
    $('cp-seguimiento').hidden = false;
    programar(espera);
  });

  var actualizarAhora = $('cp-actualizar');
  if (actualizarAhora) actualizarAhora.addEventListener('click', function () { programar(0); });

  // Espera inicial (sólo presentación): no confirma ni cambia el estado del pago.
  programar(Math.max(0, espera - (Date.now() - inicio)));
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && vista.hidden === false && !$('cp-seguimiento').hidden) programar(300);
  });
})();
