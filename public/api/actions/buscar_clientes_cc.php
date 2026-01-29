<?php
declare(strict_types=1);
// public/api/actions/buscar_clientes_cc.php
// Buscar clientes con Cuenta Corriente habilitada (para usar en Caja)

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

$q = trim((string)($_GET['q'] ?? ''));

if (strlen($q) < 2) {
    $respond(['ok' => true, 'clientes' => []]);
}

try {
    $pdo = getPDO();
    
    $like = '%' . $q . '%';
    
    // Buscar clientes con CC habilitado
    $sql = "
        SELECT 
            c.id,
            c.nombre,
            c.telefono,
            c.cuit,
            c.email,
            c.cc_habilitado,
            c.cc_saldo,
            c.cc_limite,
            (c.cc_limite - c.cc_saldo) AS cc_disponible
        FROM clientes c
        WHERE c.activo = 1 
          AND c.cc_habilitado = 1
          AND (
              c.nombre LIKE ? 
              OR c.telefono LIKE ? 
              OR c.cuit LIKE ?
              OR c.email LIKE ?
          )
        ORDER BY c.nombre ASC
        LIMIT 10
    ";
    
    $st = $pdo->prepare($sql);
    $st->execute([$like, $like, $like, $like]);
    $clientes = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos
    foreach ($clientes as &$c) {
        $c['id'] = (int)$c['id'];
        $c['cc_saldo'] = (float)($c['cc_saldo'] ?? 0);
        $c['cc_limite'] = (float)($c['cc_limite'] ?? 0);
        $c['cc_disponible'] = (float)($c['cc_disponible'] ?? 0);
    }
    
    $respond(['ok' => true, 'clientes' => $clientes]);
    
} catch (Throwable $e) {
    error_log("Error en buscar_clientes_cc: " . $e->getMessage());
    $fail(500, 'Error buscando clientes: ' . $e->getMessage());
}
