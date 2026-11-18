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
    if (es_metodo_en_linea($datos['metodo_pago'])) {
        // Los pagos en línea sólo se inician con el botón «Pagar» (pago_iniciar.php).
        flash('warning', 'Usa el botón «Pagar» para continuar con el pago en línea.');
        redirigir('checkout.php', ['paso' => 'resumen']);
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
    'pse'         => ['tipo_persona' => 0, 'tipo_documento' => 'CC', 'documento' => '', 'banco' => ''],
];
$datos['pse'] = ($datos['pse'] ?? []) + ['tipo_persona' => 0, 'tipo_documento' => 'CC', 'documento' => '', 'banco' => ''];

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
        'pse'         => [
            'tipo_persona'   => post_int('pse_tipo_persona', -1),
            'tipo_documento' => post_texto('pse_tipo_documento', 5),
            'documento'      => preg_replace('/[\s.\-]/', '', post_texto('pse_documento', 25)),
            'banco'          => post_texto('pse_banco', 10),
        ],
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

    // Pagos en línea: el método debe estar configurado; PSE exige además los datos que pide la API de Wompi.
    if (es_metodo_en_linea($datos['metodo_pago']) && ($problema = problema_metodo($datos['metodo_pago'])) !== null) {
        $v->agregar('metodo_pago', $problema);
    } elseif ($datos['metodo_pago'] === 'pse') {
        $pseForm = pse_datos_formulario();
        if ($pseForm['error'] !== null) {
            $v->agregar('metodo_pago', $pseForm['error']);
        } else {
            if (!isset(PSE_TIPOS_PERSONA[$datos['pse']['tipo_persona']])) {
                $v->agregar('pse_tipo_persona', 'Selecciona el tipo de persona.');
            }
            if (!isset(PSE_TIPOS_DOCUMENTO[$datos['pse']['tipo_documento']])) {
                $v->agregar('pse_tipo_documento', 'Selecciona el tipo de documento.');
            }
            if (!preg_match('/^[0-9A-Za-z]{5,20}$/', $datos['pse']['documento'])) {
                $v->agregar('pse_documento', 'Ingresa el número de documento del titular de la cuenta (5 a 20 caracteres, sin puntos).');
            }
            if (!isset($pseForm['bancos'][$datos['pse']['banco']])) {
                $v->agregar('pse_banco', 'Selecciona tu banco.');
            }
            if (!isset($_POST['acepto_wompi'])) {
                $v->agregar('acepto_wompi', 'Debes aceptar los términos y la política de datos de Wompi para pagar con PSE.');
            }
        }
    }
    if (!es_metodo_en_linea($datos['metodo_pago'])) {
        $datos['pse'] = ['tipo_persona' => 0, 'tipo_documento' => 'CC', 'documento' => '', 'banco' => ''];
    }
    // Identificador único de esta compra: une las dos pestañas y evita pedidos duplicados.
    $datos['token'] = bin2hex(random_bytes(16));

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
                <?php
                $descripciones = [
                    'contra_entrega' => 'Pagas en efectivo cuando recibes el pedido.',
                    'pedido_prueba'  => 'Genera un pedido de demostración sin cobro real.',
                    'wompi'          => 'Al pulsar «Pagar» se abre el checkout real de Wompi (ambiente de pruebas) en una pestaña nueva.',
                    'pse'            => 'Al pulsar «Pagar» Wompi te lleva al flujo PSE de tu banco en una pestaña nueva (ambiente de pruebas).',
                ];
                $pseForm = $datos['metodo_pago'] === 'pse' || pse_problema() === null ? pse_datos_formulario() : ['bancos' => [], 'terminos_url' => '', 'datos_url' => '', 'error' => pse_problema()];
                ?>
                <div class="d-grid gap-2">
                    <?php foreach (METODOS_PAGO as $clave => $texto):
                        $problema = $clave === 'pse' ? $pseForm['error'] : problema_metodo($clave); ?>
                        <label class="caja p-3 d-flex gap-3 align-items-start<?= $problema ? ' opacity-75' : '' ?>" for="mp-<?= e($clave) ?>">
                            <input class="form-check-input mt-1" type="radio" name="metodo_pago" id="mp-<?= e($clave) ?>" value="<?= e($clave) ?>" <?= $datos['metodo_pago'] === $clave && !$problema ? 'checked' : '' ?> <?= $problema ? 'disabled' : '' ?> data-metodo-pago>
                            <span>
                                <strong class="d-block"><?= e($texto) ?><?php if (es_metodo_en_linea($clave)): ?> <i class="bi bi-box-arrow-up-right small"></i><?php endif; ?></strong>
                                <small class="text-muted-fc"><?= e($descripciones[$clave]) ?></small>
                                <?php if ($problema): ?><small class="d-block text-danger mt-1"><i class="bi bi-exclamation-circle me-1"></i><?= e($problema) ?></small><?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if (!$pseForm['error'] && $pseForm['bancos']): $pse = $datos['pse']; ?>
                    <fieldset class="caja mt-3" id="campo-pse">
                        <legend class="h6 fw-bold">Datos para PSE <span class="small fw-normal text-muted-fc">(los pide Wompi para iniciar el pago)</span></legend>
                        <div class="row g-2">
                            <div class="col-sm-6"><label class="form-label small" for="pse_tipo_persona">Tipo de persona</label>
                                <select class="form-select<?= clase_invalida($errores, 'pse_tipo_persona') ?>" id="pse_tipo_persona" name="pse_tipo_persona">
                                    <?php foreach (PSE_TIPOS_PERSONA as $k => $t): ?><option value="<?= $k ?>" <?= (int) $pse['tipo_persona'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
                                <?= error_campo($errores, 'pse_tipo_persona') ?></div>
                            <div class="col-sm-6"><label class="form-label small" for="pse_banco">Banco</label>
                                <select class="form-select<?= clase_invalida($errores, 'pse_banco') ?>" id="pse_banco" name="pse_banco"><option value="">Selecciona tu banco…</option>
                                    <?php foreach ($pseForm['bancos'] as $cod => $nombreBanco): ?><option value="<?= e($cod) ?>" <?= (string) $pse['banco'] === (string) $cod ? 'selected' : '' ?>><?= e($nombreBanco) ?></option><?php endforeach; ?></select>
                                <?= error_campo($errores, 'pse_banco') ?></div>
                            <div class="col-sm-5"><label class="form-label small" for="pse_tipo_documento">Tipo de documento</label>
                                <select class="form-select<?= clase_invalida($errores, 'pse_tipo_documento') ?>" id="pse_tipo_documento" name="pse_tipo_documento">
                                    <?php foreach (PSE_TIPOS_DOCUMENTO as $k => $t): ?><option value="<?= e($k) ?>" <?= $pse['tipo_documento'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
                                <?= error_campo($errores, 'pse_tipo_documento') ?></div>
                            <div class="col-sm-7"><label class="form-label small" for="pse_documento">Número de documento del titular</label>
                                <input class="form-control<?= clase_invalida($errores, 'pse_documento') ?>" id="pse_documento" name="pse_documento" maxlength="20" inputmode="numeric" autocomplete="off" value="<?= e($pse['documento']) ?>">
                                <?= error_campo($errores, 'pse_documento') ?></div>
                            <div class="col-12"><div class="form-check">
                                <input class="form-check-input<?= clase_invalida($errores, 'acepto_wompi') ?>" type="checkbox" id="acepto_wompi" name="acepto_wompi" value="1">
                                <label class="form-check-label small" for="acepto_wompi">Acepto los <a href="<?= e($pseForm['terminos_url']) ?>" target="_blank" rel="noopener noreferrer">términos y condiciones</a>
                                    <?php if ($pseForm['datos_url'] !== ''): ?>y la <a href="<?= e($pseForm['datos_url']) ?>" target="_blank" rel="noopener noreferrer">política de tratamiento de datos</a><?php endif; ?> de Wompi.</label>
                                <?= error_campo($errores, 'acepto_wompi') ?></div></div>
                        </div>
                        <p class="small text-muted-fc mb-0 mt-2"><i class="bi bi-shield-lock me-1"></i>Tu documento sólo se envía a Wompi para iniciar el pago; la tienda no lo almacena.</p>
                    </fieldset>
                <?php endif; ?>
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
                <?php if ($d['metodo_pago'] === 'pse' && !empty($d['pse']['banco'])): ?>
                    <div class="small text-muted-fc">Banco: <?= e(pse_datos_formulario()['bancos'][$d['pse']['banco']] ?? $d['pse']['banco']) ?> · <?= e(PSE_TIPOS_PERSONA[$d['pse']['tipo_persona']] ?? '') ?></div>
                <?php endif; ?>
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
            <?php if (es_metodo_en_linea($d['metodo_pago'])): $tokenCompra = (string) $d['token']; ?>
                <div class="alert alert-light border mt-4 small mb-3">
                    <i class="bi bi-window-stack me-1"></i>Al pulsar <strong>Pagar</strong> se abrirá
                    <?= $d['metodo_pago'] === 'pse' ? 'el flujo <strong>PSE</strong> de tu banco (a través de Wompi)' : 'el <strong>checkout de Wompi</strong>' ?>
                    en una <strong>pestaña nueva</strong>, y esta pestaña mostrará tu comprobante. Ambiente de <strong>pruebas</strong>: no se realizan cobros reales.
                </div>
                <form method="post" action="<?= url('pago_iniciar.php') ?>" id="form-pago-en-linea" data-dos-pestanas
                      data-comprobante-url="<?= e(url('pago_comprobante.php', ['token' => $tokenCompra])) ?>"
                      data-ventana="fc_pago_<?= e($tokenCompra) ?>" data-proveedor="<?= $d['metodo_pago'] === 'pse' ? 'PSE' : 'Wompi' ?>">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="token" value="<?= e($tokenCompra) ?>">
                    <input type="hidden" name="modo" value="dos_pestanas">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <a class="btn btn-outline-fc" href="<?= url('checkout.php') ?>"><i class="bi bi-arrow-left me-1"></i>Modificar entrega</a>
                        <button class="btn btn-fc btn-lg" type="submit" id="btn-pagar">
                            <i class="bi bi-lock me-1"></i>Pagar <?= precio($resumen['total']) ?> con <?= $d['metodo_pago'] === 'pse' ? 'PSE' : 'Wompi' ?>
                        </button>
                    </div>
                    <p class="small text-danger mt-2 mb-0 d-none" id="aviso-ventana" data-aviso-ventana role="alert"></p>
                </form>
            <?php else: ?>
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
        <?php endif; ?>
        </div>
        <div class="col-lg-4">
            <?php $mostrarItems = $paso === 'entrega'; require __DIR__ . '/includes/partials/resumen_compra.php'; ?>
        </div>
    </div>
</div>
<?php $scriptsExtra = ['assets/js/pago.js']; require __DIR__ . '/includes/footer.php'; ?>
