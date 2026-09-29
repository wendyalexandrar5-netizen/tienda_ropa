<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/** Envoltorio de las páginas de acceso: fondo animado con las fotos del catálogo (diseño original). */
$filasFondo = [[1, 16, 2, 3, 11, 19, 7, 4], [17, 5, 12, 10, 14, 15, 9, 13], [6, 18, 8, 20, 1, 3, 11, 2]];
?>
<section class="auth-pagina">
    <div class="fondo-animado" aria-hidden="true">
        <?php foreach ($filasFondo as $n => $fila): ?>
            <div class="fila fila<?= $n + 1 ?>">
                <?php foreach (array_merge($fila, $fila) as $img): ?>
                    <img src="<?= asset("assets/img/productos/$img.jpg") ?>" alt="" loading="lazy">
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="auth-tarjeta<?= !empty($auth_ancha) ? ' ancha' : '' ?>">
        <div class="text-center">
            <a href="<?= url('index.php') ?>"><img class="logo-auth" src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="<?= e(ajuste('nombre_tienda')) ?>"></a>
        </div>
        <?php require __DIR__ . '/alertas.php'; ?>
