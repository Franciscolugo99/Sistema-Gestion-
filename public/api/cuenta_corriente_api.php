<?php
// public/api/cuenta_corriente_api.php
// FLUS - API para operaciones de Cuenta Corriente
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/CuentaCorrienteController.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar login
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$cc = new CuentaCorrienteController($pdo);

// Obtener acción
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Usuario actual (se usa en varias acciones)
$usuarioId = function_exists('session_user_id') ? session_user_id() : (int)($_SESSION['usuario_id'] ?? ($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0)));
$cajaId = (int)($_SESSION['caja_id'] ?? 0) ?: null;
$terminalId = (int)($_SESSION['terminal_id'] ?? 0) ?: null;

try {
    switch ($action) {
        
        // ═══════════════════════════════════════════════════════════════
        // BUSCAR CLIENTES (para autocompletar)
        // ═══════════════════════════════════════════════════════════════
        case 'buscar_clientes':
            require_permission('ver_cuenta_corriente');
            
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode(['success' => true, 'clientes' => []]);
                exit;
            }
            
            $sql = "
                SELECT id, nombre, telefono, cc_saldo, cc_limite
                FROM clientes
                WHERE activo = 1 
                  AND cc_habilitado = 1
                  AND cc_saldo > 0
                  AND (nombre LIKE :q OR telefono LIKE :q OR cuit LIKE :q)
                ORDER BY nombre ASC
                LIMIT 10
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':q' => '%' . $q . '%']);
            $clientes = $st->fetchAll(PDO::FETCH_ASSOC);
            
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
                ['caja_id' => $cajaId, 'terminal_id' => $terminalId]
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
    // Throwable atrapa Exception Y Error (TypeError, etc.)
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
