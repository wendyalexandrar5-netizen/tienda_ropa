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
})();
