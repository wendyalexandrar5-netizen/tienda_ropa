/* =============================================================================
   FIRE CAT · JavaScript de la tienda
   -----------------------------------------------------------------------------
   El navegador SOLO mejora la experiencia: toda regla de negocio (precios,
   stock, permisos) se valida en el servidor / base de datos. Este archivo
   consume la misma API REST que usará la futura app móvil.
   Sin scripts inline (compatible con Content-Security-Policy estricta).
   ============================================================================= */
(function () {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const isAuth = document.body.dataset.auth === '1';
  const money = (n) => '$ ' + Math.round(Number(n) || 0).toLocaleString('es-CO');

  // ---------------------------------------------------------------- API ----
  async function api(method, url, body) {
    const opts = {
      method,
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
      credentials: 'same-origin',
    };
    if (body instanceof FormData) {
      opts.body = body;
    } else if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    let res, json = null;
    try {
      res = await fetch(url, opts);
      json = await res.json().catch(() => null);
    } catch (e) {
      return { ok: false, status: 0, error: { code: 'RED', message: 'No hay conexión. Revisa tu internet e inténtalo de nuevo.' } };
    }
    if (res.status === 401 && !url.startsWith('/api/auth')) {
      window.location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
    }
    if (json && json.success) return { ok: true, status: res.status, data: json.data, meta: json.meta };
    return { ok: false, status: res.status, error: (json && json.error) || { code: 'ERROR', message: 'Ocurrió un error inesperado.' } };
  }
  window.FC = { api, toast, money, setLoading, updateCartCount };

  // -------------------------------------------------------------- Toasts ---
  function toast(message, type = 'success', link) {
    const box = document.getElementById('toasts');
    if (!box || !window.bootstrap) { return; }
    const el = document.createElement('div');
    el.className = 'toast toast-fc' + (type === 'error' ? ' is-error' : '');
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const body = document.createElement('div');
    body.className = 'toast-body';
    const icon = document.createElement('i');
    icon.className = 'bi ' + (type === 'error' ? 'bi-exclamation-octagon' : 'bi-check-circle-fill');
    const text = document.createElement('div');
    text.className = 'flex-grow-1';
    text.textContent = message;                       // textContent: nunca HTML (anti-XSS)
    body.append(icon, text);
    if (link) {
      const a = document.createElement('a');
      a.href = link.href; a.textContent = link.text;
      a.className = 'fw-bold text-decoration-underline text-nowrap';
      a.style.color = 'var(--fc-accent)';
      body.append(a);
    }
    el.append(body);
    box.append(el);
    const t = new bootstrap.Toast(el, { delay: type === 'error' ? 6000 : 3500 });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    t.show();
  }

  function setLoading(btn, loading) {
    if (!btn) return;
    if (loading) {
      btn.dataset.html = btn.innerHTML;
      btn.classList.add('is-loading');
      btn.disabled = true;
      const w = btn.offsetWidth; btn.style.minWidth = w + 'px';
      btn.innerHTML = '<span class="spinner-border" role="status" aria-hidden="true"></span>';
    } else {
      btn.classList.remove('is-loading');
      btn.disabled = false;
      if (btn.dataset.html) btn.innerHTML = btn.dataset.html;
    }
  }

  function updateCartCount(count) {
    document.querySelectorAll('[data-cart-count]').forEach((el) => {
      el.dataset.count = String(count);
      el.textContent = count > 0 ? String(count) : '';
    });
  }

  // ------------------------------------------------------ Header scroll ---
  const header = document.querySelector('[data-header]');
  if (header) {
    const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 8);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // -------------------------------------------- Validación del cliente ---
  function validateForm(form) {
    form.querySelectorAll('[data-match]').forEach((input) => {
      const other = form.querySelector(input.dataset.match);
      input.setCustomValidity(other && other.value !== input.value ? 'No coincide' : '');
    });
    const valid = form.checkValidity();
    form.classList.add('was-validated');
    if (!valid) {
      const first = form.querySelector(':invalid');
      if (first) first.focus();
    }
    return valid;
  }

  document.querySelectorAll('form[data-validate]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!validateForm(form)) { e.preventDefault(); e.stopPropagation(); return; }
      const btn = form.querySelector('[type="submit"]');
      setLoading(btn, true);
    });
  });

  document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = document.querySelector(btn.dataset.togglePassword);
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
      btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
  });

  document.querySelectorAll('[data-strength]').forEach((input) => {
    const bar = document.querySelector(input.dataset.strength + ' > div');
    input.addEventListener('input', () => {
      const v = input.value;
      let score = 0;
      if (v.length >= 8) score++;
      if (v.length >= 12) score++;
      if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      const colors = ['#c62828', '#c62828', '#e67e22', '#e6b800', '#1f7a3d', '#1f7a3d'];
      if (bar) { bar.style.width = (score * 20) + '%'; bar.style.background = colors[score]; }
    });
  });

  // Confirmación antes de enviar (formularios clásicos)
  document.querySelectorAll('form[data-confirm]:not([data-api-form])').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });

  // Formularios que se envían al cambiar (ordenar, filtros de estado)
  document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
    form.addEventListener('change', () => form.submit());
  });

  // ------------------------------------------- Formularios hacia la API ---
  // <form data-api-form data-method="POST" data-action="/api/..." data-reload
  //       data-redirect="/ruta/{id}" data-success="Mensaje" data-confirm="¿...?">
  function formToObject(form) {
    const fd = new FormData(form);
    const out = {};
    for (const [key, value] of fd.entries()) {
      if (value instanceof File) continue;
      if (key.endsWith('[]')) { (out[key.slice(0, -2)] = out[key.slice(0, -2)] || []).push(value); continue; }
      out[key] = value;
    }
    [...form.elements].forEach((el) => {
      if (el.type === 'checkbox' && el.name && !el.name.endsWith('[]')) out[el.name] = el.checked;
    });
    return out;
  }

  function clearErrors(form) {
    form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    form.querySelectorAll('[data-error-for]').forEach((el) => { el.textContent = ''; el.classList.remove('d-block'); });
  }

  function showErrors(form, fields) {
    Object.entries(fields || {}).forEach(([name, msg]) => {
      const input = form.querySelector(`[name="${CSS.escape(name)}"]`) || document.querySelector(`[form="${form.id}"][name="${CSS.escape(name)}"]`);
      if (input) input.classList.add('is-invalid');
      const slot = form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
      if (slot) { slot.textContent = msg; slot.classList.add('d-block'); }
      else if (input && input.nextElementSibling?.classList.contains('invalid-feedback')) input.nextElementSibling.textContent = msg;
    });
  }

  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('form[data-api-form]');
    if (!form) return;
    e.preventDefault();
    if (form.dataset.busy === '1') return;
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
    clearErrors(form);
    if (!form.checkValidity()) { form.classList.add('was-validated'); form.querySelector(':invalid')?.focus(); return; }

    const submitter = e.submitter || form.querySelector('[type="submit"]') || document.querySelector(`[form="${form.id}"][type="submit"]`);
    const isMultipart = form.enctype === 'multipart/form-data';
    const body = isMultipart ? new FormData(form) : formToObject(form);
    form.dataset.busy = '1';
    setLoading(submitter, true);
    const res = await api((form.dataset.method || 'POST').toUpperCase(), form.dataset.action || form.action, body);
    form.dataset.busy = '0';

    if (res.ok) {
      if (form.dataset.success) toast(form.dataset.success);
      const id = res.data && (res.data.id || res.data.item_id);
      if (form.dataset.redirect) {
        window.location.href = form.dataset.redirect.replace('{id}', encodeURIComponent(id || ''));
        return;
      }
      if (form.hasAttribute('data-reload')) { setTimeout(() => window.location.reload(), 350); return; }
      setLoading(submitter, false);
      form.dispatchEvent(new CustomEvent('api:success', { detail: res.data }));
      const modal = form.closest('.modal');
      if (modal && window.bootstrap) bootstrap.Modal.getInstance(modal)?.hide();
    } else {
      setLoading(submitter, false);
      showErrors(form, res.error.details && res.error.details.campos);
      toast(res.error.message, 'error');
    }
  });

  // Botones de acción directa: <button data-api="DELETE /api/..." data-confirm data-reload>
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-api]');
    if (!btn) return;
    e.preventDefault();
    if (btn.dataset.confirm && !window.confirm(btn.dataset.confirm)) return;
    const [method, url] = btn.dataset.api.split(' ');
    let payload;
    if (btn.dataset.body) { try { payload = JSON.parse(btn.dataset.body); } catch (_) { payload = undefined; } }
    setLoading(btn, true);
    const res = await api(method, url, payload);
    if (res.ok) {
      if (btn.dataset.success) toast(btn.dataset.success);
      if (btn.dataset.redirect) { window.location.href = btn.dataset.redirect; return; }
      if (btn.hasAttribute('data-reload')) { setTimeout(() => window.location.reload(), 300); return; }
      setLoading(btn, false);
    } else {
      setLoading(btn, false);
      toast(res.error.message, 'error');
    }
  });

  // --------------------------------------------------------- Cantidades ---
  document.addEventListener('click', (e) => {
    const step = e.target.closest('[data-qty] [data-step]');
    if (!step) return;
    const input = step.closest('[data-qty]').querySelector('input');
    const min = Number(input.min || 1), max = Number(input.max || 20);
    const next = Math.min(max, Math.max(min, (Number(input.value) || min) + Number(step.dataset.step)));
    if (next !== Number(input.value)) {
      input.value = next;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });

  // ---------------------------------------------------------- Favoritos ---
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-fav]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    if (!isAuth) { window.location.href = '/login?next=' + encodeURIComponent(location.pathname); return; }
    const id = btn.dataset.fav;
    const active = btn.getAttribute('aria-pressed') === 'true';
    const res = active ? await api('DELETE', '/api/favorites/' + encodeURIComponent(id)) : await api('POST', '/api/favorites', { producto_id: Number(id) });
    if (!res.ok) { toast(res.error.message, 'error'); return; }
    document.querySelectorAll(`[data-fav="${CSS.escape(id)}"]`).forEach((b) => {
      b.setAttribute('aria-pressed', String(!active));
      b.classList.toggle('is-active', !active);
      b.classList.toggle('text-danger', !active && !b.classList.contains('fav-btn'));
      const i = b.querySelector('i');
      if (i) i.className = 'bi ' + (!active ? 'bi-heart-fill' : 'bi-heart');
    });
    toast(active ? 'Eliminado de favoritos' : 'Guardado en favoritos', 'success', active ? null : { href: '/cuenta/favoritos', text: 'Ver' });
  });

  // ------------------------------------------------ Página de carrito ---
  const cartPage = document.querySelector('[data-cart-page]');
  if (cartPage) {
    cartPage.addEventListener('change', async (e) => {
      const wrap = e.target.closest('[data-cart-qty]');
      if (!wrap) return;
      const qty = Number(e.target.value);
      if (!Number.isInteger(qty) || qty < 1) { e.target.value = 1; return; }
      cartPage.style.opacity = '.6';
      const res = await api('PUT', '/api/cart/' + wrap.dataset.cartQty, { cantidad: qty });
      if (res.ok) { window.location.reload(); } else { cartPage.style.opacity = ''; toast(res.error.message, 'error'); }
    });
    cartPage.addEventListener('click', async (e) => {
      const rm = e.target.closest('[data-cart-remove]');
      const clear = e.target.closest('[data-cart-clear]');
      if (!rm && !clear) return;
      if (clear && !window.confirm('¿Vaciar todo el carrito?')) return;
      const res = rm ? await api('DELETE', '/api/cart/' + rm.dataset.cartRemove) : await api('DELETE', '/api/cart');
      if (res.ok) window.location.reload(); else toast(res.error.message, 'error');
    });
  }

  // ---------------------------------------------- Galería de producto ---
  const gallery = document.querySelector('[data-gallery]');
  if (gallery) {
    const main = gallery.querySelector('[data-main-image]');
    gallery.querySelectorAll('.pdp-thumbs button').forEach((b) => {
      b.addEventListener('click', () => {
        gallery.querySelectorAll('.pdp-thumbs button').forEach((x) => x.classList.remove('active'));
        b.classList.add('active');
        main.src = b.dataset.src;
      });
    });
  }

  // ---------------------------------- Selector de variante (talla/color) ---
  const addForm = document.querySelector('[data-add-to-cart]');
  if (addForm) {
    const data = JSON.parse(document.getElementById('product-data').textContent);
    const variants = data.variantes;
    const colorBtns = [...addForm.querySelectorAll('[data-color]')];
    const sizeBtns = [...addForm.querySelectorAll('[data-size]')];
    const hidden = addForm.querySelector('[name="variante_id"]');
    const qtyInput = addForm.querySelector('[name="cantidad"]');
    const note = addForm.querySelector('[data-stock-note]');
    const errorBox = addForm.querySelector('[data-add-error]');
    const priceEl = document.querySelector('[data-price]');
    const addBtn = addForm.querySelector('[data-add-btn]');
    const colorName = addForm.querySelector('[data-color-name]');
    const sizeName = addForm.querySelector('[data-size-name]');
    let color = null, size = null;

    const find = (c, s) => variants.find((v) => v.color_id === c && v.talla_id === s);

    function render() {
      colorBtns.forEach((b) => {
        const c = Number(b.dataset.color);
        b.classList.toggle('active', c === color);
        b.disabled = !variants.some((v) => v.color_id === c && v.stock > 0);
      });
      sizeBtns.forEach((b) => {
        const s = Number(b.dataset.size);
        const v = color !== null ? find(color, s) : variants.find((x) => x.talla_id === s && x.stock > 0);
        b.disabled = !v || v.stock <= 0;
        b.classList.toggle('active', s === size && !b.disabled);
      });
      if (size !== null && sizeBtns.find((b) => Number(b.dataset.size) === size)?.disabled) size = null;
      const v = color !== null && size !== null ? find(color, size) : null;
      hidden.value = v ? v.id : '';
      if (sizeName) sizeName.textContent = size !== null ? sizeBtns.find((b) => Number(b.dataset.size) === size).dataset.name : 'Selecciona una talla';
      if (colorName && color !== null) colorName.textContent = colorBtns.find((b) => Number(b.dataset.color) === color)?.dataset.name || '';
      if (v) {
        if (priceEl) priceEl.textContent = money(v.precio);
        qtyInput.max = Math.max(1, Math.min(20, v.stock));
        if (Number(qtyInput.value) > Number(qtyInput.max)) qtyInput.value = qtyInput.max;
        note.className = 'stock-note mb-4' + (v.stock <= 5 ? ' low' : '');
        note.innerHTML = '<span class="dot"></span>';
        note.append(v.stock <= 5 ? `¡Últimas ${v.stock} unidades!` : 'Disponible · envío en 24–72 h');
        errorBox.textContent = '';
      } else if (note && !note.textContent.includes('agotado')) {
        note.textContent = '';
      }
    }

    colorBtns.forEach((b) => b.addEventListener('click', () => { color = Number(b.dataset.color); render(); }));
    sizeBtns.forEach((b) => b.addEventListener('click', () => { size = Number(b.dataset.size); render(); }));

    // Preselecciona el primer color con stock.
    const firstColor = colorBtns.find((b) => variants.some((v) => v.color_id === Number(b.dataset.color) && v.stock > 0));
    if (firstColor) color = Number(firstColor.dataset.color);
    const availableSizes = sizeBtns.filter((b) => color !== null && find(color, Number(b.dataset.size))?.stock > 0);
    if (availableSizes.length === 1) size = Number(availableSizes[0].dataset.size);
    render();

    addForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!hidden.value) { errorBox.textContent = 'Selecciona una talla y un color disponibles.'; return; }
      if (!isAuth) { window.location.href = '/login?next=' + encodeURIComponent(location.pathname); return; }
      setLoading(addBtn, true);
      const res = await api('POST', '/api/cart', { variante_id: Number(hidden.value), cantidad: Number(qtyInput.value) || 1 });
      setLoading(addBtn, false);
      if (res.ok) {
        updateCartCount(res.data.resumen.unidades);
        toast('Agregado al carrito', 'success', { href: '/carrito', text: 'Ver carrito' });
      } else {
        errorBox.textContent = res.error.message;
        toast(res.error.message, 'error');
      }
    });
  }

  // ----------------------------------- Recuperación de contraseña (#token) ---
  const recovery = document.querySelector('[data-recovery-handler]');
  if (recovery) {
    const params = new URLSearchParams(location.hash.replace(/^#/, ''));
    const access = params.get('access_token');
    const refresh = params.get('refresh_token');
    history.replaceState(null, '', location.pathname);   // no dejar tokens en la barra de direcciones
    const showInvalid = () => {
      recovery.querySelector('[data-recovery-loading]')?.classList.add('d-none');
      recovery.querySelector('[data-recovery-invalid]')?.classList.remove('d-none');
    };
    if (access && refresh && params.get('type') === 'recovery') {
      api('POST', '/restablecer/sesion', { access_token: access, refresh_token: refresh })
        .then((res) => (res.ok ? window.location.reload() : showInvalid()));
    } else {
      showInvalid();
    }
  }

  document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
})();
