<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$campos = [
    'nombre_tienda'      => ['Nombre de la tienda', 'texto', 60],
    'eslogan'            => ['Eslogan', 'texto', 120],
    'email_contacto'     => ['Correo de contacto', 'email', 120],
    'telefono_contacto'  => ['Teléfono / WhatsApp', 'texto', 30],
    'costo_envio'        => ['Costo de envío (COP)', 'numero', 10],
    'envio_gratis_desde' => ['Envío gratis desde (COP, 0 = nunca)', 'numero', 10],
    'umbral_stock_bajo'  => ['Umbral de stock bajo (unidades)', 'numero', 5],
];
$errores = [];

if (es_post()) {
    $accion = post_texto('accion', 20);
    try {
        if ($accion === 'ajustes') {
            $v = new Validador();
            $valores = [];
            foreach ($campos as $clave => [$etiqueta, $tipo, $max]) {
                $valores[$clave] = post_texto($clave, $max);
                $v->requerido($clave, $valores[$clave], $etiqueta);
                if ($tipo === 'email') {
                    $v->email($clave, $valores[$clave]);
                } elseif ($tipo === 'numero') {
                    $v->numero($clave, $valores[$clave], 0, 10000000, $etiqueta);
                    $valores[$clave] = (string) (int) $valores[$clave];
                }
            }
            if ($v->ok()) {
                transaccion(static function () use ($valores) {
                    foreach ($valores as $clave => $valor) {
                        consulta('UPDATE configuracion SET valor = ? WHERE clave = ?', [$valor, $clave]);
                    }
                });
                flash('success', 'Configuración guardada.');
                redirigir('admin/configuracion.php');
            }
            $errores = $v->errores();
            flash('danger', 'Revisa los campos marcados.');
        } elseif ($accion === 'talla') {
            $nombre = post_texto('nombre', 10);
            if ($nombre === '' || valor('SELECT id FROM tallas WHERE nombre = ?', [$nombre])) {
                throw new DomainException('Escribe una talla nueva (máx. 10 caracteres) que no exista.');
            }
            consulta('INSERT INTO tallas (nombre, orden) VALUES (?, ?)', [$nombre, max(0, min(99, post_int('orden', 50)))]);
            flash('success', "Talla «$nombre» agregada.");
            redirigir('admin/configuracion.php');
        } elseif ($accion === 'color') {
            $nombre = post_texto('nombre', 30);
            $hex = strtoupper(post_texto('codigo_hex', 7));
            if ($nombre === '' || !preg_match('/^#[0-9A-F]{6}$/', $hex) || valor('SELECT id FROM colores WHERE nombre = ?', [$nombre])) {
                throw new DomainException('Escribe un color nuevo y un código hexadecimal válido (#RRGGBB).');
            }
            consulta('INSERT INTO colores (nombre, codigo_hex) VALUES (?, ?)', [$nombre, $hex]);
            flash('success', "Color «$nombre» agregado.");
            redirigir('admin/configuracion.php');
        } elseif ($accion === 'eliminar_talla' || $accion === 'eliminar_color') {
            $tabla = $accion === 'eliminar_talla' ? 'tallas' : 'colores';
            $columna = $accion === 'eliminar_talla' ? 'talla_id' : 'color_id';
            $id = post_int('id');
            if ((int) valor("SELECT COUNT(*) FROM variantes_producto WHERE $columna = ?", [$id]) > 0) {
                throw new DomainException('No se puede eliminar: hay variantes de productos que la usan.');
            }
            consulta("DELETE FROM $tabla WHERE id = ?", [$id]);
            flash('success', 'Elemento eliminado.');
            redirigir('admin/configuracion.php');
        }
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
        redirigir('admin/configuracion.php');
    }
}

$tallas = filas('SELECT t.*, (SELECT COUNT(*) FROM variantes_producto v WHERE v.talla_id = t.id) AS usos FROM tallas t ORDER BY orden, nombre');
$colores = filas('SELECT c.*, (SELECT COUNT(*) FROM variantes_producto v WHERE v.color_id = c.id) AS usos FROM colores c ORDER BY nombre');

$titulo = 'Configuración';
$seccion = 'configuracion';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-6">
        <form method="post" action="<?= url('admin/configuracion.php') ?>" class="tarjeta-admin needs-validation" novalidate>
            <?= csrf_campo() ?><input type="hidden" name="accion" value="ajustes">
            <div class="cabecera"><h2>Datos de la tienda y envíos</h2></div>
            <div class="cuerpo row g-3">
                <?php foreach ($campos as $clave => [$etiqueta, $tipo, $max]): ?>
                    <div class="<?= $tipo === 'numero' ? 'col-md-4' : 'col-md-6' ?>">
                        <label class="form-label small" for="<?= e($clave) ?>"><?= e($etiqueta) ?></label>
                        <input class="form-control<?= clase_invalida($errores, $clave) ?>" id="<?= e($clave) ?>" name="<?= e($clave) ?>" required maxlength="<?= $max ?>"
                               type="<?= $tipo === 'numero' ? 'number' : ($tipo === 'email' ? 'email' : 'text') ?>" <?= $tipo === 'numero' ? 'min="0"' : '' ?>
                               value="<?= e($_POST[$clave] ?? ajuste($clave)) ?>">
                        <?= error_campo($errores, $clave) ?>
                    </div>
                <?php endforeach; ?>
                <div class="col-12 text-end"><button class="btn btn-fc"><i class="bi bi-check2 me-1"></i>Guardar configuración</button></div>
            </div>
        </form>
        <div class="tarjeta-admin mt-3">
            <div class="cabecera"><h2>Tu cuenta y sistema</h2></div>
            <div class="cuerpo small">
                <p><a class="btn btn-sm btn-outline-fc" href="<?= url('cambiar_password.php') ?>"><i class="bi bi-key me-1"></i>Cambiar mi contraseña</a></p>
                <ul class="list-unstyled mb-0 text-muted-fc">
                    <li>Entorno: <strong><?= e(config('app_env')) ?></strong> <?= es_desarrollo() ? '(los errores muestran detalle; cambiar a "production" en config/config.local.php al publicar)' : '' ?></li>
                    <li>PHP <?= e(PHP_VERSION) ?> · Base de datos <?= e((string) valor('SELECT VERSION()')) ?></li>
                    <li>Sesión: cookie HttpOnly, SameSite=Lax<?= es_https() ? ', Secure' : ' (Secure se activa con HTTPS)' ?> · expira tras <?= (int) config('session_idle_minutes') ?> min de inactividad</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="tarjeta-admin">
            <div class="cabecera"><h2>Tallas</h2></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($tallas as $t): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-3">
                        <span><strong><?= e($t['nombre']) ?></strong> <small class="text-muted-fc">· <?= (int) $t['usos'] ?> variantes</small></span>
                        <?php if ((int) $t['usos'] === 0): ?>
                            <form method="post" action="<?= url('admin/configuracion.php') ?>" data-confirmar="¿Eliminar la talla <?= e($t['nombre']) ?>?"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar_talla"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0" aria-label="Eliminar"><i class="bi bi-trash"></i></button></form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?= url('admin/configuracion.php') ?>" class="cuerpo d-flex gap-2">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="talla">
                <input class="form-control form-control-sm" name="nombre" maxlength="10" placeholder="Nueva talla" required aria-label="Nueva talla">
                <input class="form-control form-control-sm" type="number" name="orden" min="0" max="99" value="50" style="width:70px" aria-label="Orden" title="Orden">
                <button class="btn btn-sm btn-fc" aria-label="Agregar talla"><i class="bi bi-plus-lg"></i></button>
            </form>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="tarjeta-admin">
            <div class="cabecera"><h2>Colores</h2></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($colores as $c): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-3">
                        <span><i class="muestra align-middle" style="background: <?= e($c['codigo_hex']) ?>"></i> <strong><?= e($c['nombre']) ?></strong> <small class="text-muted-fc">· <?= (int) $c['usos'] ?></small></span>
                        <?php if ((int) $c['usos'] === 0): ?>
                            <form method="post" action="<?= url('admin/configuracion.php') ?>" data-confirmar="¿Eliminar el color <?= e($c['nombre']) ?>?"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar_color"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0" aria-label="Eliminar"><i class="bi bi-trash"></i></button></form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?= url('admin/configuracion.php') ?>" class="cuerpo d-flex gap-2">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="color">
                <input class="form-control form-control-sm" name="nombre" maxlength="30" placeholder="Nuevo color" required aria-label="Nuevo color">
                <input class="form-control form-control-sm form-control-color" type="color" name="codigo_hex" value="#888888" aria-label="Código de color">
                <button class="btn btn-sm btn-fc" aria-label="Agregar color"><i class="bi bi-plus-lg"></i></button>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
