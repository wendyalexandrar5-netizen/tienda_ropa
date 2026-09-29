<?php

declare(strict_types=1);

/*
 * =============================================================================
 * Datos de prueba: usuarios, direcciones, carritos y pedidos históricos.
 * -----------------------------------------------------------------------------
 * Uso:   php scripts/seed_demo.php
 *
 * * Los usuarios se crean con la API de administración de Supabase Auth
 *   (requiere SUPABASE_SECRET_KEY). Nunca se insertan contraseñas en tablas.
 * * Las contraseñas son SOLO de demostración (DEMO_PASSWORD en .env o la
 *   predeterminada). No uses estas cuentas en producción.
 * * Los pedidos se generan con las mismas funciones de la BD que usa la
 *   tienda (crear_pedido, admin_registrar_venta_pos, ...), así que respetan
 *   stock, precios y reglas de negocio. Luego se reparten en los últimos
 *   90 días para que el dashboard y los reportes tengan información.
 * =============================================================================
 */

use App\Auth\CurrentUser;
use App\Core\Database;
use App\Core\HttpException;

/** @var App\Core\Container $c */
$c = require __DIR__ . '/../src/bootstrap.php';
app($c);
$db = $c->db();
$config = $c->config;

$password = getenv('DEMO_PASSWORD') ?: 'FireCat-Demo-2026';
$users = [
    ['email' => 'admin@firecat.test', 'nombre' => 'Admin', 'apellido' => 'Fire Cat', 'telefono' => '3000000000', 'rol' => 'admin'],
    ['email' => 'camila@firecat.test', 'nombre' => 'Camila', 'apellido' => 'Rojas', 'telefono' => '3101234567', 'rol' => 'cliente'],
    ['email' => 'andres@firecat.test', 'nombre' => 'Andrés', 'apellido' => 'Pérez', 'telefono' => '3207654321', 'rol' => 'cliente'],
    ['email' => 'valentina@firecat.test', 'nombre' => 'Valentina', 'apellido' => 'Gómez', 'telefono' => '3159876543', 'rol' => 'cliente'],
    ['email' => 'santiago@firecat.test', 'nombre' => 'Santiago', 'apellido' => 'Muñoz', 'telefono' => '3004567890', 'rol' => 'cliente'],
];

// En local (GoTrue sin gateway) se puede derivar una clave service_role
// firmada con el JWT secret si no se definió SUPABASE_SECRET_KEY.
if ($config->supabaseSecretKey === '' && $config->supabaseJwtSecret !== '' && $config->appEnv !== 'production') {
    $b64 = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $h = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = $b64(json_encode(['role' => 'service_role', 'iss' => 'supabase-local', 'iat' => time(), 'exp' => time() + 600]));
    $jwt = "$h.$p." . $b64(hash_hmac('sha256', "$h.$p", $config->supabaseJwtSecret, true));
    putenv("SUPABASE_SECRET_KEY=$jwt");
    $_ENV['SUPABASE_SECRET_KEY'] = $jwt;
    $c = new App\Core\Container(App\Core\Config::fromEnv());
    app($c);
    $db = $c->db();
}

$auth = $c->authClient();
echo "→ Creando usuarios de prueba en Supabase Auth…\n";
$ids = [];
foreach ($users as $u) {
    try {
        $created = $auth->adminCreateUser($u['email'], $password, ['nombre' => $u['nombre'], 'apellido' => $u['apellido'], 'telefono' => $u['telefono']]);
        $ids[$u['email']] = (string) ($created['id'] ?? '');
        echo "  ✔ {$u['email']}\n";
    } catch (HttpException $e) {
        if ($e->errorCode !== 'EMAIL_REGISTRADO' && $e->status !== 422) {
            throw $e;
        }
        echo "  • {$u['email']} ya existía\n";
    }
}

$db->asSystem(function (Database $db) use ($users, &$ids) {
    foreach ($users as $u) {
        $id = $db->fetchValue('select id from public.profiles where email = :e', ['e' => $u['email']]);
        if (!$id) {
            throw new RuntimeException("No se encontró el perfil de {$u['email']} (¿trigger on_auth_user_created instalado?)");
        }
        $ids[$u['email']] = (string) $id;
        $db->execute('update public.profiles set rol = :r where id = :id', ['r' => $u['rol'], 'id' => $id]);
    }
});
echo "  ✔ Rol administrador asignado a admin@firecat.test\n";

$asUser = static function (string $id, string $email) use ($db): void {
    $db->setUser(new CurrentUser($id, $email, ['sub' => $id, 'role' => 'authenticated', 'aud' => 'authenticated', 'email' => $email], '', 'script'));
};

$already = (int) $db->asSystem(fn (Database $db) => $db->fetchValue('select count(*) from public.pedidos'));
if ($already > 0) {
    echo "→ Ya existen pedidos ($already); no se generan pedidos de demostración.\n";
    echo "\nListo. Contraseña de demostración: $password\n";
    exit(0);
}

$cities = [
    ['Bogotá', 'Cundinamarca', 'Calle 72 # 10-34'], ['Medellín', 'Antioquia', 'Carrera 43A # 1-50'],
    ['Cali', 'Valle del Cauca', 'Avenida 6N # 23-45'], ['Barranquilla', 'Atlántico', 'Calle 84 # 51B-20'],
];
mt_srand(2026);

echo "→ Generando direcciones y pedidos de clientes…\n";
$orderIds = [];
foreach (array_slice($users, 1) as $i => $u) {
    $id = $ids[$u['email']];
    $asUser($id, $u['email']);
    [$city, $dep, $dir] = $cities[$i % count($cities)];
    $addr = $c->account()->createAddress([
        'alias' => 'Casa', 'destinatario' => $u['nombre'] . ' ' . $u['apellido'], 'telefono' => $u['telefono'],
        'direccion' => $dir, 'ciudad' => $city, 'departamento' => $dep, 'es_principal' => true,
    ]);

    $pedidos = 3 + $i;
    for ($n = 0; $n < $pedidos; $n++) {
        $variants = $db->asSystem(fn (Database $db) => $db->fetchAll(
            "select v.id from public.variantes_producto v join public.productos p on p.id = v.producto_id
              where v.activa and p.estado = 'activo' and v.stock > 3 order by random() limit :n",
            ['n' => mt_rand(1, 3)],
        ));
        $asUser($id, $u['email']);
        foreach ($variants as $v) {
            $c->cart()->add(['variante_id' => (int) $v['id'], 'cantidad' => mt_rand(1, 2)]);
        }
        $order = $c->orders()->create([
            'direccion_id' => $addr['id'],
            'metodo_pago' => mt_rand(0, 1) ? 'contra_entrega' : 'transferencia',
        ], $id);
        $orderIds[] = $order['id'];
    }
    // Deja algo en el carrito para ver la experiencia de compra.
    $c->cart()->add(['variante_id' => (int) $db->asSystem(fn (Database $db) => $db->fetchValue("select id from public.variantes_producto where stock > 5 order by id limit 1")), 'cantidad' => 1]);
    echo "  ✔ {$u['email']}: $pedidos pedidos\n";
}

echo "→ Avanzando estados de pedidos y registrando ventas en tienda…\n";
$adminId = $ids['admin@firecat.test'];
$asUser($adminId, 'admin@firecat.test');
$flows = [
    ['confirmado', 'preparando', 'enviado', 'entregado'],
    ['confirmado', 'preparando', 'enviado', 'entregado'],
    ['confirmado', 'preparando', 'enviado'],
    ['confirmado', 'preparando'],
    ['cancelado'],
    [],
];
foreach ($orderIds as $k => $orderId) {
    foreach ($flows[$k % count($flows)] as $estado) {
        $c->adminOrders()->changeStatus($orderId, ['estado' => $estado, 'comentario' => 'Datos de prueba']);
    }
}
for ($n = 0; $n < 6; $n++) {
    $variants = $db->asSystem(fn (Database $db) => $db->fetchAll(
        "select v.id from public.variantes_producto v join public.productos p on p.id = v.producto_id
          where v.activa and p.estado = 'activo' and v.stock > 3 order by random() limit :n",
        ['n' => mt_rand(1, 3)],
    ));
    $asUser($adminId, 'admin@firecat.test');
    $c->adminOrders()->registerPosSale([
        'items' => array_map(static fn ($v) => ['variante_id' => (int) $v['id'], 'cantidad' => 1], $variants),
        'pago_recibido' => 800000,
        'metodo_pago' => 'efectivo',
        'cliente_nombre' => 'Cliente mostrador',
    ]);
}

// Distribuye fechas en los últimos 90 días (solo datos de demostración).
$db->setUser(null);
$db->asSystem(function (Database $db) {
    $db->execute(
        "with f as (
           select id, now() - ((row_number() over (order by numero desc) - 1) * interval '3 days 5 hours') - (random() * interval '10 hours') as fecha
             from public.pedidos)
         update public.pedidos p set created_at = f.fecha, updated_at = f.fecha from f where f.id = p.id"
    );
    $db->execute(
        "update public.historial_estados_pedido h
            set created_at = p.created_at + (h.id - (select min(id) from public.historial_estados_pedido x where x.pedido_id = h.pedido_id)) * interval '1 day'
           from public.pedidos p where p.id = h.pedido_id"
    );
    $db->execute("update public.movimientos_inventario m set created_at = p.created_at from public.pedidos p where p.id = m.pedido_id");
    $db->execute(
        "with f as (select id, now() - (row_number() over (order by email) * interval '37 days') as fecha from public.profiles where rol = 'cliente')
         update public.profiles pr set fecha_registro = f.fecha from f where f.id = pr.id"
    );
});

$total = (int) $db->asSystem(fn (Database $db) => $db->fetchValue('select count(*) from public.pedidos'));
echo "  ✔ $total pedidos en total (web + tienda física)\n";
echo "\nListo. Cuentas de demostración (contraseña: $password):\n";
foreach ($users as $u) {
    echo "  · {$u['email']}  [{$u['rol']}]\n";
}
