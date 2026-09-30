/**
 * FIRE CAT · JavaScript del panel: gráficos (Chart.js) y vista previa de imágenes.
 * Los datos de los gráficos llegan en <script type="application/json"> (no ejecutable),
 * así la política CSP no necesita permitir scripts en línea.
 */
(function () {
  'use strict';

  var dinero = function (v) {
    return '$' + Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  };
  var PALETA = ['#ffc107', '#0dcaf0', '#0d6efd', '#212529', '#198754', '#dc3545', '#6f42c1', '#fd7e14'];

  if (window.Chart) {
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#6d6a64';

    document.querySelectorAll('canvas[data-grafico]').forEach(function (canvas) {
      var fuente = document.getElementById(canvas.dataset.fuente);
      if (!fuente) return;
      var datos = JSON.parse(fuente.textContent);
      var tipo = canvas.dataset.grafico;
      var config;

      if (tipo === 'linea' || tipo === 'barras') {
        config = {
          type: tipo === 'linea' ? 'line' : 'bar',
          data: {
            labels: datos.etiquetas,
            datasets: [{
              label: datos.titulo || 'Ventas',
              data: datos.valores,
              borderColor: '#0e0e0e',
              backgroundColor: tipo === 'linea' ? 'rgba(255, 229, 0, .35)' : '#0e0e0e',
              fill: tipo === 'linea',
              tension: .35,
              pointBackgroundColor: '#ffe500',
              pointBorderColor: '#0e0e0e',
              borderRadius: 6
            }]
          },
          options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return datos.moneda === false ? c.parsed.y + ' uds' : dinero(c.parsed.y); } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return datos.moneda === false ? v : dinero(v); } } }, x: { grid: { display: false } } }
          }
        };
      } else if (tipo === 'combinado') {
        config = {
          type: 'bar',
          data: {
            labels: datos.etiquetas,
            datasets: datos.series.map(function (s) {
              return {
                type: s.tipo || 'bar', label: s.titulo, data: s.valores,
                backgroundColor: s.color, borderColor: s.color, borderRadius: 6,
                tension: .35, pointBackgroundColor: s.color, borderWidth: s.tipo === 'line' ? 3 : 0, order: s.tipo === 'line' ? 0 : 1
              };
            })
          },
          options: {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } },
                       tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + dinero(c.parsed.y); } } } },
            scales: { y: { ticks: { callback: function (v) { return dinero(v); } } }, x: { grid: { display: false } } }
          }
        };
      } else {
        config = {
          type: 'doughnut',
          data: { labels: datos.etiquetas, datasets: [{ data: datos.valores, backgroundColor: PALETA, borderWidth: 2, borderColor: '#fff' }] },
          options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
        };
      }
      new Chart(canvas, config);
    });
  }

  // Vista previa de imagen antes de subirla
  document.querySelectorAll('input[type=file][data-vista-previa]').forEach(function (input) {
    input.addEventListener('change', function () {
      var img = document.getElementById(input.dataset.vistaPrevia);
      var archivo = input.files && input.files[0];
      if (!img || !archivo) return;
      if (archivo.size > 2 * 1024 * 1024) {
        alert('La imagen supera 2 MB.');
        input.value = '';
        return;
      }
      img.src = URL.createObjectURL(archivo);
    });
  });

  // Marcar / desmarcar todas las casillas de un grupo
  document.querySelectorAll('[data-marcar-todos]').forEach(function (b) {
    b.addEventListener('click', function () {
      var cajas = document.querySelectorAll('input[name="' + b.dataset.marcarTodos + '"]');
      var marcar = Array.prototype.some.call(cajas, function (c) { return !c.checked; });
      cajas.forEach(function (c) { c.checked = marcar; });
    });
  });

  // Filtros de periodo: si se editan las fechas, el periodo pasa a "rango"
  document.querySelectorAll('form').forEach(function (form) {
    var periodo = form.querySelector('select[name="periodo"]');
    if (!periodo || !periodo.querySelector('option[value="rango"]')) return;
    form.querySelectorAll('input[name="desde"], input[name="hasta"]').forEach(function (i) {
      i.addEventListener('change', function () { periodo.value = 'rango'; });
    });
  });

  // Líneas dinámicas (compras de materiales y fichas técnicas) con cálculo en vivo
  var numero = function (v) {
    v = String(v || '').replace(/\s|\$/g, '');
    if (v.indexOf(',') !== -1 && v.indexOf('.') === -1) v = v.replace(',', '.');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  };
  document.querySelectorAll('table[data-lineas]').forEach(function (tabla) {
    var tipo = tabla.dataset.lineas;
    var cuerpo = tabla.querySelector('tbody');
    var plantilla = document.getElementById('plantilla-' + tipo);
    var formulario = tabla.closest('form');

    var recalcular = function () {
      var total = 0;
      cuerpo.querySelectorAll('tr').forEach(function (fila) {
        var sel = fila.querySelector('[data-material]');
        var opcion = sel && sel.selectedOptions[0];
        var unidad = fila.querySelector('[data-unidad-linea]');
        if (unidad) unidad.textContent = opcion && opcion.dataset.unidad ? opcion.dataset.unidad : '—';
        var cantidad = numero((fila.querySelector('[data-cantidad-linea]') || {}).value);
        var sub = 0;
        if (tipo === 'compra') {
          sub = cantidad * numero((fila.querySelector('[data-costo-linea]') || {}).value);
        } else if (opcion && opcion.dataset.costo) {
          sub = cantidad * (1 + numero((fila.querySelector('[data-merma-linea]') || {}).value) / 100) * numero(opcion.dataset.costo);
        }
        total += sub;
        var celda = fila.querySelector('[data-subtotal-linea]');
        if (celda) celda.textContent = dinero(sub);
      });
      var celdaTotal = tabla.querySelector('[data-total-lineas]');
      if (celdaTotal) celdaTotal.textContent = dinero(total);
      if (formulario && formulario.hasAttribute('data-ficha')) {
        formulario.querySelectorAll('[data-costo-fijo]').forEach(function (i) { total += numero(i.value); });
        var caja = formulario.querySelector('[data-precio]');
        if (caja) {
          caja.querySelector('[data-costo-total]').textContent = dinero(total);
          var margen = numero(caja.dataset.precio) - total;
          var m = caja.querySelector('[data-margen]');
          m.textContent = dinero(margen) + ' (' + (numero(caja.dataset.precio) > 0 ? Math.round(margen / numero(caja.dataset.precio) * 100) : 0) + ' %)';
          m.className = margen < 0 ? 'cifra-negativa' : 'cifra-positiva';
        }
      }
    };

    tabla.addEventListener('input', recalcular);
    tabla.addEventListener('change', function (ev) {
      var sel = ev.target.closest('[data-material]');
      if (sel) {
        var fila = sel.closest('tr');
        var opcion = sel.selectedOptions[0];
        var costo = fila.querySelector('[data-costo-linea]');
        if (costo && !costo.value && opcion && opcion.dataset.costo) costo.value = Math.round(numero(opcion.dataset.costo));
        var merma = fila.querySelector('[data-merma-linea]');
        if (merma && (merma.value === '' || merma.value === '0') && opcion && opcion.dataset.merma) merma.value = opcion.dataset.merma;
      }
      recalcular();
    });
    tabla.addEventListener('click', function (ev) {
      var quitar = ev.target.closest('[data-quitar-linea]');
      if (!quitar) return;
      if (cuerpo.querySelectorAll('tr').length > 1) quitar.closest('tr').remove();
      else quitar.closest('tr').querySelectorAll('input, select').forEach(function (i) { i.value = i.hasAttribute('data-merma-linea') ? '0' : ''; });
      recalcular();
    });
    var agregar = tabla.querySelector('[data-agregar-linea]');
    if (agregar && plantilla) {
      agregar.addEventListener('click', function () {
        cuerpo.appendChild(plantilla.content.cloneNode(true));
        var ultima = cuerpo.lastElementChild.querySelector('select');
        if (ultima) ultima.focus();
      });
    }
    if (formulario) formulario.addEventListener('input', function (ev) { if (ev.target.hasAttribute('data-costo-fijo')) recalcular(); });
    recalcular();
  });
})();
