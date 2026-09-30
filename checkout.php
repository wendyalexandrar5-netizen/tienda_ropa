<?php
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$uid = (int) $usuario['id'];

if (es_post() && post_texto('accion', 20) === 'confirmar') {
    $datos = $_SESSION['checkout'] ?? null;
    if (!$datos) {
        flash('warning', 'Completa primero la información de entrega.');
        redirigir('checkout.php');
    }
    try {
        $pedido = crear_pedido($uid, $datos);
        unset($_SESSION['checkout']);
        if (!empty($datos['guardar_direccion'])) {
            consulta('UPDATE usuarios SET telefono = ?, direccion = ?, ciudad = ? WHERE id = ?',
                [$datos['telefono'], $datos['direccion'], $datos['ciudad'], $uid]);
        }
        redirigir('pedido_confirmado.php', ['codigo' => $pedido['codigo']]);
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
        redirigir('carrito.php');
    }
}

foreach (carrito_revisar($uid) as $aviso) {
    flash('warning', $aviso);
}
$items   = array_values(array_filter(carrito_items($uid), static fn($i) => $i['disponible']));
$resumen = carrito_resumen($items);
if (!$items) {
    flash('info', 'Tu carrito está vacío. Agrega productos para continuar.');
    redirigir('carrito.php');
}

$errores = [];
$datos = $_SESSION['checkout'] ?? [
    'nombre'      => trim($usuario['nombre'] . ' ' . $usuario['apellido']),
    'telefono'    => (string) $usuario['telefono'],
    'direccion'   => (string) $usuario['direccion'],
    'ciudad'      => (string) $usuario['ciudad'],
    'notas'       => '',
    'metodo_pago' => 'contra_entrega',
    'pago_con'    => null,
    'guardar_direccion' => false,
];

if (es_post() && post_texto('accion', 20) === 'revisar') {
    $datos = [
        'nombre'      => post_texto('nombre', 120),
        'telefono'    => post_texto('telefono', 20),
        'direccion'   => post_texto('direccion', 160),
        'ciudad'      => post_texto('ciudad', 80),
        'notas'       => post_texto('notas', 255),
        'metodo_pago' => post_texto('metodo_pago', 20),
        'pago_con'    => post_texto('pago_con', 12),
        'guardar_direccion' => isset($_POST['guardar_direccion']),
    ];
    $v = (new Validador())
        ->requerido('nombre', $datos['nombre'], 'El nombre de quien recibe')->longitud('nombre', $datos['nombre'], 3, 120, 'El nombre')
        ->nombrePersona('nombre', $datos['nombre'], 'El nombre')
        ->requerido('telefono', $datos['telefono'], 'El teléfono')->telefono('telefono', $datos['telefono'])
        ->requerido('direccion', $datos['direccion'], 'La dirección')->longitud('direccion', $datos['direccion'], 5, 160, 'La dirección')
        ->requerido('ciudad', $datos['ciudad'], 'La ciudad')->longitud('ciudad', $datos['ciudad'], 2, 80, 'La ciudad')
        ->enLista('metodo_pago', $datos['metodo_pago'], array_keys(METODOS_PAGO), 'El método de pago');
    if ($datos['metodo_pago'] === 'contra_entrega' && $datos['pago_con'] !== '') {
        $v->numero('pago_con', $datos['pago_con'], 0, 100000000, 'El valor en efectivo');
        if (is_numeric($datos['pago_con']) && (float) $datos['pago_con'] < $resumen['total']) {
            $v->agregar('pago_con', 'El valor en efectivo debe cubrir el total (' . precio($resumen['total']) . ').');
        }
    }
    $datos['pago_con'] = ($datos['metodo_pago'] === 'contra_entrega' && is_numeric($datos['pago_con'])) ? (float) $datos['pago_con'] : null;

    if ($v->ok()) {
        $_SESSION['checkout'] = $datos;
        redirigir('checkout.php', ['paso' => 'resumen']);
    }
    $errores = $v->errores();
    flash('danger', 'Revisa los datos de entrega marcados en rojo.');
}

$paso = (get_texto('paso', 10) === 'resumen' && isset($_SESSION['checkout']) && !$errores) ? 'resumen' : 'entrega';

$titulo = 'Finalizar compra';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <ol class="pasos-checkout" aria-label="Proceso de compra">
        <li class="hecho"><span class="num"><i class="bi bi-check"></i></span><a class="text-reset text-decoration-none" href="<?= url('carrito.php') ?>">Carrito</a></li>
        <li class="<?= $paso === 'entrega' ? 'actual' : 'hecho' ?>"><span class="num"><?= $paso === 'entrega' ? '2' : '<i class="bi bi-check"></i>' ?></span>Entrega</li>
        <li class="<?= $paso === 'resumen' ? 'actual' : '' ?>"><span class="num">3</span>Resumen</li>
        <li><span class="num">4</span>Confirmación</li>
    </ol>

    <div class="row g-4">
        <div class="col-lg-8">
        <?php if ($paso === 'entrega'): ?>
            <h1 class="h2 mb-4">Información de entrega</h1>
            <form method="post" action="<?= url('checkout.php') ?>" class="caja needs-validation" novalidate>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="revisar">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label" for="nombre">Nombre de quien recibe *</label>
                        <input class="form-control<?= clase_invalida($errores, 'nombre') ?>" id="nombre" name="nombre" required minlength="3" maxlength="120" value="<?= e($datos['nombre']) ?>" autocomplete="name">
                        <?= error_campo($errores, 'nombre') ?>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="telefono">Teléfono *</label>
                        <input class="form-control<?= clase_invalida($errores, 'telefono') ?>" id="telefono" name="telefono" type="tel" required pattern="\+?[0-9 ]{7,20}" maxlength="20" value="<?= e($datos['telefono']) ?>" autocomplete="tel">
                        <?= error_campo($errores, 'telefono') ?>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="direccion">Dirección *</label>
                        <input class="form-control<?= clase_invalida($errores, 'direccion') ?>" id="direccion" name="direccion" required minlength="5" maxlength="160" value="<?= e($datos['direccion']) ?>" placeholder="Calle, número, apartamento" autocomplete="street-address">
                        <?= error_campo($errores, 'direccion') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="ciudad">Ciudad *</label>
                        <input class="form-control<?= clase_invalida($errores, 'ciudad') ?>" id="ciudad" name="ciudad" required minlength="2" maxlength="80" value="<?= e($datos['ciudad']) ?>" autocomplete="address-level2">
                        <?= error_campo($errores, 'ciudad') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notas">Indicaciones para la entrega <span class="text-muted-fc fw-normal">(opcional)</span></label>
                        <textarea class="form-control" id="notas" name="notas" rows="2" maxlength="255" placeholder="Ej.: dejar en portería, llamar antes de llegar"><?= e($datos['notas']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="guardar_direccion" name="guardar_direccion" value="1" <?= !empty($datos['guardar_direccion']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="guardar_direccion">Guardar teléfono y dirección en mi perfil</label>
                        </div>
                    </div>
                </div>

                <h2 class="h5 fw-bold mt-4 mb-3">Método de pago</h2>
                <div class="d-grid gap-2">
                    <?php foreach (METODOS_PAGO as $clave => $texto): ?>
                        <label class="caja p-3 d-flex gap-3 align-items-start" for="mp-<?= e($clave) ?>">
                            <input class="form-check-input mt-1" type="radio" name="metodo_pago" id="mp-<?= e($clave) ?>" value="<?= e($clave) ?>" <?= $datos['metodo_pago'] === $clave ? 'checked' : '' ?> data-metodo-pago>
                            <span>
                                <strong class="d-block"><?= e($texto) ?></strong>
                                <small class="text-muted-fc"><?= $clave === 'contra_entrega' ? 'Pagas en efectivo cuando recibes el pedido.' : 'Genera un pedido de demostración sin cobro real.' ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?= error_campo($errores, 'metodo_pago') ?>
                <div class="mt-3" id="campo-pago-con">
                    <label class="form-label" for="pago_con">¿Con cuánto pagarás? <span class="text-muted-fc fw-normal">(opcional, para llevar tu cambio)</span></label>
                    <div class="input-group" style="max-width: 280px">
                        <span class="input-group-text">$</span>
                        <input class="form-control<?= clase_invalida($errores, 'pago_con') ?>" id="pago_con" name="pago_con" type="number" min="<?= (int) $resumen['total'] ?>" step="100" value="<?= e($datos['pago_con'] ?? '') ?>">
                    </div>
                    <?= error_campo($errores, 'pago_con') ?>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <a class="btn btn-outline-fc" href="<?= url('carrito.php') ?>"><i class="bi bi-arrow-left me-1"></i>Volver al carrito</a>
                    <button class="btn btn-fc" type="submit">Revisar pedido <i class="bi bi-arrow-right ms-1"></i></button>
                </div>
            </form>
        <?php else: $d = $_SESSION['checkout']; ?>
            <h1 class="h2 mb-4">Revisa y confirma tu pedido</h1>
            <div class="caja mb-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="caja-titulo mb-2"><i class="bi bi-geo-alt me-1"></i>Entrega</div>
                        <div class="fw-semibold"><?= e($d['nombre']) ?> · <?= e($d['telefono']) ?></div>
                        <div class="text-muted-fc"><?= e($d['direccion']) ?>, <?= e($d['ciudad']) ?></div>
                        <?php if ($d['notas'] !== ''): ?><div class="small text-muted-fc mt-1">Nota: <?= e($d['notas']) ?></div><?php endif; ?>
                    </div>
                    <a class="btn btn-sm btn-outline-fc" href="<?= url('checkout.php') ?>">Editar</a>
                </div>
                <hr>
                <div class="caja-titulo mb-2"><i class="bi bi-wallet2 me-1"></i>Pago</div>
                <div><?= e(METODOS_PAGO[$d['metodo_pago']]) ?></div>
                <?php if ($d['pago_con'] !== null): ?>
                    <div class="small text-muted-fc">Pagarás con <?= precio($d['pago_con']) ?> · Cambio: <strong><?= precio($d['pago_con'] - $resumen['total']) ?></strong></div>
                <?php endif; ?>
            </div>
            <div class="caja">
                <div class="caja-titulo">Productos</div>
                <?php foreach ($items as $i): ?>
                    <div class="item-carrito d-flex gap-3 align-items-center">
                        <img src="<?= imagen_url($i['imagen']) ?>" alt="">
                        <div class="flex-grow-1">
                            <div class="fw-bold"><?= e($i['nombre']) ?></div>
                            <div class="small text-muted-fc">Talla <?= e($i['talla']) ?> · <?= e($i['color']) ?> · <?= (int) $i['cantidad'] ?> × <?= precio($i['precio']) ?></div>
                        </div>
                        <div class="fw-bold"><?= precio($i['subtotal']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="post" action="<?= url('checkout.php') ?>" class="mt-4" data-cargando>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="confirmar">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <a class="btn btn-outline-fc" href="<?= url('checkout.php') ?>"><i class="bi bi-arrow-left me-1"></i>Modificar entrega</a>
                    <button class="btn btn-fc btn-lg" type="submit">
                        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                        <i class="bi bi-lock me-1"></i>Confirmar pedido por <?= precio($resumen['total']) ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
        </div>
        <div class="col-lg-4">
            <?php $mostrarItems = $paso === 'entrega'; require __DIR__ . '/includes/partials/resumen_compra.php'; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
