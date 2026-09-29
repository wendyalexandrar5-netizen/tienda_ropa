<?php
/**
 * Pruebas de integración de extremo a extremo (HTTP real + verificación en la BD).
 *
 * Uso (con Apache y MySQL de XAMPP encendidos y la BD recién importada):
 *   php tests/pruebas_integracion.php http://localhost/tienda_ropa
 *
 * ¡ATENCIÓN! Las pruebas crean y modifican datos (pedidos, usuarios, stock).
 * Después de ejecutarlas vuelve a importar database.sql para restaurar la demo.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Ejecutar sólo desde la línea de comandos.');
}

require __DIR__ . '/../includes/bootstrap.php';

$BASE = rtrim($argv[1] ?? 'http://localhost/tienda_ropa', '/');
$ok = 0;
$fallos = [];

function prueba(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) {
        $ok++;
        echo "  \033[32m✔\033[0m $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  \033[31m✘ $nombre\033[0m" . ($detalle !== '' ? " → $detalle" : '') . "\n";
    }
}

function seccion(string $t): void
{
    echo "\n\033[1m$t\033[0m\n";
}

/** Cliente HTTP mínimo con cookies (una "pestaña de navegador" por instancia). */
final class Navegador
{
    private string $cookies;
    private string $token = '';
    public int $codigo = 0;
    public string $html = '';
    public string $ubicacion = '';
    public array $cabeceras = [];

    public function __construct(private string $base)
    {
        $this->cookies = tempnam(sys_get_temp_dir(), 'fc');
    }

    public function get(string $ruta): self
    {
        return $this->pedir('GET', $ruta);
    }

    /** POST con el token CSRF de la última página (salvo que se indique otro). */
    public function post(string $ruta, array $datos = [], bool $conCsrf = true, array $archivos = []): self
    {
        if ($conCsrf && !isset($datos['_csrf'])) {
            $datos['_csrf'] = $this->csrf();
        }
        if ($archivos) {
            // multipart/form-data: los arreglos se envían como campo[0], campo[1]… (igual que un navegador)
            foreach ($datos as $k => $v) {
                if (is_array($v)) {
                    unset($datos[$k]);
                    foreach (array_values($v) as $i => $item) {
                        $datos["{$k}[$i]"] = $item;
                    }
                }
            }
        }
        foreach ($archivos as $campo => [$ruta_archivo, $mime, $nombre]) {
            $datos[$campo] = new CURLFile($ruta_archivo, $mime, $nombre);
        }
        return $this->pedir('POST', $ruta, $datos, (bool) $archivos);
    }

    /** Último token CSRF visto en una página (el token es por sesión). */
    public function csrf(): string
    {
        return $this->token;
    }

    public function contiene(string $texto): bool
    {
        return str_contains(html_entity_decode($this->html, ENT_QUOTES, 'UTF-8'), $texto);
    }

    public function seguir(): self
    {
        $n = 0;
        while (in_array($this->codigo, [301, 302, 303], true) && $this->ubicacion !== '' && $n++ < 5) {
            $destino = $this->ubicacion;
            if (!preg_match('#^https?://#', $destino)) {
                $p = parse_url($this->base);
                $destino = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $destino;
            }
            $this->pedir('GET', $destino);
        }
        return $this;
    }

    private function pedir(string $metodo, string $ruta, array $datos = [], bool $multipart = false): self
    {
        $url = preg_match('#^https?://#', $ruta) ? $ruta : $this->base . '/' . ltrim($ruta, '/');
        $ch = curl_init($url);
        $this->cabeceras = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookies,
            CURLOPT_COOKIEFILE     => $this->cookies,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $h) {
                $partes = explode(':', $h, 2);
                if (count($partes) === 2) {
                    $this->cabeceras[strtolower(trim($partes[0]))] = trim($partes[1]);
                }
                return strlen($h);
            },
        ]);
        if ($metodo === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $datos : http_build_query($datos));
        }
        $this->html = (string) curl_exec($ch);
        $this->codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->ubicacion = $this->cabeceras['location'] ?? '';
        if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->html, $m)) {
            $this->token = $m[1];
        }
        return $this;
    }
}

function stock(int $varianteId): int
{
    return (int) valor('SELECT stock FROM variantes_producto WHERE id = ?', [$varianteId]);
}

// ---------------------------------------------------------------------------
seccion('1. Páginas públicas y rutas');
$anon = new Navegador($BASE);
foreach (['index.php', 'productos.php', 'productos.php?categoria=3&talla=3', 'producto.php?id=1', 'categorias.php',
          'carrito.php', 'login.php', 'registro.php', 'recuperar.php', 'admin/login.php'] as $r) {
    prueba("GET $r → 200", $anon->get($r)->codigo === 200, (string) $anon->codigo);
}
prueba('Producto inexistente → 404', $anon->get('producto.php?id=99999')->codigo === 404);
prueba('Checkout sin sesión redirige al login', $anon->get('checkout.php')->codigo === 302 && str_contains($anon->ubicacion, 'login.php'));
prueba('Búsqueda "sudadera" encuentra resultados', $anon->get('productos.php?q=sudadera')->contiene('Sudadera clásica'));
prueba('Filtro por talla XS sólo muestra productos con XS', $anon->get('productos.php?talla=1')->contiene('Buzo cuello redondo') && !$anon->contiene('Pantalón cargo'));
prueba('Filtro por precio máximo 40.000', $anon->get('productos.php?precio_max=40000')->contiene('Gorro Alien') && !$anon->contiene('Chaqueta urbana'));
$anon->get('index.php');
$fresco = (new Navegador($BASE))->get('index.php');
prueba('Cabecera CSP presente', str_contains($anon->cabeceras['content-security-policy'] ?? '', "script-src 'self'"));
prueba('Cabecera X-Frame-Options DENY', ($anon->cabeceras['x-frame-options'] ?? '') === 'DENY');
$cookie = $fresco->cabeceras['set-cookie'] ?? '';
prueba('Cookie de sesión HttpOnly y SameSite=Lax', stripos($cookie, 'HttpOnly') !== false && stripos($cookie, 'SameSite=Lax') !== false, $cookie);

seccion('2. Archivos y carpetas protegidos (Apache/.htaccess)');
foreach (['config/config.php', 'includes/db.php', 'includes/bootstrap.php', 'admin/includes/header.php', 'database.sql',
          'storage/logs/', 'tests/pruebas_integracion.php', 'uploads/', 'README.md', '.htaccess'] as $r) {
    $c = $anon->get($r)->codigo;
    prueba("Bloqueado: $r", $c === 403 || $c === 404, (string) $c);
}
$php = ROOT_PATH . '/uploads/productos/prueba_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($php, '<?php echo "EJECUTADO-" . (6*7);');
$anon->get('uploads/productos/' . basename($php));
prueba('Un .php dentro de uploads/ NO se ejecuta', !str_contains($anon->html, 'EJECUTADO-42'), $anon->codigo . ' ' . substr($anon->html, 0, 60));
unlink($php);

seccion('3. Registro');
$email = 'prueba_' . bin2hex(random_bytes(3)) . '@example.com';
$cli = new Navegador($BASE);
$cli->get('registro.php');
$base = ['nombre' => 'María José', 'apellido' => 'Pérez', 'email' => $email, 'telefono' => '3001112233',
         'direccion' => 'Calle 1 # 2-3', 'ciudad' => 'Bogotá', 'acepto' => '1'];
$cli->post('registro.php', $base + ['password' => 'debil', 'password_confirmacion' => 'debil']);
prueba('Contraseña débil rechazada', $cli->codigo === 200 && $cli->contiene('al menos 8 caracteres'));
$cli->post('registro.php', $base + ['password' => 'Segura123', 'password_confirmacion' => 'Otra12345']);
prueba('Confirmación distinta rechazada', $cli->contiene('Las contraseñas no coinciden'));
$cli->post('registro.php', array_merge($base, ['email' => 'no-es-correo']) + ['password' => 'Segura123', 'password_confirmacion' => 'Segura123']);
prueba('Email inválido rechazado', $cli->contiene('correo electrónico válido'));
$cli->post('registro.php', array_merge($base, ['email' => 'cliente@firecat.com']) + ['password' => 'Segura123', 'password_confirmacion' => 'Segura123']);
prueba('Email duplicado rechazado', $cli->contiene('No fue posible registrar este correo'));
$cli->post('registro.php', $base + ['password' => 'Segura123', 'password_confirmacion' => 'Segura123', 'rol' => 'administrador']);
prueba('Registro válido redirige', $cli->codigo === 302);
$nuevo = fila('SELECT id, rol, password_hash FROM usuarios WHERE email = ?', [$email]);
prueba('Usuario creado con rol cliente (se ignora "rol" enviado)', $nuevo && $nuevo['rol'] === 'cliente');
prueba('Contraseña guardada como hash bcrypt', $nuevo && str_starts_with($nuevo['password_hash'], '$2y$') && password_verify('Segura123', $nuevo['password_hash']));
$cli->post('registro.php', ['nombre' => 'X'], false);
prueba('POST sin token CSRF → 403 "La solicitud expiró"', $cli->codigo === 403 && $cli->contiene('La solicitud expiró'));

seccion('4. Login / logout');
$cli->get('index.php');
$cli->post('logout.php');
prueba('Logout por POST redirige', $cli->codigo === 302);
prueba('Logout por GET no cierra sesión (sólo confirma)', (new Navegador($BASE))->get('logout.php')->codigo === 302);
$l = new Navegador($BASE);
$l->get('login.php');
$l->post('login.php', ['email' => 'cliente@firecat.com', 'password' => 'incorrecta']);
$msgMalPass = $l->contiene('Correo o contraseña incorrectos.');
$l->post('login.php', ['email' => 'noexiste@firecat.com', 'password' => 'Cualquiera1']);
prueba('Mensaje genérico (misma respuesta con correo inexistente o clave errónea)', $msgMalPass && $l->contiene('Correo o contraseña incorrectos.'));
$l->post('login.php', ['email' => "' OR '1'='1", 'password' => "' OR '1'='1"]);
prueba('SQL Injection en login no autentica', $l->codigo === 200 && $l->contiene('Correo o contraseña incorrectos.'));
$l->post('login.php', ['email' => 'cliente@firecat.com', 'password' => 'Cliente123*', 'retorno' => 'https://sitio-malicioso.com/']);
prueba('Login correcto + retorno externo ignorado (sin open redirect)', $l->codigo === 302 && !str_contains($l->ubicacion, 'malicioso'), $l->ubicacion);
$cookieAntes = $l->cabeceras['set-cookie'] ?? '';
prueba('Se regenera el ID de sesión al iniciar sesión', str_contains($cookieAntes, 'FIRECATSESSID='));

seccion('5. Control de acceso por rol');
foreach (['admin/index.php', 'admin/productos.php', 'admin/producto_editar.php?id=1', 'admin/usuarios.php', 'admin/reportes.php?exportar=pedidos'] as $r) {
    prueba("Cliente en $r → 403", $l->get($r)->codigo === 403, (string) $l->codigo);
}
prueba('Anónimo en admin/productos.php → login del panel', $anon->get('admin/productos.php')->codigo === 302 && str_contains($anon->ubicacion, 'admin/login.php'));
$l->post('admin/productos.php', ['accion' => 'estado', 'producto_id' => 1]);
prueba('Cliente no puede ejecutar acciones POST del panel', $l->codigo === 403 && fila('SELECT estado FROM productos WHERE id = 1')['estado'] === 'activo');
$a = new Navegador($BASE);
$a->get('admin/login.php');
$a->post('admin/login.php', ['email' => 'cliente@firecat.com', 'password' => 'Cliente123*']);
prueba('Login del panel rechaza cuentas cliente', $a->contiene('sin permisos de administrador'));

seccion('6. Carrito: validaciones de servidor');
$uid = (int) valor("SELECT id FROM usuarios WHERE email = 'cliente@firecat.com'");
carrito_vaciar($uid);
$l->get('producto.php?id=9');
$l->post('carrito.php', ['accion' => 'agregar', 'variante_id' => 50, 'cantidad' => 2, 'precio' => 1, 'retorno' => 'producto.php?id=9']);
$item = fila('SELECT cd.cantidad, cd.precio_unitario FROM carrito_detalle cd JOIN carritos c ON c.id = cd.carrito_id WHERE c.usuario_id = ? AND cd.variante_id = 50', [$uid]);
prueba('Agregar al carrito (precio del navegador ignorado)', $item && (int) $item['cantidad'] === 2 && (float) $item['precio_unitario'] === 129900.0);
$l->post('carrito.php', ['accion' => 'agregar', 'producto_id' => 1, 'talla_id' => 5, 'color_id' => 2, 'cantidad' => 1, 'retorno' => 'producto.php?id=1'])->seguir();
prueba('Variante agotada no se puede agregar', $l->contiene('agotada'));
$l->post('carrito.php', ['accion' => 'agregar', 'variante_id' => 56, 'cantidad' => 4, 'retorno' => 'carrito.php'])->seguir();
prueba('Cantidad mayor al stock rechazada', $l->contiene('Sólo hay 3 unidad(es)'));
$l->post('carrito.php', ['accion' => 'agregar', 'variante_id' => 51, 'cantidad' => -5, 'retorno' => 'carrito.php'])->seguir();
prueba('Cantidad negativa rechazada', $l->contiene('La cantidad debe estar entre 1 y'));
$l->post('carrito.php', ['accion' => 'agregar', 'variante_id' => 2, 'cantidad' => 1], false);
prueba('Agregar sin CSRF → 403', $l->codigo === 403 && $l->contiene('La solicitud expiró'));
$l->get('carrito.php');
$itemId = (int) valor('SELECT cd.id FROM carrito_detalle cd JOIN carritos c ON c.id = cd.carrito_id WHERE c.usuario_id = ? AND cd.variante_id = 50', [$uid]);
$l->post('carrito.php', ['accion' => 'actualizar', 'item_id' => $itemId, 'cantidad' => 3]);
prueba('Actualizar cantidad', (int) valor('SELECT cantidad FROM carrito_detalle WHERE id = ?', [$itemId]) === 3);
$l->get('carrito.php');
$l->post('carrito.php', ['accion' => 'actualizar', 'item_id' => $itemId, 'cantidad' => 999])->seguir();
prueba('Actualizar a cantidad excesiva rechazado', (int) valor('SELECT cantidad FROM carrito_detalle WHERE id = ?', [$itemId]) === 3);
$ajeno = new Navegador($BASE);
$ajeno->get('login.php');
$ajeno->post('login.php', ['email' => 'andres@example.com', 'password' => 'Cliente123*']);
$ajeno->get('carrito.php');
$ajeno->post('carrito.php', ['accion' => 'eliminar', 'item_id' => $itemId]);
prueba('Otro cliente no puede borrar ítems de un carrito ajeno', (int) valor('SELECT COUNT(*) FROM carrito_detalle WHERE id = ?', [$itemId]) === 1);
$l->get('carrito.php');
prueba('Carrito muestra total con precio real de BD', $l->contiene('$389.700'));

seccion('7. Checkout y transacción');
$stockAntes = stock(50);
$movAntes = (int) valor('SELECT COUNT(*) FROM movimientos_inventario');
$l->get('checkout.php');
prueba('Paso de entrega precargado con el perfil', $l->contiene('Calle 45 # 12-30'));
$l->post('checkout.php', ['accion' => 'revisar', 'nombre' => '', 'telefono' => 'abc', 'direccion' => 'x', 'ciudad' => '', 'metodo_pago' => 'bitcoin']);
prueba('Datos de entrega inválidos rechazados', $l->contiene('Revisa los datos de entrega'));
$l->post('checkout.php', ['accion' => 'revisar', 'nombre' => 'Laura Gómez', 'telefono' => '3001234567', 'direccion' => 'Calle 45 # 12-30',
                          'ciudad' => 'Bogotá', 'metodo_pago' => 'contra_entrega', 'pago_con' => '100']);
prueba('Pago en efectivo menor al total rechazado', $l->contiene('debe cubrir el total'));
$l->post('checkout.php', ['accion' => 'revisar', 'nombre' => 'Laura Gómez', 'telefono' => '3001234567', 'direccion' => 'Calle 45 # 12-30',
                          'ciudad' => 'Bogotá', 'metodo_pago' => 'contra_entrega', 'pago_con' => '400000', 'notas' => '<script>alert(1)</script>'])->seguir();
prueba('Paso de resumen', $l->contiene('Revisa y confirma tu pedido'));
prueba('XSS en notas se muestra escapado', str_contains($l->html, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($l->html, '<script>alert(1)</script>'));

// Simula que otra persona compró mientras tanto: el stock baja por debajo de lo pedido.
consulta('UPDATE variantes_producto SET stock = 1 WHERE id = 50');
$pedidosAntes = (int) valor('SELECT COUNT(*) FROM pedidos');
$l->post('checkout.php', ['accion' => 'confirmar'])->seguir();
prueba('Stock insuficiente al confirmar → ROLLBACK (no se crea pedido)', (int) valor('SELECT COUNT(*) FROM pedidos') === $pedidosAntes && stock(50) === 1);
prueba('Se informa al cliente y el carrito se ajusta', $l->contiene('sólo quedan 1') || $l->contiene('Ajustamos'));
consulta('UPDATE variantes_producto SET stock = ? WHERE id = 50', [$stockAntes]);
carrito_vaciar($uid);
carrito_agregar($uid, 50, 3);

// Precio cambiado entre agregar y comprar: se cobra el precio vigente de la BD.
consulta('UPDATE carrito_detalle cd JOIN carritos c ON c.id = cd.carrito_id SET cd.precio_unitario = 1 WHERE c.usuario_id = ?', [$uid]);
$l->get('checkout.php');
$l->post('checkout.php', ['accion' => 'revisar', 'nombre' => 'Laura Gómez', 'telefono' => '3001234567', 'direccion' => 'Calle 45 # 12-30',
                          'ciudad' => 'Bogotá', 'metodo_pago' => 'contra_entrega', 'pago_con' => '400000']);
$l->get('checkout.php?paso=resumen');
$l->post('checkout.php', ['accion' => 'confirmar']);
prueba('Confirmar redirige a pedido_confirmado', $l->codigo === 302 && str_contains($l->ubicacion, 'pedido_confirmado.php'), $l->ubicacion);
$pedido = fila('SELECT * FROM pedidos WHERE usuario_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
prueba('Pedido creado con total calculado en servidor (3 × 129.900 + envío 0)', $pedido && (float) $pedido['total'] === 389700.0 && (float) $pedido['subtotal'] === 389700.0);
prueba('Código de pedido generado', $pedido && preg_match('/^FC-\d{6}$/', (string) $pedido['codigo']) === 1);
prueba('pedido_detalle guarda precio histórico', (float) valor('SELECT precio_unitario FROM pedido_detalle WHERE pedido_id = ?', [$pedido['id']]) === 129900.0);
prueba('Inventario descontado', stock(50) === $stockAntes - 3);
prueba('Movimiento "venta" en el kardex', (int) valor("SELECT COUNT(*) FROM movimientos_inventario WHERE pedido_id = ? AND tipo = 'venta'", [$pedido['id']]) === 1 && (int) valor('SELECT COUNT(*) FROM movimientos_inventario') === $movAntes + 1);
prueba('Carrito vaciado', carrito_contar($uid) === 0);
prueba('Historial inicial del pedido', (int) valor('SELECT COUNT(*) FROM pedido_historial WHERE pedido_id = ?', [$pedido['id']]) === 1);
$l->post('checkout.php', ['accion' => 'confirmar'])->seguir();
prueba('Doble envío no duplica el pedido', (int) valor('SELECT COUNT(*) FROM pedidos WHERE usuario_id = ?', [$uid]) === (int) valor('SELECT COUNT(*) FROM pedidos WHERE usuario_id = ? AND id <= ?', [$uid, $pedido['id']]));

seccion('8. Historial, IDOR y cancelación');
prueba('mis_pedidos lista el pedido', $l->get('mis_pedidos.php')->contiene($pedido['codigo']));
prueba('Detalle del propio pedido', $l->get('pedido.php?id=' . $pedido['id'])->codigo === 200);
prueba('Pedido de otro cliente → 404 (IDOR)', $l->get('pedido.php?id=2')->codigo === 404);
prueba('Confirmación de pedido ajeno → 404', $l->get('pedido_confirmado.php?codigo=FC-000002')->codigo === 404);
$l->get('pedido.php?id=' . $pedido['id']);
$l->post('pedido.php', ['pedido_id' => $pedido['id'], 'accion' => 'cancelar']);
prueba('Cliente cancela pedido pendiente', valor('SELECT estado FROM pedidos WHERE id = ?', [$pedido['id']]) === 'cancelado');
prueba('Stock devuelto al cancelar', stock(50) === $stockAntes);
$l->get('pedido.php?id=4');
$l->post('pedido.php', ['pedido_id' => 4, 'accion' => 'cancelar']);
prueba('No puede cancelar un pedido ya enviado', valor('SELECT estado FROM pedidos WHERE id = 4') === 'enviado');

seccion('9. Perfil');
$l->get('perfil.php');
$l->post('perfil.php', ['nombre' => '<script>x</script>', 'apellido' => 'Gómez', 'email' => 'cliente@firecat.com', 'telefono' => '3001234567', 'direccion' => 'Calle 45 # 12-30', 'ciudad' => 'Bogotá']);
prueba('Nombre con etiquetas HTML rechazado', $l->contiene('sólo puede contener letras'));
$l->post('perfil.php', ['nombre' => 'Laura', 'apellido' => 'Gómez', 'email' => 'cliente@firecat.com', 'telefono' => '3001234567', 'direccion' => 'Calle 45 # 12-30 <b>Apto</b>', 'ciudad' => 'Bogotá']);
$l->get('perfil.php');
prueba('Perfil actualizado y HTML escapado', $l->contiene('Tus datos fueron actualizados') && str_contains($l->html, '&lt;b&gt;Apto&lt;/b&gt;'));
$l->post('perfil.php', ['nombre' => 'Laura', 'apellido' => 'Gómez', 'email' => 'otro@firecat.com', 'telefono' => '3001234567', 'direccion' => 'Calle 45 # 12-30', 'ciudad' => 'Bogotá']);
prueba('Cambiar correo exige contraseña actual', $l->contiene('confirma tu contraseña actual'));
consulta("UPDATE usuarios SET direccion = 'Calle 45 # 12-30, Apto 402' WHERE id = ?", [$uid]);

seccion('10. Recuperación de contraseña');
$r = new Navegador($BASE);
$r->get('recuperar.php');
$r->post('recuperar.php', ['email' => 'noexiste@example.com']);
$respInexistente = $r->contiene('Si el correo está registrado');
$r->get('recuperar.php');
$r->post('recuperar.php', ['email' => $email]);
prueba('Misma respuesta para correos existentes e inexistentes', $respInexistente && $r->contiene('Si el correo está registrado'));
preg_match('/restablecer\.php\?token=([a-f0-9]{64})/', $r->html, $m);
prueba('Token generado (visible sólo en modo desarrollo)', !empty($m[1]));
prueba('En BD sólo se guarda el hash del token', !empty($m[1]) && (int) valor('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [hash('sha256', $m[1])]) === 1);
if (!empty($m[1])) {
    $r->get('restablecer.php?token=' . $m[1]);
    $r->post('restablecer.php', ['token' => $m[1], 'password' => 'NuevaClave9', 'password_confirmacion' => 'NuevaClave9']);
    prueba('Contraseña restablecida', $r->codigo === 302 && password_verify('NuevaClave9', (string) valor('SELECT password_hash FROM usuarios WHERE email = ?', [$email])));
    prueba('El token no puede reutilizarse', $r->get('restablecer.php?token=' . $m[1])->contiene('no es válido o ya expiró'));
}

seccion('11. Administrador');
$adm = new Navegador($BASE);
$adm->get('admin/login.php');
$adm->post('admin/login.php', ['email' => 'admin@firecat.com', 'password' => 'Admin123*']);
prueba('Login del administrador', $adm->codigo === 302);
$adm->get('admin/index.php');
prueba('Obligado a cambiar la contraseña inicial', $adm->codigo === 302 && str_contains($adm->ubicacion, 'cambiar_password.php'));
$adm->get('cambiar_password.php');
$adm->post('cambiar_password.php', ['password_actual' => 'Admin123*', 'password' => 'Admin123*', 'password_confirmacion' => 'Admin123*']);
prueba('No permite reutilizar la misma contraseña', $adm->contiene('debe ser diferente'));
$adm->post('cambiar_password.php', ['password_actual' => 'Admin123*', 'password' => 'AdminNueva2026', 'password_confirmacion' => 'AdminNueva2026']);
prueba('Contraseña de admin cambiada', $adm->codigo === 302 && (int) valor("SELECT debe_cambiar_password FROM usuarios WHERE email = 'admin@firecat.com'") === 0);
foreach (['admin/index.php', 'admin/productos.php', 'admin/producto_crear.php', 'admin/producto_editar.php?id=1', 'admin/categorias.php',
          'admin/inventario.php', 'admin/inventario.php?bajo=1', 'admin/pedidos.php', 'admin/pedidos.php?estado=pendiente', 'admin/pedido_detalle.php?id=1',
          'admin/usuarios.php', 'admin/usuario_editar.php?id=2', 'admin/usuario_editar.php', 'admin/reportes.php', 'admin/configuracion.php'] as $ruta) {
    prueba("Admin GET $ruta → 200", $adm->get($ruta)->codigo === 200, (string) $adm->codigo);
}
$adm->get('admin/index.php');
prueba('Dashboard muestra KPIs', $adm->contiene('Ventas totales') && $adm->contiene('Pedidos pendientes') && $adm->contiene('Poco inventario'));

// Crear producto con imagen real y variantes
$jpg = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
$im = imagecreatetruecolor(400, 500);
imagefill($im, 0, 0, imagecolorallocate($im, 255, 229, 0));
imagejpeg($im, $jpg);
$adm->get('admin/producto_crear.php');
$adm->post('admin/producto_crear.php', ['categoria_id' => 1, 'nombre' => 'Camiseta de prueba', 'descripcion' => 'Creada por las pruebas', 'precio' => '45000',
    'estado' => 'activo', 'tallas' => [2, 3], 'colores' => [1], 'stock_inicial' => 7], true, ['imagen' => [$jpg, 'image/jpeg', 'foto.jpg']]);
$nuevoP = fila("SELECT * FROM productos WHERE nombre = 'Camiseta de prueba' ORDER BY id DESC LIMIT 1");
prueba('Producto creado', $nuevoP !== null && $adm->codigo === 302);
prueba('Imagen guardada con nombre aleatorio en uploads/', $nuevoP && preg_match('#^uploads/productos/[a-f0-9]{32}\.jpg$#', (string) $nuevoP['imagen']) === 1 && is_file(ROOT_PATH . '/' . $nuevoP['imagen']));
prueba('Variantes generadas (2 tallas × 1 color, stock 7)', $nuevoP && (int) valor('SELECT COUNT(*) FROM variantes_producto WHERE producto_id = ? AND stock = 7', [$nuevoP['id']]) === 2);
prueba('Producto visible en la tienda', $anon->get('producto.php?id=' . ($nuevoP['id'] ?? 0))->codigo === 200);

$falso = tempnam(sys_get_temp_dir(), 'php') . '.jpg';
file_put_contents($falso, "<?php system(\$_GET['c']); ?>");
$adm->get('admin/producto_crear.php');
$adm->post('admin/producto_crear.php', ['categoria_id' => 1, 'nombre' => 'Producto malicioso', 'precio' => '1000', 'estado' => 'activo'], true,
    ['imagen' => [$falso, 'image/jpeg', 'shell.php.jpg']]);
prueba('Archivo PHP disfrazado de imagen rechazado', $adm->contiene('no es una imagen válida') && !valor("SELECT id FROM productos WHERE nombre = 'Producto malicioso'"));
$adm->post('admin/producto_crear.php', ['categoria_id' => 1, 'nombre' => 'Producto svg', 'precio' => '1000', 'estado' => 'activo'], true,
    ['imagen' => [$jpg, 'image/jpeg', 'imagen.svg']]);
prueba('Extensión no permitida rechazada', $adm->contiene('Formato no permitido'));
$adm->post('admin/producto_crear.php', ['categoria_id' => 999, 'nombre' => 'X', 'precio' => '-5', 'estado' => 'otro']);
prueba('Validación de servidor en productos', $adm->contiene('Selecciona una categoría válida') && $adm->contiene('El precio debe ser'));

// Editar precio: los pedidos antiguos conservan su precio
$adm->get('admin/producto_editar.php?id=1');
$adm->post('admin/producto_editar.php', ['accion' => 'guardar', 'producto_id' => 1, 'categoria_id' => 1, 'nombre' => 'Camiseta básica',
    'descripcion' => 'Actualizada', 'precio' => '59900', 'estado' => 'activo', 'destacado' => '1']);
prueba('Precio del producto editado', (float) valor('SELECT precio FROM productos WHERE id = 1') === 59900.0);
prueba('Pedido antiguo conserva precio histórico ($49.900)', (float) valor('SELECT precio_unitario FROM pedido_detalle WHERE pedido_id = 1 AND producto_id = 1') === 49900.0);
$adm->get('admin/producto_editar.php?id=' . $nuevoP['id']);
$adm->post('admin/producto_editar.php', ['accion' => 'agregar_variante', 'producto_id' => $nuevoP['id'], 'talla_id' => 4, 'color_id' => 1, 'stock' => 3]);
prueba('Agregar variante', (int) valor('SELECT COUNT(*) FROM variantes_producto WHERE producto_id = ?', [$nuevoP['id']]) === 3);
$adm->post('admin/producto_editar.php', ['accion' => 'agregar_variante', 'producto_id' => $nuevoP['id'], 'talla_id' => 4, 'color_id' => 1, 'stock' => 3])->seguir();
prueba('Variante duplicada rechazada', $adm->contiene('ya existe'));

// Desactivar producto
$adm->get('admin/productos.php');
$adm->post('admin/productos.php', ['accion' => 'estado', 'producto_id' => $nuevoP['id']]);
prueba('Desactivar producto', valor('SELECT estado FROM productos WHERE id = ?', [$nuevoP['id']]) === 'inactivo');
prueba('Producto inactivo no visible en la tienda', $anon->get('producto.php?id=' . $nuevoP['id'])->codigo === 404);
$adm->get('admin/productos.php');
$adm->post('admin/productos.php', ['accion' => 'eliminar', 'producto_id' => 1])->seguir();
prueba('Producto con ventas no se elimina', (int) valor('SELECT COUNT(*) FROM productos WHERE id = 1') === 1 && $adm->contiene('tiene pedidos asociados'));
$adm->post('admin/productos.php', ['accion' => 'eliminar', 'producto_id' => $nuevoP['id']]);
prueba('Producto sin ventas eliminado (y su imagen)', !valor('SELECT id FROM productos WHERE id = ?', [$nuevoP['id']]) && !is_file(ROOT_PATH . '/' . $nuevoP['imagen']));

// Categorías
$adm->get('admin/categorias.php');
$adm->post('admin/categorias.php', ['accion' => 'guardar', 'categoria_id' => 0, 'nombre' => 'Temporada', 'descripcion' => 'Prueba', 'estado' => 'activa']);
$catId = (int) valor("SELECT id FROM categorias WHERE nombre = 'Temporada'");
prueba('Crear categoría', $catId > 0);
$adm->post('admin/categorias.php', ['accion' => 'guardar', 'categoria_id' => 0, 'nombre' => 'Temporada', 'estado' => 'activa']);
prueba('Nombre de categoría duplicado rechazado', $adm->contiene('Ya existe una categoría'));
$adm->post('admin/categorias.php', ['accion' => 'guardar', 'categoria_id' => $catId, 'nombre' => 'Temporada 2026', 'descripcion' => '', 'estado' => 'inactiva']);
prueba('Editar categoría', valor('SELECT nombre FROM categorias WHERE id = ?', [$catId]) === 'Temporada 2026');
$adm->post('admin/categorias.php', ['accion' => 'eliminar', 'categoria_id' => 1])->seguir();
prueba('Categoría con productos no se elimina', (int) valor('SELECT COUNT(*) FROM categorias WHERE id = 1') === 1);
$adm->post('admin/categorias.php', ['accion' => 'eliminar', 'categoria_id' => $catId]);
prueba('Eliminar categoría vacía', !valor('SELECT id FROM categorias WHERE id = ?', [$catId]));

// Inventario
$s = stock(2);
$adm->get('admin/inventario.php');
$adm->post('admin/inventario.php', ['variante_id' => 2, 'tipo' => 'entrada', 'cantidad' => 5, 'motivo' => 'Reposición']);
prueba('Entrada de inventario', stock(2) === $s + 5);
$adm->post('admin/inventario.php', ['variante_id' => 2, 'tipo' => 'salida', 'cantidad' => 9999, 'motivo' => 'Error'])->seguir();
prueba('Salida mayor al stock rechazada', stock(2) === $s + 5 && $adm->contiene('Stock insuficiente'));
$adm->post('admin/inventario.php', ['variante_id' => 2, 'tipo' => 'ajuste', 'cantidad' => $s, 'motivo' => 'Conteo físico']);
prueba('Ajuste por conteo físico', stock(2) === $s);
prueba('Movimientos registrados en kardex', (int) valor("SELECT COUNT(*) FROM movimientos_inventario WHERE variante_id = 2 AND tipo IN ('entrada','ajuste') AND usuario_id = 1 AND motivo IN ('Reposición','Conteo físico')") === 2);

// Pedidos: flujo de estados
$adm->get('admin/pedido_detalle.php?id=8');
$adm->post('admin/pedido_detalle.php', ['pedido_id' => 8, 'estado' => 'entregado'])->seguir();
prueba('Transición inválida (pendiente → entregado) rechazada', valor('SELECT estado FROM pedidos WHERE id = 8') === 'pendiente' && $adm->contiene('No es posible pasar'));
$adm->post('admin/pedido_detalle.php', ['pedido_id' => 8, 'estado' => 'confirmado', 'comentario' => 'Validado por teléfono']);
prueba('Cambio de estado pendiente → confirmado', valor('SELECT estado FROM pedidos WHERE id = 8') === 'confirmado');
prueba('Historial registra el cambio', (int) valor("SELECT COUNT(*) FROM pedido_historial WHERE pedido_id = 8 AND estado_nuevo = 'confirmado' AND usuario_id = 1") === 1);
$s77 = stock(77);
$adm->post('admin/pedido_detalle.php', ['pedido_id' => 8, 'estado' => 'cancelado']);
prueba('Cancelación por admin devuelve stock', valor('SELECT estado FROM pedidos WHERE id = 8') === 'cancelado' && stock(77) === $s77 + 1);
$adm->post('admin/pedido_detalle.php', ['pedido_id' => 8, 'estado' => 'confirmado'])->seguir();
prueba('Pedido cancelado no admite más cambios', valor('SELECT estado FROM pedidos WHERE id = 8') === 'cancelado');

// Usuarios
$adm->get('admin/usuario_editar.php?id=1');
$adm->post('admin/usuario_editar.php', ['usuario_id' => 1, 'nombre' => 'Admin', 'apellido' => 'FIRE CAT', 'email' => 'admin@firecat.com', 'rol' => 'cliente', 'estado' => 'activo']);
prueba('Admin no puede quitarse su propio rol', valor('SELECT rol FROM usuarios WHERE id = 1') === 'administrador');
$adm->get('admin/usuarios.php');
$adm->post('admin/usuarios.php', ['accion' => 'estado', 'usuario_id' => $nuevo['id']]);
prueba('Desactivar cliente', valor('SELECT estado FROM usuarios WHERE id = ?', [$nuevo['id']]) === 'inactivo');
$bloq = new Navegador($BASE);
$bloq->get('login.php');
$bloq->post('login.php', ['email' => $email, 'password' => 'NuevaClave9']);
prueba('Cliente inactivo no puede iniciar sesión (mensaje genérico)', $bloq->contiene('Correo o contraseña incorrectos.'));
$adm->get('admin/usuario_editar.php');
$adm->post('admin/usuario_editar.php', ['usuario_id' => 0, 'nombre' => 'Soporte', 'apellido' => 'Tienda', 'email' => 'soporte_' . bin2hex(random_bytes(2)) . '@firecat.com', 'rol' => 'administrador', 'estado' => 'activo']);
prueba('Crear usuario con contraseña temporal', $adm->contiene('Contraseña temporal (se muestra sólo esta vez)'));

// Reportes y configuración
$adm->get('admin/reportes.php?exportar=pedidos');
prueba('Exportar pedidos a CSV', str_contains($adm->cabeceras['content-type'] ?? '', 'text/csv') && str_contains($adm->html, 'FC-000001'));
$adm->get('admin/reportes.php');
prueba('Reporte de caja por día', $adm->contiene('Resumen de caja por día'));
$adm->get('admin/configuracion.php');
$adm->post('admin/configuracion.php', ['accion' => 'ajustes', 'nombre_tienda' => 'FIRE CAT', 'eslogan' => 'La mejor calidad en venta de ropa y textiles',
    'email_contacto' => 'hola@firecat.com', 'telefono_contacto' => '+57 300 000 0000', 'costo_envio' => '15000', 'envio_gratis_desde' => '200000', 'umbral_stock_bajo' => '5']);
prueba('Guardar configuración', valor("SELECT valor FROM configuracion WHERE clave = 'costo_envio'") === '15000');
consulta("UPDATE configuracion SET valor = '12000' WHERE clave = 'costo_envio'");

seccion('12. Fuerza bruta');
$fb = new Navegador($BASE);
$fb->get('login.php');
for ($i = 0; $i < 6; $i++) {
    $fb->post('login.php', ['email' => 'camila@example.com', 'password' => 'mala' . $i]);
}
prueba('Bloqueo temporal tras 5 intentos fallidos', $fb->contiene('Demasiados intentos'));
$fb->post('login.php', ['email' => 'camila@example.com', 'password' => 'Cliente123*']);
prueba('Durante el bloqueo, ni la contraseña correcta funciona', $fb->contiene('Demasiados intentos'));
consulta("DELETE FROM intentos_login WHERE email = 'camila@example.com'");

// ---------------------------------------------------------------------------
$total = $ok + count($fallos);
echo "\n\033[1mResultado: $ok / $total pruebas superadas\033[0m\n";
if ($fallos) {
    echo "Fallidas:\n - " . implode("\n - ", $fallos) . "\n";
}
echo "Recuerda volver a importar database.sql para restaurar los datos de demostración.\n";
exit($fallos ? 1 : 0);
