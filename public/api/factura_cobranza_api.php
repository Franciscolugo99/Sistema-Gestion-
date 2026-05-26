<?php
// public/api/factura_cobranza_api.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once FLUS_ROOT . '/src/cobranzas_lib.php';
require_once __DIR__ . '/../caja_lib.php';

require_login_json();

$pdo = getPDO();
$input = array_merge($_GET, $_POST, api_read_json());
$action = trim((string)($input['action'] ?? ''));

function flus_factura_cobranza_current_user_id(): int
{
    if (function_exists('session_user_id')) {
        return session_user_id();
    }
    return (int)($_SESSION['usuario_id'] ?? ($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0)));
}

function flus_factura_cobranza_current_user_name(PDO $pdo, int $userId): string
{
    if (function_exists('current_user')) {
        $user = current_user();
        if (is_array($user)) {
            $name = trim((string)($user['username'] ?? $user['nombre'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }

    if ($userId > 0) {
        try {
            $st = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $name = trim((string)($st->fetchColumn() ?: ''));
            if ($name !== '') {
                return $name;
            }
        } catch (Throwable $e) {
            // Best effort.
        }
    }

    return $userId > 0 ? ('user#' . $userId) : '';
}

function flus_factura_cobranza_current_terminal_id(): ?int
{
    if (function_exists('current_terminal_id')) {
        $terminalId = current_terminal_id();
        if ($terminalId > 0) {
            return $terminalId;
        }
    }

    $terminalId = (int)($_SESSION['terminal_id'] ?? 0);
    return $terminalId > 0 ? $terminalId : null;
}

function flus_factura_cobranza_current_caja_id(PDO $pdo, ?int $terminalId): ?int
{
    $cajaId = (int)($_SESSION['caja_id'] ?? 0);
    if ($cajaId > 0) {
        return $cajaId;
    }

    if ($terminalId === null || $terminalId <= 0 || !flus_table_exists($pdo, 'caja_sesiones')) {
        return null;
    }

    try {
        $st = $pdo->prepare("
            SELECT id FROM caja_sesiones
            WHERE terminal_id = ?
              AND (fecha_cierre IS NULL OR fecha_cierre = '0000-00-00 00:00:00')
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$terminalId]);
        $id = (int)($st->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

if ($action === '') {
    json_fail('Accion requerida', 400, ['error_code' => 'ACTION_REQUIRED']);
}

if ($action !== 'registrar_cobro_factura') {
    json_fail('Accion no reconocida', 400, ['error_code' => 'UNKNOWN_ACTION']);
}

require_method_json('POST');
require_perm_json('registrar_pago_cc');
require_csrf_json($input);

$userId = flus_factura_cobranza_current_user_id();
if ($userId <= 0) {
    json_fail('Usuario no identificado', 401, ['error_code' => 'USER_REQUIRED']);
}

$facturaId = (int)($input['factura_id'] ?? 0);
$monto = function_exists('parse_money_ar') ? parse_money_ar($input['monto'] ?? 0) : (float)($input['monto'] ?? 0);
$medioPago = function_exists('norm_medio_pago')
    ? norm_medio_pago((string)($input['medio_pago'] ?? 'EFECTIVO'))
    : strtoupper(trim((string)($input['medio_pago'] ?? 'EFECTIVO')));
$referencia = trim((string)($input['referencia'] ?? ''));
$observaciones = trim((string)($input['observaciones'] ?? ''));
$requestUid = trim((string)($input['request_uid'] ?? ''));
$registrarCaja = (int)($input['registrar_caja'] ?? 0) === 1;

if ($facturaId <= 0) {
    json_fail('Factura invalida', 400, ['error_code' => 'FACTURA_INVALIDA']);
}
if ($monto <= 0) {
    json_fail('El monto debe ser mayor a cero', 400, ['error_code' => 'MONTO_INVALIDO']);
}

$terminalId = flus_factura_cobranza_current_terminal_id();
$cajaId = $registrarCaja ? flus_factura_cobranza_current_caja_id($pdo, $terminalId) : null;
if ($registrarCaja && ($cajaId === null || $cajaId <= 0)) {
    json_fail('No hay caja abierta para registrar el movimiento de caja.', 409, ['error_code' => 'CAJA_NO_ABIERTA']);
}
if ($registrarCaja && $terminalId !== null && $terminalId > 0) {
    $cajaTurno = caja_get_abierta($pdo, $terminalId);
    if (is_array($cajaTurno) && (int)($cajaTurno['id'] ?? 0) === (int)$cajaId && !caja_user_can_operar_turno($cajaTurno, $userId)) {
        json_fail('Esta caja fue abierta por ' . caja_turno_owner_label($cajaTurno) . '. Cerrá ese turno o cambiá de terminal para registrar el cobro.', 409, ['error_code' => 'CAJA_TURNO_AJENO']);
    }
}

$result = flus_cobranzas_register_invoice_payment($pdo, [
    'factura_id' => $facturaId,
    'monto' => $monto,
    'medio_pago' => $medioPago,
    'referencia' => $referencia,
    'observaciones' => $observaciones !== '' ? $observaciones : 'Cobro registrado desde factura',
    'request_uid' => $requestUid,
    'created_by' => $userId,
    'usuario_nombre' => flus_factura_cobranza_current_user_name($pdo, $userId),
    'caja_id' => $cajaId,
    'terminal_id' => $terminalId,
    'registrar_caja_mov' => $registrarCaja,
]);

if (($result['success'] ?? false) !== true) {
    json_response([
        'success' => false,
        'error' => (string)($result['error'] ?? 'No se pudo registrar el cobro.'),
    ], 400);
}

json_response(['success' => true] + $result);
