<?php
/**
 * PESTAÑA 1 · Inicio del pago en línea (POST + CSRF desde el botón «Pagar»).
 *
 * 1. Crea el pedido UNA sola vez por compra: el token de la compra es UNIQUE en la BD,
 *    así que un doble clic, una recarga o dos pestañas nunca generan dos pedidos.
 * 2. Crea (o reutiliza) el intento de pago y obtiene la URL REAL del proveedor:
 *    - Wompi: Web Checkout firmado (sandbox).
 *    - PSE:   transacción PSE en la API de Wompi → URL del flujo PSE (async_payment_url).
 * 3. Redirige (303) a pago_ir.php, que lleva a la pasarela. Aquí NO se marca nada como pagado.
 */
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$uid = (int) $usuario['id'];
$token = post_texto('token', 32);

$mostrar = static function (string $titulo, string $mensaje, string $tipo = 'danger', array $enlaces = []): void {
    http_response_code($tipo === 'danger' ? 422 : 200);
    require ROOT_PATH . '/includes/partials/pago_aviso.php';
    exit;
};

if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    $mostrar('No pudimos iniciar el pago', 'La solicitud no es válida. Vuelve al carrito e inténtalo de nuevo.', 'danger', [['Ir al carrito', url('carrito.php')]]);
}

$pedido = fila('SELECT * FROM pedidos WHERE token_checkout = ? AND usuario_id = ?', [$token, $uid]);
if (!$pedido) {
    $datos = $_SESSION['checkout'] ?? null;
    if (!$datos || !hash_equals((string) ($datos['token'] ?? ''), $token) || !es_metodo_en_linea($datos['metodo_pago'] ?? '')) {
        $mostrar('No pudimos iniciar el pago', 'La compra ya no está disponible (la sesión expiró o se modificó en otra pestaña). Revisa tu carrito.', 'danger', [['Ir al carrito', url('carrito.php')]]);
    }
    if (($problema = problema_metodo($datos['metodo_pago'])) !== null) {
        $mostrar('Método de pago no disponible', $problema, 'warning', [['Volver al checkout', url('checkout.php')]]);
    }
    try {
        $creado = crear_pedido($uid, $datos + ['token_checkout' => $token]);
        if (!empty($datos['guardar_direccion'])) {
            consulta('UPDATE usuarios SET telefono = ?, direccion = ?, ciudad = ? WHERE id = ?', [$datos['telefono'], $datos['direccion'], $datos['ciudad'], $uid]);
        }
    } catch (DomainException $e) {
        // La otra pestaña (comprobante) leerá este motivo en lugar de esperar indefinidamente.
        cache_escribir('fallo_compra_' . $token, ['usuario' => $uid, 'mensaje' => $e->getMessage()]);
        $mostrar('No pudimos crear el pedido', $e->getMessage(), 'danger', [['Revisar mi carrito', url('carrito.php')]]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        // Otra petición con el mismo token ya creó el pedido (doble envío simultáneo): se reutiliza.
    }
    $pedido = fila('SELECT * FROM pedidos WHERE token_checkout = ? AND usuario_id = ?', [$token, $uid]);
    $_SESSION['pago_pse'][$token] = $datos['pse'] ?? null;   // sólo en sesión, nunca en la BD
    unset($_SESSION['checkout']);
}
$pseDatos = $_SESSION['pago_pse'][$token] ?? null;
// Se libera el bloqueo de la sesión: la otra pestaña puede consultar el comprobante mientras se habla con Wompi.
session_write_close();

$comprobante = url('pago_comprobante.php', ['token' => $token]);
if (!$pedido || !es_metodo_en_linea($pedido['metodo_pago'])) {
    $mostrar('No pudimos iniciar el pago', 'No encontramos un pedido con pago en línea para esta compra.', 'danger', [['Mis pedidos', url('mis_pedidos.php')]]);
}
if (pagos_modo_demo() && $pedido['estado'] !== 'cancelado') {
    // Modo enlace: se abre la página configurada de Wompi/PSE y el pago queda registrado como PAGADO.
    // El estado del pedido (preparado, enviado, entregado) lo sigue actualizando el administrador.
    $pago = pago_actual((int) $pedido['id']);
    if (!$pago || !$pago['url_pago']) {
        $pago = transaccion(static function () use ($pedido) {
            $p = fila('SELECT * FROM pedidos WHERE id = ? FOR UPDATE', [$pedido['id']]);
            $intento = crear_intento_pago($p, $p['metodo_pago']);
            consulta("UPDATE pagos SET url_pago = ?, estado = 'APPROVED', medio_wompi = ?, verificado_en = NOW() WHERE id = ?",
                [url_demo_pago($p['metodo_pago']), strtoupper($p['metodo_pago']), $intento['id']]);
            consulta("UPDATE pedidos SET estado_pago = 'aprobado' WHERE id = ?", [$p['id']]);
            $nombre = $p['metodo_pago'] === 'pse' ? 'PSE' : 'Wompi';
            if ($p['estado'] === 'pendiente') {
                consulta("UPDATE pedidos SET estado = 'confirmado' WHERE id = ?", [$p['id']]);
                consulta("INSERT INTO pedido_historial (pedido_id, estado_anterior, estado_nuevo, usuario_id, comentario) VALUES (?, 'pendiente', 'confirmado', NULL, ?)",
                    [$p['id'], "Pago registrado con $nombre (referencia {$intento['referencia']})"]);
            }
            return fila('SELECT * FROM pagos WHERE id = ?', [$intento['id']]);
        });
    }
    header('Location: ' . url('pago_ir.php', ['ref' => $pago['referencia']]), true, 303);
    exit;
}
if ($pedido['estado_pago'] === 'aprobado') {
    $mostrar('Este pedido ya está pagado', 'El pago de este pedido ya fue confirmado por el proveedor. No es necesario pagar de nuevo.', 'info', [['Ver comprobante', $comprobante]]);
}
if ($pedido['estado'] === 'cancelado') {
    $mostrar('Pedido cancelado', 'Este pedido fue cancelado y no admite pagos.', 'warning', [['Mis pedidos', url('mis_pedidos.php')]]);
}

// Se reutiliza el intento vigente (misma referencia y misma URL) para no abrir dos cobros:
//  - checkout de Wompi aún sin transacción, creado hace menos de 30 minutos;
//  - transacción ya iniciada en el proveedor que sigue pendiente (p. ej. PSE en el banco).
// Sólo se crea un intento nuevo cuando el anterior terminó sin éxito (rechazado, anulado, error) o venció.
$pago = pago_actual((int) $pedido['id']);
$reutilizable = $pago && $pago['url_pago'] && (
    ($pago['estado'] === 'CREADO' && strtotime((string) $pago['fecha_creacion']) > time() - 1800)
    || ($pago['estado'] === 'PENDING' && $pago['transaccion_id'] !== null)
);
if (!$reutilizable) {
    if ($pedido['metodo_pago'] === 'pse' && !$pseDatos) {
        $mostrar('Faltan los datos de PSE', 'Para un nuevo intento con PSE vuelve a hacer la compra desde el carrito.', 'warning', [['Ver comprobante', $comprobante]]);
    }
    try {
        $pago = crear_intento_pago($pedido, $pedido['metodo_pago']);
        if ($pedido['metodo_pago'] === 'wompi') {
            consulta('UPDATE pagos SET url_pago = ? WHERE id = ?', [wompi_url_checkout($pago, $pedido, $usuario), $pago['id']]);
        } else {
            wompi_iniciar_pse($pago, $pedido, $usuario, $pseDatos);
        }
        $pago = fila('SELECT * FROM pagos WHERE id = ?', [$pago['id']]);
    } catch (RuntimeException | DomainException $e) {
        if (!empty($pago['id'])) {
            consulta("UPDATE pagos SET estado = 'ERROR', mensaje = ? WHERE id = ? AND estado IN ('CREADO', 'PENDING') AND transaccion_id IS NULL",
                [mb_substr($e->getMessage(), 0, 255), $pago['id']]);
            consulta("UPDATE pedidos SET estado_pago = 'error' WHERE id = ? AND estado_pago = 'pendiente'", [$pedido['id']]);
        }
        registrar_log("Inicio de pago {$pedido['codigo']}: " . $e->getMessage(), 'pagos.log');
        $mostrar('No fue posible conectar con la pasarela', $e->getMessage() . ' Tu pedido ' . $pedido['codigo'] . ' quedó registrado y puedes reintentar el pago desde el comprobante.',
            'danger', [['Ver comprobante', $comprobante]]);
    }
}
header('Location: ' . url('pago_ir.php', ['ref' => $pago['referencia']]), true, 303);
exit;
