<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

if (es_post() && post_texto('accion', 20) === 'estado') {
    $u = fila('SELECT id, nombre, estado, rol FROM usuarios WHERE id = ?', [post_int('usuario_id')]);
    if (!$u) {
        flash('danger', 'El usuario no existe.');
    } elseif ((int) $u['id'] === (int) $admin['id']) {
        flash('warning', 'No puedes desactivar tu propia cuenta.');
    } else {
        $nuevo = $u['estado'] === 'activo' ? 'inactivo' : 'activo';
        consulta('UPDATE usuarios SET estado = ? WHERE id = ?', [$nuevo, $u['id']]);
        flash('success', "La cuenta de {$u['nombre']} ahora está $nuevo." . ($nuevo === 'inactivo' ? ' Su sesión se cerrará en su próxima acción.' : ''));
    }
    redirigir('admin/usuarios.php', array_intersect_key($_GET, array_flip(['q', 'rol', 'estado', 'pagina'])));
}

$q = get_texto('q', 80);
$rol = get_texto('rol', 20) ?: ROL_CLIENTE;
$estado = get_texto('estado', 10);
$where = ['1 = 1'];
$params = [];
if (in_array($rol, [ROL_ADMIN, ROL_CLIENTE], true)) { $where[] = 'u.rol = ?'; $params[] = $rol; }
if (in_array($estado, ['activo', 'inactivo'], true)) { $where[] = 'u.estado = ?'; $params[] = $estado; }
if ($q !== '') {
    $like = '%' . escapar_like($q) . '%';
    $where[] = "(CONCAT(u.nombre, ' ', u.apellido) LIKE ? OR u.email LIKE ? OR u.telefono LIKE ?)";
    array_push($params, $like, $like, $like);
}
$sqlWhere = implode(' AND ', $where);
$pag = paginar((int) valor("SELECT COUNT(*) FROM usuarios u WHERE $sqlWhere", $params), 15, max(1, get_int('pagina', 1)));
$usuarios = filas("SELECT u.id, u.nombre, u.apellido, u.email, u.telefono, u.ciudad, u.rol, u.estado, u.fecha_registro, u.ultimo_acceso,
                          COUNT(p.id) AS pedidos, COALESCE(SUM(CASE WHEN p.estado <> 'cancelado' THEN p.total END), 0) AS gastado
                   FROM usuarios u LEFT JOIN pedidos p ON p.usuario_id = u.id
                   WHERE $sqlWhere GROUP BY u.id ORDER BY u.fecha_registro DESC LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}", $params);

$titulo = 'Clientes y usuarios';
$seccion = 'usuarios';
$acciones_cabecera = '<a class="btn btn-fc" href="' . url('admin/usuario_editar.php') . '"><i class="bi bi-person-plus me-1"></i>Nuevo usuario</a>';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex gap-2 mb-3">
    <a class="btn btn-sm <?= $rol === ROL_CLIENTE ? 'btn-fc' : 'btn-light' ?>" href="<?= url('admin/usuarios.php', ['rol' => ROL_CLIENTE]) ?>">Clientes</a>
    <a class="btn btn-sm <?= $rol === ROL_ADMIN ? 'btn-fc' : 'btn-light' ?>" href="<?= url('admin/usuarios.php', ['rol' => ROL_ADMIN]) ?>">Administradores</a>
</div>
<form class="barra-filtros row g-2 align-items-end mb-3" method="get" action="<?= url('admin/usuarios.php') ?>">
    <input type="hidden" name="rol" value="<?= e($rol) ?>">
    <div class="col-md-6"><label class="form-label small" for="q">Buscar</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre, correo o teléfono"></div>
    <div class="col-md-3"><label class="form-label small" for="estado">Estado</label>
        <select class="form-select form-select-sm" id="estado" name="estado"><option value="">Todos</option><option value="activo" <?= $estado === 'activo' ? 'selected' : '' ?>>Activos</option><option value="inactivo" <?= $estado === 'inactivo' ? 'selected' : '' ?>>Inactivos</option></select></div>
    <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-fc flex-fill">Filtrar</button><a class="btn btn-sm btn-light" href="<?= url('admin/usuarios.php', ['rol' => $rol]) ?>" aria-label="Limpiar"><i class="bi bi-x-lg"></i></a></div>
</form>
<div class="tarjeta-admin">
    <div class="cabecera"><h2><?= $pag['total'] ?> <?= $rol === ROL_ADMIN ? 'administrador(es)' : 'cliente(s)' ?></h2></div>
    <div class="table-responsive">
        <table class="table tabla-admin table-hover">
            <thead><tr><th>Usuario</th><th>Contacto</th><th class="text-center">Pedidos</th><th class="text-end">Comprado</th><th>Registro</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr class="<?= $u['estado'] !== 'activo' ? 'opacity-50' : '' ?>">
                    <td><div class="d-flex align-items-center gap-2"><span class="avatar"><?= e(iniciales($u['nombre'], $u['apellido'])) ?></span>
                        <div><a class="fw-bold text-decoration-none" href="<?= url('admin/usuario_editar.php', ['id' => $u['id']]) ?>"><?= e($u['nombre'] . ' ' . $u['apellido']) ?></a>
                            <div class="small text-muted-fc"><?= e($u['email']) ?></div></div></div></td>
                    <td class="small"><?= e($u['telefono']) ?><div class="text-muted-fc"><?= e($u['ciudad']) ?></div></td>
                    <td class="text-center"><?= (int) $u['pedidos'] ?></td>
                    <td class="text-end"><?= precio($u['gastado']) ?></td>
                    <td class="small text-nowrap"><?= fecha($u['fecha_registro'], false) ?><div class="text-muted-fc">Último acceso: <?= fecha($u['ultimo_acceso']) ?></div></td>
                    <td><?= $u['estado'] === 'activo' ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Inactivo</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="<?= url('admin/usuario_editar.php', ['id' => $u['id']]) ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                        <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
                            <form class="d-inline" method="post" action="<?= e(url_actual()) ?>" data-confirmar="¿<?= $u['estado'] === 'activo' ? 'Desactivar' : 'Activar' ?> la cuenta de <?= e($u['nombre']) ?>?">
                                <?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="usuario_id" value="<?= (int) $u['id'] ?>">
                                <button class="btn btn-sm btn-light" title="<?= $u['estado'] === 'activo' ? 'Desactivar' : 'Activar' ?>"><i class="bi <?= $u['estado'] === 'activo' ? 'bi-person-slash' : 'bi-person-check' ?>"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$usuarios): ?><tr><td colspan="7" class="text-center text-muted-fc py-5">No hay usuarios con esos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require ROOT_PATH . '/includes/partials/paginacion.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
