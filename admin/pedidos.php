<?php
require __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$estado = get_texto('estado', 20);
$q = get_texto('q', 80);
$desde = get_texto('desde', 10);
$hasta = get_texto('hasta', 10);
$esFecha = static fn(string $f) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;

$where = ['1 = 1'];
$params = [];
if (isset(ESTADOS_PEDIDO[$estado])) { $where[] = 'p.estado = ?'; $params[] = $estado; }
if ($q !== '') {
    $like = '%' . escapar_like($q) . '%';
    $where[] = "(p.codigo LIKE ? OR CONCAT(u.nombre, ' ', u.apellido) LIKE ? OR u.email LIKE ? OR p.nombre_destinatario LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}
if ($esFecha($desde)) { $where[] = 'p.fecha_pedido >= ?'; $params[] = $desde . ' 00:00:00'; }
if ($esFecha($hasta)) { $where[] = 'p.fecha_pedido <= ?'; $params[] = $hasta . ' 23:59:59'; }
$sqlWhere = implode(' AND ', $where);

$base = "FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id WHERE $sqlWhere";
$totales = fila("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN p.estado <> 'cancelado' THEN p.total END), 0) AS monto $base", $params);
$pag = paginar((int) $totales['n'], 15, max(1, get_int('pagina', 1)));
$pedidos = filas("SELECT p.id, p.codigo, p.total, p.estado, p.metodo_pago, p.fecha_pedido, p.ciudad_entrega,
                         CONCAT(u.nombre, ' ', u.apellido) AS cliente, u.email,
                         (SELECT SUM(d.cantidad) FROM pedido_detalle d WHERE d.pedido_id = p.id) AS unidades
                  $base ORDER BY p.fecha_pedido DESC, p.id DESC LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}", $params);
$conteo = [];
foreach (filas('SELECT estado, COUNT(*) AS n FROM pedidos GROUP BY estado') as $f) {
    $conteo[$f['estado']] = (int) $f['n'];
}

$titulo = 'Pedidos';
$seccion = 'pedidos';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm <?= $estado === '' ? 'btn-fc' : 'btn-light' ?>" href="<?= url('admin/pedidos.php') ?>">Todos <span class="badge text-bg-secondary ms-1"><?= array_sum($conteo) ?></span></a>
    <?php foreach (ESTADOS_PEDIDO as $clave => [$texto]): ?>
        <a class="btn btn-sm <?= $estado === $clave ? 'btn-fc' : 'btn-light' ?>" href="<?= url('admin/pedidos.php', ['estado' => $clave]) ?>"><?= e($texto) ?> <span class="badge text-bg-secondary ms-1"><?= $conteo[$clave] ?? 0 ?></span></a>
    <?php endforeach; ?>
</div>
<form class="barra-filtros row g-2 align-items-end mb-3" method="get" action="<?= url('admin/pedidos.php') ?>">
    <?php if ($estado): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
    <div class="col-md-5"><label class="form-label small" for="q">Buscar</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Código, cliente o correo"></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="desde">Desde</label><input class="form-control form-control-sm" type="date" id="desde" name="desde" value="<?= e($esFecha($desde) ? $desde : '') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="hasta">Hasta</label><input class="form-control form-control-sm" type="date" id="hasta" name="hasta" value="<?= e($esFecha($hasta) ? $hasta : '') ?>"></div>
    <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-fc flex-fill">Filtrar</button><a class="btn btn-sm btn-light" href="<?= url('admin/pedidos.php') ?>" aria-label="Limpiar"><i class="bi bi-x-lg"></i></a></div>
</form>

<div class="tarjeta-admin">
    <div class="cabecera"><h2><?= (int) $totales['n'] ?> pedido(s)</h2><span class="small text-muted-fc">Monto (sin cancelados): <strong class="text-dark"><?= precio($totales['monto']) ?></strong></span></div>
    <div class="table-responsive">
        <table class="table tabla-admin table-hover">
            <thead><tr><th>Pedido</th><th>Cliente</th><th>Fecha</th><th class="text-center">Uds.</th><th>Pago</th><th>Estado</th><th class="text-end">Total</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pedidos as $p): ?>
                <tr>
                    <td class="fw-bold"><a href="<?= url('admin/pedido_detalle.php', ['id' => $p['id']]) ?>"><?= e($p['codigo']) ?></a></td>
                    <td><?= e($p['cliente']) ?><div class="small text-muted-fc"><?= e($p['email']) ?> · <?= e($p['ciudad_entrega']) ?></div></td>
                    <td class="small text-nowrap"><?= fecha($p['fecha_pedido']) ?></td>
                    <td class="text-center"><?= (int) $p['unidades'] ?></td>
                    <td class="small"><?= e(METODOS_PAGO[$p['metodo_pago']] ?? '') ?></td>
                    <td><?= estado_pedido_badge($p['estado']) ?></td>
                    <td class="text-end fw-semibold"><?= precio($p['total']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-light" href="<?= url('admin/pedido_detalle.php', ['id' => $p['id']]) ?>" title="Gestionar"><i class="bi bi-chevron-right"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pedidos): ?><tr><td colspan="8" class="text-center text-muted-fc py-5">No hay pedidos con esos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require ROOT_PATH . '/includes/partials/paginacion.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
