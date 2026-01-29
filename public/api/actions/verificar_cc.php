<?php
declare(strict_types=1);
// public/api/actions/verificar_cc.php
// Verificar si un cliente tiene disponible el crédito solicitado

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!defined('FLUS_API_CONTEXT')) define('FLUS_API_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';

$fail = function(int $code, string $error, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$error] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
};

$respond = function(array $payload): void {
    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

// Verificar login
if (function_exists('require_login_json')) {
    require_login_json();
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $uid = (int)($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0));
    if ($uid <= 0) $fail(401, 'No autenticado');
}

// Verificar permiso
if (function_exists('user_has_permission') && !user_has_permission('registrar_cargo_cc')) {
    $fail(403, 'No autorizado para operar con cuenta corriente');
}

$clienteId = (int)($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);
$monto = (float)($_GET['monto'] ?? $_POST['monto'] ?? 0);

if ($clienteId <= 0) {
    $fail(400, 'Cliente inválido');
}

try {
    $pdo = getPDO();
    
    // Obtener datos del cliente
    $st = $pdo->prepare("
        SELECT 
            id, nombre, cc_habilitado, cc_saldo, cc_limite,
            (cc_limite - cc_saldo) AS cc_disponible
        FROM clientes 
        WHERE id = ? AND activo = 1
    ");
    $st->execute([$clienteId]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente) {
        $fail(404, 'Cliente no encontrado');
    }
    
    if (!$cliente['cc_habilitado']) {
        $respond([
            'ok' => true,
            'habilitado' => false,
            'puede_comprar' => false,
            'error' => 'El cliente no tiene cuenta corriente habilitada'
        ]);
    }
    
    $saldo = (float)$cliente['cc_saldo'];
    $limite = (float)$cliente['cc_limite'];
    $disponible = (float)$cliente['cc_disponible'];
    
    $excede = ($monto > $disponible + 0.01);
    
    // Verificar si el usuario puede autorizar exceso
    $puedeAutorizar = function_exists('user_has_permission') && user_has_permission('vender_excedido_cc');
    
    $puedeComprar = !$excede || $puedeAutorizar;
    
    $respond([
        'ok' => true,
        'habilitado' => true,
        'cliente_id' => (int)$cliente['id'],
        'nombre' => $cliente['nombre'],
        'saldo' => round($saldo, 2),
        'limite' => round($limite, 2),
        'disponible' => round($disponible, 2),
        'monto_solicitado' => round($monto, 2),
        'excede' => $excede,
        'puede_comprar' => $puedeComprar,
        'puede_autorizar' => $puedeAutorizar,
        'mensaje' => $excede 
            ? ($puedeAutorizar 
                ? "Excede el límite (disponible: \$" . number_format($disponible, 2, ',', '.') . "). Se registrará con autorización."
                : "Excede el límite de crédito (disponible: \$" . number_format($disponible, 2, ',', '.') . ")")
            : null
    ]);
    
} catch (Throwable $e) {
    error_log("Error en verificar_cc: " . $e->getMessage());
    $fail(500, 'Error verificando CC: ' . $e->getMessage());
}
