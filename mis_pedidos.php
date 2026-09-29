<?php
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$filtroEstado = get_texto('estado', 20);
$params = [$usuario['id']];
$where = 'p.usuario_id = ?';
if (isset(ESTADOS_PEDIDO[$filtroEstado])) {
    $where .= ' AND p.estado = ?';
    $params[] = $filtroEstado;
}
$total = (int) valor("SELECT COUNT(*) FROM pedidos p WHERE $where", $params);
$pag = paginar($total, 10, max(1, get_int('pagina', 1)));
$pedidos = filas("SELECT p.id, p.codigo, p.total, p.estado, p.fecha_pedido, p.metodo_pago,
                         (SELECT SUM(d.cantidad) FROM pedido_detalle d WHERE d.pedido_id = p.id) AS unidades,
                         (SELECT pr.imagen FROM pedido_detalle d JOIN productos pr ON pr.id = d.producto_id WHERE d.pedido_id = p.id ORDER BY d.id LIMIT 1) AS imagen
                  FROM pedidos p WHERE $where ORDER BY p.fecha_pedido DESC, p.id DESC
                  LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}", $params);

$titulo = 'Mis pedidos';
$seccion = 'pedidos';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= url('index.php') ?>">Inicio</a></li><li class="breadcrumb-item active">Mis pedidos</li></ol></nav>
    <div class="row g-4">
        <aside class="col-lg-3"><?php require __DIR__ . '/includes/partials/menu_cuenta.php'; ?></aside>
        <section class="col-lg-9">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <h1 class="h2 mb-0">Mis pedidos</h1>
                <form method="get" action="<?= url('mis_pedidos.php') ?>">
                    <select class="form-select form-select-sm" name="estado" data-autoenviar aria-label="Filtrar por estado">
                        <option value="">Todos los estados</option>
                        <?php foreach (ESTADOS_PEDIDO as $clave => [$texto]): ?>
                            <option value="<?= e($clave) ?>" <?= $filtroEstado === $clave ? 'selected' : '' ?>><?= e($texto) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php if (!$pedidos): ?>
                <div class="estado-vacio">
                    <div class="icono"><i class="bi bi-receipt"></i></div>
                    <h2 class="h4"><?= $filtroEstado ? 'No hay pedidos con ese estado' : 'Aún no tienes pedidos' ?></h2>
                    <p class="text-muted-fc">Cuando compres, podrás seguir aquí el estado de tus pedidos.</p>
                    <a class="btn btn-fc" href="<?= url('productos.php') ?>">Explorar la tienda</a>
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($pedidos as $p): ?>
                        <a class="caja d-flex gap-3 align-items-center text-decoration-none text-reset" href="<?= url('pedido.php', ['id' => $p['id']]) ?>">
                            <img src="<?= imagen_url($p['imagen']) ?>" alt="" width="64" height="80" class="rounded-3 object-fit-cover d-none d-sm-block">
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                                    <strong><?= e($p['codigo']) ?></strong> <?= estado_pedido_badge($p['estado']) ?>
                                </div>
                                <div class="small text-muted-fc"><?= fecha($p['fecha_pedido']) ?> · <?= (int) $p['unidades'] ?> unidad(es) · <?= e(METODOS_PAGO[$p['metodo_pago']] ?? '') ?></div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold"><?= precio($p['total']) ?></div>
                                <span class="small">Ver detalle <i class="bi bi-chevron-right"></i></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php require __DIR__ . '/includes/partials/paginacion.php'; ?>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
