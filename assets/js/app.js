/**
 * FIRE CAT · JavaScript de la tienda.
 * Todo es "mejora progresiva": el servidor valida SIEMPRE los datos, este
 * código sólo mejora la experiencia (validación inmediata, loaders, etc.).
 */
(function () {
  'use strict';

  // 1. Validación de formularios con estilos de Bootstrap ---------------------
  document.querySelectorAll('form.needs-validation').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      form.querySelectorAll('[data-igual-a]').forEach(function (campo) {
        var otro = document.getElementById(campo.dataset.igualA);
        campo.setCustomValidity(otro && otro.value !== campo.value ? 'No coincide' : '');
      });
      if (!form.checkValidity()) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        var primero = form.querySelector(':invalid');
        if (primero) primero.focus();
      }
      form.classList.add('was-validated');
    });
  });

  // 2. Confirmaciones antes de acciones destructivas -------------------------
  document.querySelectorAll('form[data-confirmar]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.dataset.confirmar)) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
      }
    });
  });

  // 3. Loader y bloqueo de doble envío (p. ej. confirmar pedido) ------------
  document.querySelectorAll('form[data-cargando]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) return;
      var boton = form.querySelector('[type="submit"]');
      if (form.dataset.enviado === '1') { ev.preventDefault(); return; }
      form.dataset.enviado = '1';
      form.classList.add('cargando');
      if (boton) boton.setAttribute('aria-busy', 'true');
    });
  });
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-cargando]').forEach(function (f) {
      f.dataset.enviado = ''; f.classList.remove('cargando');
    });
  });

  // 4. Auto-envío de selects/inputs (ordenar, filtrar, cantidades) -----------
  document.querySelectorAll('[data-autoenviar]').forEach(function (campo) {
    campo.addEventListener('change', function () {
      if (campo.form && (!campo.checkValidity || campo.checkValidity())) campo.form.requestSubmit ? campo.form.requestSubmit() : campo.form.submit();
    });
  });

  // 5. Mostrar/ocultar contraseña ------------------------------------------
  document.querySelectorAll('[data-ver-password]').forEach(function (boton) {
    boton.addEventListener('click', function () {
      var input = document.getElementById(boton.dataset.verPassword);
      if (!input) return;
      var visible = input.type === 'text';
      input.type = visible ? 'password' : 'text';
      boton.innerHTML = visible ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
      boton.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
    });
  });

  // 6. Medidor de fortaleza de contraseña ----------------------------------
  document.querySelectorAll('[data-medidor]').forEach(function (input) {
    var barra = document.querySelector('#' + input.dataset.medidor + ' div');
    if (!barra) return;
    input.addEventListener('input', function () {
      var v = input.value, puntos = 0;
      if (v.length >= 8) puntos++;
      if (v.length >= 12) puntos++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) puntos++;
      if (/\d/.test(v)) puntos++;
      if (/[^A-Za-z0-9]/.test(v)) puntos++;
      var colores = ['#c62828', '#c62828', '#e07b24', '#e8cf00', '#2e7d32', '#2e7d32'];
      barra.style.width = (puntos / 5 * 100) + '%';
      barra.style.background = colores[puntos];
    });
  });

  // 7. Imprimir comprobante ------------------------------------------------
  document.querySelectorAll('[data-imprimir]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  // 8. Checkout: el campo "¿con cuánto pagas?" sólo aplica a contra entrega --
  var campoPago = document.getElementById('campo-pago-con');
  if (campoPago) {
    var actualizar = function () {
      var sel = document.querySelector('[data-metodo-pago]:checked');
      var mostrar = sel && sel.value === 'contra_entrega';
      campoPago.hidden = !mostrar;
      var input = campoPago.querySelector('input');
      if (input) input.disabled = !mostrar;
    };
    document.querySelectorAll('[data-metodo-pago]').forEach(function (r) { r.addEventListener('change', actualizar); });
    actualizar();
  }

  // 9. Ficha de producto: combinación talla/color y stock ------------------
  var form = document.getElementById('form-agregar');
  var datos = document.getElementById('datos-variantes');
  if (form && datos) {
    var variantes = JSON.parse(datos.textContent || '[]');
    var umbral = parseInt(form.dataset.umbral || '5', 10);
    var cantidad = document.getElementById('cantidad');
    var estado = document.getElementById('estado-stock');
    var boton = document.getElementById('btn-agregar');
    var MAX = 20;

    var seleccion = function (nombre) {
      var r = form.querySelector('input[name="' + nombre + '"]:checked');
      return r ? parseInt(r.value, 10) : null;
    };
    var buscar = function (talla, color) {
      return variantes.find(function (v) { return v.talla === talla && v.color === color; });
    };
    var stockDe = function (campo, valor) {
      return variantes.filter(function (v) { return v[campo] === valor; })
        .reduce(function (s, v) { return s + v.stock; }, 0);
    };

    var refrescar = function () {
      var talla = seleccion('talla_id'), color = seleccion('color_id');
      // Deshabilita colores sin stock para la talla elegida (y viceversa).
      form.querySelectorAll('input[name="color_id"]').forEach(function (r) {
        var c = parseInt(r.value, 10);
        var v = talla ? buscar(talla, c) : null;
        r.disabled = talla ? !(v && v.stock > 0) : stockDe('color', c) <= 0;
      });
      form.querySelectorAll('input[name="talla_id"]').forEach(function (r) {
        var t = parseInt(r.value, 10);
        r.disabled = stockDe('talla', t) <= 0;
      });

      var v = (talla && color) ? buscar(talla, color) : null;
      if (!v || v.stock <= 0) {
        estado.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' +
          (talla && color ? 'Combinación agotada. Elige otro color o talla.' : 'Selecciona talla y color.') + '</span>';
        boton.disabled = true;
        return;
      }
      var max = Math.min(MAX, v.stock);
      cantidad.max = max;
      if (parseInt(cantidad.value, 10) > max) cantidad.value = max;
      estado.innerHTML = v.stock <= umbral
        ? '<span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>¡Sólo quedan ' + v.stock + ' unidades!</span>'
        : '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + v.stock + ' disponibles</span>';
      boton.disabled = false;
    };

    form.addEventListener('change', function (ev) {
      if (ev.target.name === 'talla_id') {
        // Si el color elegido no existe en la nueva talla, selecciona el primero disponible.
        var t = seleccion('talla_id'), c = seleccion('color_id');
        var actual = c ? buscar(t, c) : null;
        if (!actual || actual.stock <= 0) {
          var alterna = variantes.find(function (v) { return v.talla === t && v.stock > 0; });
          if (alterna) {
            var radio = form.querySelector('input[name="color_id"][value="' + alterna.color + '"]');
            if (radio) { radio.disabled = false; radio.checked = true; }
          }
        }
      }
      refrescar();
    });

    form.querySelectorAll('[data-cantidad]').forEach(function (b) {
      b.addEventListener('click', function () {
        var n = (parseInt(cantidad.value, 10) || 1) + parseInt(b.dataset.cantidad, 10);
        cantidad.value = Math.max(1, Math.min(parseInt(cantidad.max, 10) || MAX, n));
      });
    });

    form.addEventListener('submit', function (ev) {
      var n = parseInt(cantidad.value, 10);
      if (!seleccion('talla_id') || !seleccion('color_id') || !(n >= 1) || n > parseInt(cantidad.max, 10)) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        estado.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Revisa la talla, el color y la cantidad.</span>';
      }
    }, true);

    refrescar();
  }
})();
