/* =============================================================================
   FIRE CAT · JavaScript del panel administrativo
   Gráficos (Chart.js), caja / venta en tienda y menú lateral móvil.
   ============================================================================= */
(function () {
  'use strict';
  const { api, toast, money, setLoading } = window.FC;

  // ------------------------------------------------ Menú lateral móvil ---
  const sidebar = document.getElementById('adminSidebar');
  const backdrop = document.querySelector('[data-sidebar-close]');
  document.querySelector('[data-sidebar-open]')?.addEventListener('click', () => { sidebar.classList.add('open'); backdrop.classList.add('show'); });
  backdrop?.addEventListener('click', () => { sidebar.classList.remove('open'); backdrop.classList.remove('show'); });

  // --------------------------------------------------------- Gráficos ---
  if (window.Chart) {
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.color = '#6b6b68';
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
    const fmtDate = (iso, opts) => new Date(iso + 'T00:00:00').toLocaleDateString('es-CO', opts || { day: 'numeric', month: 'short' });
    const moneyTick = (v) => '$' + (v >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : v >= 1e3 ? Math.round(v / 1e3) + 'k' : v);
    const grid = { color: '#efeee9' };

    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
      let series = [];
      try { series = JSON.parse(canvas.dataset.series || '[]'); } catch (_) { series = []; }
      const type = canvas.dataset.chart;

      if (type === 'sales') {
        new Chart(canvas, {
          type: 'bar',
          data: {
            labels: series.map((r) => fmtDate(r.periodo)),
            datasets: [
              { label: 'Ventas', data: series.map((r) => r.total), backgroundColor: '#111', hoverBackgroundColor: '#FFE600', borderRadius: 6, maxBarThickness: 26 },
            ],
          },
          options: {
            maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            scales: { y: { grid, ticks: { callback: moneyTick } }, x: { grid: { display: false } } },
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => 'Ventas: ' + money(c.raw) + ' · ' + series[c.dataIndex].pedidos + ' pedido(s)' } } },
          },
        });
      }

      if (type === 'sales-stacked') {
        new Chart(canvas, {
          type: 'bar',
          data: {
            labels: series.map((r) => fmtDate(r.periodo)),
            datasets: [
              { label: 'Online (web/app)', data: series.map((r) => r.web), backgroundColor: '#111', borderRadius: 4, maxBarThickness: 34 },
              { label: 'Tienda física (caja)', data: series.map((r) => r.pos), backgroundColor: '#FFE600', borderRadius: 4, maxBarThickness: 34 },
            ],
          },
          options: {
            maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, grid, ticks: { callback: moneyTick } } },
            plugins: { tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + money(c.raw) } } },
          },
        });
      }

      if (type === 'status') {
        const palette = { warning: '#f2c200', info: '#3d8bd9', primary: '#6a5acd', success: '#1f7a3d', danger: '#c62828', secondary: '#9e9e96' };
        new Chart(canvas, {
          type: 'doughnut',
          data: { labels: series.map((r) => r.nombre), datasets: [{ data: series.map((r) => r.pedidos), backgroundColor: series.map((r) => palette[r.color] || '#999'), borderWidth: 2, borderColor: '#fff' }] },
          options: { maintainAspectRatio: false, cutout: '64%', plugins: { legend: { position: 'bottom' } } },
        });
      }

      if (type === 'customers') {
        new Chart(canvas, {
          type: 'bar',
          data: {
            labels: series.map((r) => fmtDate(r.periodo, { month: 'short', year: '2-digit' })),
            datasets: [
              { type: 'line', label: 'Acumulado', data: series.map((r) => r.acumulado), borderColor: '#111', backgroundColor: '#111', tension: .3, yAxisID: 'y1' },
              { label: 'Nuevos', data: series.map((r) => r.nuevos), backgroundColor: '#FFE600', borderRadius: 6, maxBarThickness: 28 },
            ],
          },
          options: { maintainAspectRatio: false, scales: { y: { grid, ticks: { precision: 0 } }, y1: { position: 'right', grid: { display: false }, ticks: { precision: 0 } }, x: { grid: { display: false } } } },
        });
      }
    });
  }

  // ------------------------------------------ Caja / venta en tienda ---
  const pos = document.querySelector('[data-pos]');
  if (!pos) return;

  const search = pos.querySelector('[data-pos-search]');
  const results = pos.querySelector('[data-pos-results]');
  const linesBox = pos.querySelector('[data-pos-lines]');
  const totalEl = pos.querySelector('[data-pos-total]');
  const paidEl = pos.querySelector('[data-pos-paid]');
  const changeEl = pos.querySelector('[data-pos-change]');
  const submit = pos.querySelector('[data-pos-submit]');
  const lines = new Map();   // variante_id → {variante, cantidad}
  let timer = null;

  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };

  function renderResults(items) {
    results.replaceChildren();
    if (!items.length) { results.append(el('p', 'text-muted-fc small m-0', 'Sin resultados.')); return; }
    items.forEach((v) => {
      const b = el('button', 'pos-item');
      b.type = 'button';
      b.disabled = v.stock <= 0;
      const img = el('img'); img.src = v.imagen_url || '/assets/img/placeholder.svg'; img.alt = '';
      const info = el('div', 'flex-grow-1');
      info.append(el('div', 'fw-600', v.producto_nombre), el('div', 'small text-muted-fc', `${v.talla} · ${v.color} · ${v.sku}`));
      const right = el('div', 'text-end');
      right.append(el('div', 'fw-600', money(v.precio)), el('div', 'small ' + (v.stock <= 0 ? 'text-danger' : 'text-muted-fc'), v.stock <= 0 ? 'Agotado' : `Stock ${v.stock}`));
      b.append(img, info, right);
      b.addEventListener('click', () => addLine(v));
      results.append(b);
    });
  }

  function addLine(v) {
    const current = lines.get(v.id);
    const qty = (current ? current.cantidad : 0) + 1;
    if (qty > v.stock) { toast(`Solo hay ${v.stock} unidades de esta variante.`, 'error'); return; }
    lines.set(v.id, { variante: v, cantidad: qty });
    render();
  }

  function render() {
    linesBox.replaceChildren();
    let total = 0;
    if (!lines.size) linesBox.append(el('p', 'text-muted-fc small', 'Agrega productos desde el buscador.'));
    lines.forEach(({ variante: v, cantidad }, id) => {
      total += v.precio * cantidad;
      const row = el('div', 'd-flex align-items-center gap-2 mb-2');
      const info = el('div', 'flex-grow-1 small');
      info.append(el('div', 'fw-600', v.producto_nombre), el('div', 'text-muted-fc', `${v.talla} · ${v.color} · ${money(v.precio)}`));
      const qty = el('input', 'form-control form-control-sm'); qty.type = 'number'; qty.min = 1; qty.max = v.stock; qty.value = cantidad; qty.style.maxWidth = '70px';
      qty.setAttribute('aria-label', 'Cantidad');
      qty.addEventListener('change', () => {
        const n = Math.max(1, Math.min(v.stock, parseInt(qty.value, 10) || 1));
        lines.set(id, { variante: v, cantidad: n }); render();
      });
      const rm = el('button', 'btn btn-light-fc btn-sm'); rm.type = 'button'; rm.innerHTML = '<i class="bi bi-x-lg"></i>'; rm.setAttribute('aria-label', 'Quitar');
      rm.addEventListener('click', () => { lines.delete(id); render(); });
      row.append(info, qty, el('div', 'small fw-600 text-end', money(v.precio * cantidad)), rm);
      row.lastChild.previousSibling.style.minWidth = '80px';
      linesBox.append(row);
    });
    totalEl.textContent = money(total);
    const paid = Number(paidEl.value) || 0;
    changeEl.textContent = paid >= total && total > 0 ? money(paid - total) : '$ 0';
    submit.disabled = !lines.size || paid < total;
  }

  search.addEventListener('input', () => {
    clearTimeout(timer);
    const q = search.value.trim();
    if (q.length < 2) return;
    timer = setTimeout(async () => {
      const res = await api('GET', '/api/admin/pos/search?q=' + encodeURIComponent(q));
      if (res.ok) renderResults(res.data); else toast(res.error.message, 'error');
    }, 250);
  });
  paidEl.addEventListener('input', render);
  pos.querySelector('[data-pos-clear]').addEventListener('click', () => { lines.clear(); paidEl.value = ''; render(); });

  submit.addEventListener('click', async () => {
    // Solo se envían variante y cantidad: el precio lo determina el servidor.
    const items = [...lines.values()].map((l) => ({ variante_id: l.variante.id, cantidad: l.cantidad }));
    setLoading(submit, true);
    const res = await api('POST', '/api/admin/pos/sales', {
      items,
      pago_recibido: Number(paidEl.value) || 0,
      metodo_pago: pos.querySelector('[data-pos-method]').value,
      cliente_nombre: pos.querySelector('[data-pos-customer]').value,
    });
    setLoading(submit, false);
    if (!res.ok) { toast(res.error.message, 'error'); return; }
    toast(`Venta ${'FC-' + String(res.data.numero).padStart(6, '0')} registrada · cambio ${money(res.data.cambio)}`, 'success', { href: '/admin/pedidos/' + res.data.id + '/comprobante', text: 'Comprobante' });
    lines.clear(); paidEl.value = ''; pos.querySelector('[data-pos-customer]').value = ''; results.replaceChildren(); search.value = '';
    render();
  });
  render();
})();
