<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.'); ?>
        </main>
        <footer class="admin-pie small text-muted-fc">© <?= date('Y') ?> <?= e(ajuste('nombre_tienda')) ?> · Panel administrativo</footer>
    </div>
</div>
<script src="<?= asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<?php if (!empty($usar_graficos)): ?><script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script><?php endif; ?>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<script src="<?= asset('assets/js/admin.js') ?>"></script>
</body>
</html>
