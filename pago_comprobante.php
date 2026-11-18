<?php
/**
 * PESTAÑA 2 · Comprobante del pedido con pago en línea.
 *
 * Muestra primero una pantalla de espera (5 s, sólo presentación) y luego el comprobante
 * construido con datos del servidor (pago_estado.php). Mientras el pago esté pendiente,
 * la página vuelve a consultar sola; el estado «PAGADO» sólo aparece cuando el backend
 * verificó la aprobación con Wompi (webhook firmado o consulta a su API).
 * Acepta ?token=… (recién creado desde el checkout) o ?pedido=ID (desde «Mis pedidos»).
 */
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$token = get_texto('token', 32);
$pedidoId = get_int('pedido');
if (!preg_match('/^[a-f0-9]{32}$/', $token) && $pedidoId <= 0) {
    redirigir('mis_pedidos.php');
}
$parametros = preg_match('/^[a-f0-9]{32}$/', $token) ? ['token' => $token] : ['pedido' => $pedidoId];
$pedido = isset($parametros['token'])
    ? fila('SELECT * FROM pedidos WHERE token_checkout = ? AND usuario_id = ?', [$token, $usuario['id']])
    : fila('SELECT * FROM pedidos WHERE id = ? AND usuario_id = ?', [$pedidoId, $usuario['id']]);
if (!isset($parametros['token']) && !$pedido) {
    error_404('No encontramos ese pedido en tu cuenta.');
}
session_write_close();
header('Cache-Control: no-store');

$tokenReintento = $pedido['token_checkout'] ?? $token;
$proveedor = ($pedido['metodo_pago'] ?? '') === 'pse' ? 'PSE' : 'Wompi';
$titulo = 'Comprobante de pago';
$sin_contenedor = false;
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <ol class="pasos-checkout no-imprimir" aria-label="Proceso de compra">
        <li class="hecho"><span class="num"><i class="bi bi-check"></i></span>Carrito</li>
        <li class="hecho"><span class="num"><i class="bi bi-check"></i></span>Entrega</li>
        <li class="hecho"><span class="num"><i class="bi bi-check"></i></span>Resumen</li>
        <li class="actual"><span class="num">4</span>Comprobante</li>
    </ol>
    <div class="row justify-content-center">
        <div class="col-lg-9" id="comprobante-pago"
             data-url-estado="<?= e(url('pago_estado.php', $parametros)) ?>"
             data-espera="5" data-intervalo="<?= (int) (config_pagos()['intervalo_consulta'] ?? 6) ?>">

            <p class="visually-hidden" aria-live="polite" id="pago-estado-vivo"></p>

            <!-- Pantalla de espera (los 5 s son sólo presentación: no confirman el pago) -->
            <div id="pago-cargando" class="caja bg-negro text-center p-4 p-lg-5 mb-4" role="status">
                <img src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="<?= e(ajuste('nombre_tienda')) ?>" width="84" height="84" class="mb-3">
                <div id="pago-cargando-spinner"><div class="spinner-border text-warning mb-3" aria-hidden="true"></div></div>
                <h1 class="h4 mb-2">Estamos preparando tu comprobante de compra</h1>
                <p class="mb-1">Estamos procesando tu solicitud de pago. En unos segundos podrás consultar el comprobante de tu pedido.</p>
                <p class="small opacity-75 mb-0"><i class="bi bi-window-stack me-1"></i>Completa el pago en la pestaña de <?= e($proveedor) ?>; no cierres ninguna de las dos pestañas.</p>
                <div id="pago-cargando-error" class="alert alert-warning text-start small mt-3 mb-0" hidden>
                    <i class="bi bi-exclamation-triangle me-1"></i><span id="pago-cargando-error-texto"></span>
                    <div class="mt-2 d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-dark" href="<?= url('mis_pedidos.php') ?>">Mis pedidos</a>
                        <a class="btn btn-sm btn-outline-dark" href="<?= url('carrito.php') ?>">Volver al carrito</a>
                    </div>
                </div>
                <noscript><div class="alert alert-warning small mt-3 mb-0">Activa JavaScript o consulta el estado en <a href="<?= url('mis_pedidos.php') ?>">Mis pedidos</a>.</div></noscript>
            </div>

            <!-- Comprobante (se llena con textContent desde el JSON del servidor) -->
            <div id="pago-comprobante" hidden>
                <div class="solo-imprimir mb-3"><strong><?= e(ajuste('nombre_tienda')) ?></strong> · Comprobante de pedido</div>
                <div class="caja text-center p-4 mb-4">
                    <div class="mb-2"><span id="cp-etiqueta" class="badge rounded-pill fs-6"></span></div>
                    <h1 class="h3 mb-1">Pedido <span id="cp-codigo"></span></h1>
                    <p class="text-muted-fc mb-3"><span id="cp-fecha"></span> · Estado del pedido: <strong id="cp-estado-pedido"></strong></p>
                    <div id="cp-mensaje" class="alert d-flex gap-2 align-items-center mb-0">
                        <i id="cp-mensaje-icono" aria-hidden="true"></i><strong id="cp-mensaje-texto"></strong>
                    </div>
                    <div id="cp-seguimiento" class="small text-muted-fc mt-2 no-imprimir" hidden>
                        <span class="spinner-grow spinner-grow-sm text-warning me-1" aria-hidden="true"></span>
                        Consultando el estado con la pasarela automáticamente (última consulta: <span id="cp-consultado"></span>).
                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="cp-actualizar">Actualizar ahora</button>
                    </div>
                    <div class="d-flex flex-wrap justify-content-center gap-2 mt-3 no-imprimir">
                        <a class="btn btn-outline-fc" id="cp-ir-pasarela" href="#" target="_blank" rel="noopener" hidden><i class="bi bi-box-arrow-up-right me-1"></i>Volver a abrir la pasarela</a>
                        <form method="post" action="<?= url('pago_iniciar.php') ?>" id="cp-reintentar" data-dos-pestanas
                              data-ventana="fc_pago_<?= e($tokenReintento) ?>" data-proveedor="<?= e($proveedor) ?>" hidden>
                            <?= csrf_campo() ?>
                            <input type="hidden" name="token" id="cp-reintentar-token" value="<?= e($tokenReintento) ?>">
                            <input type="hidden" name="modo" value="reintento">
                            <button class="btn btn-fc" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Reintentar el pago</button>
                            <span class="d-block small text-danger mt-1 d-none" data-aviso-ventana role="alert"></span>
                        </form>
                        <button class="btn btn-outline-fc" type="button" data-imprimir><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>
                        <a class="btn btn-outline-fc" id="cp-detalle" href="<?= url('mis_pedidos.php') ?>">Ver detalle del pedido</a>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="caja h-100">
                            <div class="caja-titulo">Cliente</div>
                            <dl class="row small mb-0">
                                <dt class="col-4">Nombre</dt><dd class="col-8" id="cp-cliente-nombre"></dd>
                                <dt class="col-4">Correo</dt><dd class="col-8 text-break" id="cp-cliente-email"></dd>
                                <dt class="col-4">Teléfono</dt><dd class="col-8" id="cp-cliente-telefono"></dd>
                                <dt class="col-4">Entrega</dt><dd class="col-8" id="cp-cliente-direccion"></dd>
                            </dl>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="caja h-100">
                            <div class="caja-titulo">Pago</div>
                            <dl class="row small mb-0">
                                <dt class="col-5">Método</dt><dd class="col-7" id="cp-metodo"></dd>
                                <?php foreach ([
                                    'proveedor' => 'Pasarela', 'ambiente' => 'Ambiente', 'referencia' => 'Referencia',
                                    'transaccion' => 'ID de transacción', 'medio' => 'Medio usado', 'banco' => 'Banco',
                                    'verificado' => 'Verificado', 'detalle' => 'Detalle',
                                ] as $clave => $rotulo): ?>
                                    <div class="col-12 p-0" id="cp-fila-<?= $clave ?>" hidden><div class="row m-0">
                                        <dt class="col-5"><?= e($rotulo) ?></dt><dd class="col-7 text-break" data-valor></dd>
                                    </div></div>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    </div>
                </div>

                <div class="caja mt-4">
                    <div class="caja-titulo">Productos</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Producto</th><th class="text-center">Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th></tr></thead>
                            <tbody id="cp-items"></tbody>
                            <tfoot>
                                <tr><td colspan="3" class="text-end">Subtotal</td><td class="text-end" id="cp-subtotal"></td></tr>
                                <tr><td colspan="3" class="text-end">Envío</td><td class="text-end" id="cp-envio"></td></tr>
                                <tr class="fw-bold"><td colspan="3" class="text-end">Total</td><td class="text-end" id="cp-total"></td></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <p class="small text-muted-fc mt-3 mb-0"><i class="bi bi-shield-check me-1"></i>El estado del pago se confirma únicamente con la respuesta verificada de Wompi (notificación firmada o consulta del servidor). Ambiente de pruebas: no se realizan cobros reales.</p>
            </div>
        </div>
    </div>
</div>
<?php $scriptsExtra = ['assets/js/pago.js']; require __DIR__ . '/includes/footer.php'; ?>
