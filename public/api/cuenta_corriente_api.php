<?php
// public/api/cuenta_corriente_api.php
// FLUS - API para operaciones de Cuenta Corriente
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/CuentaCorrienteController.php';
require_login_json();

$pdo = getPDO();
$cc = new CuentaCorrienteController($pdo);

$input = array_merge($_GET, $_POST, api_read_json());

function cc_api_guard(string $action, array $input): void {
    $policy = [
        'buscar_clientes' => ['perm' => 'ver_cuenta_corriente'],
        'registrar_pago' => ['perm' => 'registrar_pago_cc', 'methods' => ['POST'], 'csrf' => true],
        'registrar_cargo' => ['perm' => 'registrar_cargo_cc', 'methods' => ['POST'], 'csrf' => true],
        'verificar_disponibilidad' => ['perm' => 'ver_cuenta_corriente'],
        'get_cliente' => ['perm' => 'ver_cuenta_corriente'],
        'get_movimientos' => ['perm' => 'ver_cuenta_corriente'],
        'registrar_ajuste' => ['perm' => 'ajustar_cc', 'methods' => ['POST'], 'csrf' => true],
        'reversar_movimiento' => ['perm' => 'anular_movimiento_cc', 'methods' => ['POST'], 'csrf' => true],
        'recalcular_saldo' => ['perm' => 'recalcular_saldo_cc', 'methods' => ['POST'], 'csrf' => true],
        'get_kpis' => ['perm' => 'ver_cuenta_corriente'],
        'habilitar_cc' => ['perm' => 'habilitar_cc', 'methods' => ['POST'], 'csrf' => true],
        'actualizar_limite' => ['perm' => 'habilitar_cc', 'methods' => ['POST'], 'csrf' => true],
    ];

    if ($action === '') {
        json_fail('Accion requerida', 400, ['error_code' => 'ACTION_REQUIRED']);
    }

    $config = $policy[$action] ?? null;
    if (!is_array($config)) {
        json_fail('Accion no reconocida', 400, ['error_code' => 'UNKNOWN_ACTION']);
    }

    if (!empty($config['methods'])) {
        require_method_json($config['methods']);
    }

    require_perm_json((string)$config['perm']);

    if (!empty($config['csrf'])) {
        require_csrf_json($input);
    }
}

// Obtener acción
$action = (string)($input['action'] ?? '');
cc_api_guard($action, $input);

// Usuario actual (se usa en varias acciones)
$usuarioId = function_exists('session_user_id') ? session_user_id() : (int)($_SESSION['usuario_id'] ?? ($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0)));
$cajaId = (int)($_SESSION['caja_id'] ?? 0) ?: null;
$terminalId = (int)($_SESSION['terminal_id'] ?? 0) ?: null;

$usuarioNombre = '';
if (function_exists('current_user')) {
    $u = current_user();
    if (is_array($u)) $usuarioNombre = (string)($u['username'] ?? '');
}
if ($usuarioNombre === '' && $usuarioId > 0) {
    try {
        $stU = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
        $stU->execute([$usuarioId]);
        $usuarioNombre = (string)($stU->fetchColumn() ?? '');
    } catch (Throwable $e) {
        $usuarioNombre = '';
    }
}

// Resolver caja abierta por terminal (no dependemos de $_SESSION['caja_id'])
if ($terminalId) {
    try {
        $stC = $pdo->prepare("
            SELECT id FROM caja_sesiones
            WHERE terminal_id = ?
              AND (fecha_cierre IS NULL OR fecha_cierre = '0000-00-00 00:00:00')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stC->execute([$terminalId]);
        $tmpCaja = (int)($stC->fetchColumn() ?? 0);
        if ($tmpCaja > 0) $cajaId = $tmpCaja;
    } catch (Throwable $e) {
        // no-op
    }
}


try {
    switch ($action) {
        
        // ═══════════════════════════════════════════════════════════════
        // BUSCAR CLIENTES (para autocompletar)
                // ═══════════════════════════════════════════════════════════════
        case 'buscar_clientes':
            require_permission('ver_cuenta_corriente');

            $q = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) {
                echo json_encode(['success' => true, 'clientes' => []]);
                exit;
            }
            $like = '%' . addcslashes($q, "%_") . '%';

            // B1 FIX: parámetro solo_deudores (default=1 → mantiene comportamiento previo).
            // Pasar solo_deudores=0 para buscar todos los clientes cc_habilitados,
            // incluyendo los que tienen saldo 0 (ej: cliente nuevo o saldo saldado).
            // Útil para registrar un cargo / nueva venta CC sin deuda previa.
            $soloDeudores = ((string)($_GET['solo_deudores'] ?? '1')) !== '0';
            $saldoFiltro  = $soloDeudores ? 'AND COALESCE(m.saldo_posterior, 0) > 0' : '';

            $sql = "
            SELECT
                c.id,
                c.nombre,
                c.telefono,
                COALESCE(m.saldo_posterior, 0) AS cc_saldo,
                c.cc_limite
            FROM clientes c
            LEFT JOIN (
                SELECT m1.cliente_id, m1.saldo_posterior
                FROM cuenta_corriente_movimientos m1
                INNER JOIN (
                SELECT cliente_id, MAX(id) AS max_id
                FROM cuenta_corriente_movimientos
                WHERE estado = 'ACTIVO'
                GROUP BY cliente_id
                ) t ON t.cliente_id = m1.cliente_id AND t.max_id = m1.id
            ) m ON m.cliente_id = c.id
            WHERE c.activo = 1
                AND c.cc_habilitado = 1
                {$saldoFiltro}
                AND (c.nombre LIKE ? OR c.telefono LIKE ? OR c.cuit LIKE ?)
            ORDER BY c.nombre ASC
            LIMIT 10
            ";

            $st = $pdo->prepare($sql);
            $st->execute([$like, $like, $like]);
            $clientes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            echo json_encode(['success' => true, 'clientes' => $clientes]);
            break;


        // ═══════════════════════════════════════════════════════════════
        // REGISTRAR PAGO
        // ═══════════════════════════════════════════════════════════════
        case 'registrar_pago':
            require_permission('registrar_pago_cc');
            
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                throw new Exception('Token CSRF inválido');
            }
            
            $clienteId = (int)($_POST['cliente_id'] ?? 0);
            $monto = (float)($_POST['monto'] ?? 0);
            $medioPago = strtoupper(trim($_POST['medio_pago'] ?? 'EFECTIVO'));
            $referencia = trim($_POST['referencia'] ?? '') ?: null;
            $concepto = trim($_POST['concepto'] ?? '') ?: null;
            $documentoId = (int)($_POST['documento_id'] ?? 0);
            $facturaId = (int)($_POST['factura_id'] ?? 0);
            $requestUid = trim((string)($_POST['request_uid'] ?? '')) ?: null;

            $fromCaja = ((int)($_POST['from_caja'] ?? 0) === 1);
            if ($fromCaja) {
                // Desde Caja: soportamos todos los medios de pago
                if (($terminalId ?? 0) <= 0) {
                    throw new Exception('Terminal no identificada');
                }
                if (($cajaId ?? 0) <= 0) {
                    throw new Exception('No hay caja abierta en esta terminal');
                }
            }
            if ($clienteId <= 0) {
                throw new Exception('Cliente inválido');
            }
            if ($monto <= 0) {
                throw new Exception('El monto debe ser mayor a cero');
            }
            if ($usuarioId <= 0) {
                throw new Exception('Usuario no identificado');
            }
            
            // ORDEN CORRECTO: clienteId, monto, medioPago, usuarioId, referencia, concepto, extras
            $result = $cc->registrarPago(
                $clienteId,
                $monto,
                $medioPago,
                $usuarioId,
                $referencia,
                $concepto,
                [
                    'caja_id' => $cajaId,
                    'terminal_id' => $terminalId,
                    'usuario_nombre' => $usuarioNombre,
                    'registrar_caja_mov' => $fromCaja,
                    'documento_id' => $documentoId > 0 ? $documentoId : null,
                    'factura_id' => $facturaId > 0 ? $facturaId : null,
                    'request_uid' => $requestUid,
                ]
            );
            echo json_encode($result);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // REGISTRAR CARGO (desde ventas)
        // ═══════════════════════════════════════════════════════════════
        case 'registrar_cargo':
            // Permiso separado: generar deuda es distinto a cobrar pagos
            require_permission('registrar_cargo_cc');
            
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                throw new Exception('Token CSRF inválido');
            }
            
            $clienteId = (int)($_POST['cliente_id'] ?? 0);
            $monto = (float)($_POST['monto'] ?? 0);
            $ventaId = (int)($_POST['venta_id'] ?? 0) ?: null;
            $concepto = trim($_POST['concepto'] ?? '') ?: null;
            
            if ($clienteId <= 0 || $monto <= 0) {
                throw new Exception('Datos inválidos');
            }
            if ($usuarioId <= 0) {
                throw new Exception('Usuario no identificado');
            }
            
            // Verificar si excede límite ANTES de intentar
            $check = $cc->verificarDisponibilidad($clienteId, $monto);
            $autorizadoPor = null;
            
            if ($check['excede'] ?? false) {
                // Solo puede autorizar si el usuario actual tiene el permiso
                if (!user_has_permission('vender_excedido_cc')) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Excede límite de crédito y no tenés permiso para autorizar',
                        'excede_limite' => true,
                        'disponible' => $check['disponible'] ?? 0,
                        'requiere_autorizacion' => true,
                    ]);
                    exit;
                }
                // El usuario actual es quien autoriza (no se acepta desde POST)
                $autorizadoPor = $usuarioId;
            }
            
            // ORDEN CORRECTO: clienteId, monto, usuarioId, ventaId, concepto, autorizadoPor, extras
            $result = $cc->registrarCargo(
                $clienteId,
                $monto,
                $usuarioId,
                $ventaId,
                $concepto,
                $autorizadoPor,
                ['caja_id' => $cajaId, 'terminal_id' => $terminalId]
            );
            
            echo json_encode($result);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // VERIFICAR DISPONIBILIDAD (antes de vender)
        // ═══════════════════════════════════════════════════════════════
        case 'verificar_disponibilidad':
            require_permission('ver_cuenta_corriente');
            
            $clienteId = (int)($_GET['cliente_id'] ?? 0);
            $monto = (float)($_GET['monto'] ?? 0);
            
            if ($clienteId <= 0) {
                throw new Exception('Cliente inválido');
            }
            
            $result = $cc->verificarDisponibilidad($clienteId, $monto);
            echo json_encode($result);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // OBTENER CLIENTE CC
        // ═══════════════════════════════════════════════════════════════
        case 'get_cliente':
            require_permission('ver_cuenta_corriente');
            
            $clienteId = (int)($_GET['id'] ?? 0);
            if ($clienteId <= 0) {
                throw new Exception('ID inválido');
            }
            
            $cliente = $cc->getClienteCC($clienteId);
            if (!$cliente) {
                throw new Exception('Cliente no encontrado');
            }
            
            echo json_encode(['success' => true, 'cliente' => $cliente]);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // OBTENER MOVIMIENTOS
        // ═══════════════════════════════════════════════════════════════
        case 'get_movimientos':
            require_permission('ver_cuenta_corriente');
            
            $clienteId = (int)($_GET['cliente_id'] ?? 0);
            if ($clienteId <= 0) {
                throw new Exception('Cliente inválido');
            }
            
            $filtros = [
                'page' => (int)($_GET['page'] ?? 1),
                'per_page' => (int)($_GET['per_page'] ?? 50),
                'tipo' => $_GET['tipo'] ?? '',
                'desde' => $_GET['desde'] ?? '',
                'hasta' => $_GET['hasta'] ?? '',
            ];
            
            $result = $cc->getMovimientos($clienteId, $filtros);
            echo json_encode(['success' => true] + $result);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // REGISTRAR AJUSTE
        // ═══════════════════════════════════════════════════════════════
        case 'registrar_ajuste':
            require_permission('ajustar_cc');
            
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                throw new Exception('Token CSRF inválido');
            }
            
            $clienteId = (int)($_POST['cliente_id'] ?? 0);
            $monto = (float)($_POST['monto'] ?? 0);
            $aumentaDeuda = ($_POST['tipo'] ?? 'positivo') === 'positivo';
            $concepto = trim($_POST['concepto'] ?? '');
            $referencia = trim($_POST['referencia'] ?? '') ?: null;
            
            if ($clienteId <= 0 || $monto <= 0) {
                throw new Exception('Datos inválidos');
            }
            if (trim($concepto) === '') {
                throw new Exception('El concepto es obligatorio para ajustes');
            }
            if ($usuarioId <= 0) {
                throw new Exception('Usuario no identificado');
            }
            
            // ORDEN: clienteId, monto, aumentaDeuda, concepto, usuarioId, referencia, extras
            $result = $cc->registrarAjuste(
                $clienteId,
                $monto,
                $aumentaDeuda,
                $concepto,
                $usuarioId,
                $referencia,
                ['caja_id' => $cajaId, 'terminal_id' => $terminalId]
            );
            
            echo json_encode($result);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // REVERSAR MOVIMIENTO
        // ═══════════════════════════════════════════════════════════════
        case 'reversar_movimiento':
            require_permission('anular_movimiento_cc');
            
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                throw new Exception('Token CSRF inválido');
            }
            
            $movimientoId = (int)($_POST['movimiento_id'] ?? 0);
            $motivo = trim($_POST['motivo'] ?? '');
            
            if ($movimientoId <= 0) {
                throw new Exception('Movimiento inválido');
            }
            if (trim($motivo) === '') {
                throw new Exception('El motivo es obligatorio');
            }
            if ($usuarioId <= 0) {
                throw new Exception('Usuario no identificado');
            }
            
            // ORDEN: movimientoId, motivo, usuarioId, extras
            $result = $cc->reversarMovimiento(
                $movimientoId,
                $motivo,
                $usuarioId,
                ['caja_id' => $cajaId, 'terminal_id' => $terminalId]
            );
            
            echo json_encode($result);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // RECALCULAR SALDO (solo POST + CSRF)
        // ═══════════════════════════════════════════════════════════════
        case 'recalcular_saldo':
            require_permission('recalcular_saldo_cc');
            
            // State-changing → requiere POST y CSRF
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método no permitido');
            }
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                throw new Exception('Token CSRF inválido');
            }
            
            $clienteId = (int)($_POST['cliente_id'] ?? 0);
            if ($clienteId <= 0) {
                throw new Exception('Cliente inválido');
            }
            
            $result = $cc->recalcularSaldo($clienteId);
            echo json_encode($result);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // KPIs
        // ═══════════════════════════════════════════════════════════════
        case 'get_kpis':
            require_permission('ver_cuenta_corriente');
            
            $kpis = $cc->getKPIs();
            echo json_encode(['success' => true, 'kpis' => $kpis]);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // HABILITAR CC
        // ═══════════════════════════════════════════════════════════════
        case 'habilitar_cc':
            require_permission('habilitar_cc');
            
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                throw new Exception('Token CSRF inválido');
            }
            
            $clienteId = (int)($_POST['cliente_id'] ?? 0);
            $limite = (float)($_POST['limite'] ?? 0);
            
            if ($clienteId <= 0) {
                throw new Exception('Cliente inválido');
            }
            
            $result = $cc->habilitarCC($clienteId, $limite);
            echo json_encode($result);
            break;
            
        // ═══════════════════════════════════════════════════════════════
        // ACTUALIZAR LÍMITE
        // ═══════════════════════════════════════════════════════════════
        case 'actualizar_limite':
            require_permission('habilitar_cc');
            
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                throw new Exception('Token CSRF inválido');
            }
            
            $clienteId = (int)($_POST['cliente_id'] ?? 0);
            $limite = (float)($_POST['limite'] ?? 0);
            
            if ($clienteId <= 0) {
                throw new Exception('Cliente inválido');
            }
            
            $result = $cc->actualizarLimite($clienteId, $limite);
            echo json_encode($result);
            break;
            
        default:
            throw new Exception('Acción no reconocida');
    }
    
} catch (Throwable $e) {
    error_log('[cuenta_corriente_api] ' . $e->getMessage());
    json_fail('No se pudo procesar la operacion de cuenta corriente.', 500, [
        'error_code' => 'INTERNAL_ERROR',
    ]);
}
