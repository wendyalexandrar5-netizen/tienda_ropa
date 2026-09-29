<?php
// Fondo de fotografías en movimiento: evolución del fondo animado de
// login.html / ingresar.html / registrar.html del proyecto original.
$filas = [[1, 16, 2, 3, 11, 19], [17, 3, 1, 10, 12, 15], [7, 4, 6, 14, 9, 20], [13, 18, 5, 8, 2, 10]];
?>
<div class="auth-visual" aria-hidden="true">
  <div class="hero-gallery">
    <?php foreach ($filas as $i => $fila): ?>
      <div class="gallery-row <?= $i % 2 ? 'reverse' : '' ?>">
        <?php foreach (array_merge($fila, $fila) as $n): ?><img src="/assets/img/productos/<?= (int) $n ?>.jpg" alt=""><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="auth-quote d-flex gap-3 align-items-center">
    <img src="/assets/img/brand/firecat-mark.png" alt="">
    <div><strong class="d-block">¡Bienvenido a FIRE CAT!</strong><span class="small text-muted-fc"><?= e($storeConfig['eslogan'] ?? '') ?></span></div>
  </div>
</div>
