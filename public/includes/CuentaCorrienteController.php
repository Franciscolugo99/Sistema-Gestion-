<?php
// public/includes/CuentaCorrienteController.php
declare(strict_types=1);

/**
 * FLUS - Controlador de Cuenta Corriente (Fiado)
 * 
 * ARQUITECTURA:
 * - Fuente de verdad: tabla cuenta_corriente_movimientos
 * - clientes.cc_saldo es CACHE (se actualiza en cada movimiento dentro de transacción)
 * - Concurrencia: SELECT ... FOR UPDATE antes de cada operación
 * - Anulaciones: NUNCA se edita historial, se crean movimientos tipo REVERSA
 * 
 * @version 1.0.0
 */
class CuentaCorrienteController
{
    private PDO $pdo;
    
    // Tipos de movimiento
    public const TIPO_CARGO = 'CARGO';
    public const TIPO_PAGO = 'PAGO';
    public const TIPO_AJUSTE_POS = 'AJUSTE_POS';
    public const TIPO_AJUSTE_NEG = 'AJUSTE_NEG';
    public const TIPO_REVERSA = 'REVERSA';
    
    // Estados
    public const ESTADO_ACTIVO = 'ACTIVO';
    public const ESTADO_ANULADO = 'ANULADO';
    
    // Medios de pago
    public const MEDIOS_PAGO = [
        'EFECTIVO' => 'Efectivo',
        'TRANSFERENCIA' => 'Transferencia',
        'MP' => 'Mercado Pago',
        'DEBITO' => 'Débito',
        'CREDITO' => 'Crédito',
        'CHEQUE' => 'Cheque',
    ];
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (function_exists('flus_column_exists')) {
            return (bool)flus_column_exists($this->pdo, $table, $column);
        }

        if (function_exists('has_column')) {
            return (bool)has_column($this->pdo, $table, $column);
        }

        return false;
    }
    
    /**
     * Valida y normaliza un monto monetario
     * @param mixed $monto Valor a validar
     * @return array ['valid' => bool, 'monto' => float|null, 'error' => string|null]
     */
    private function validarMonto(mixed $monto): array
    {
        // Rechazar null, arrays, objects
        if ($monto === null || is_array($monto) || is_object($monto)) {
            return ['valid' => false, 'monto' => null, 'error' => 'Monto inválido'];
        }
        
        // Si es string, limpiar formato argentino (1.234,56 → 1234.56)
        if (is_string($monto)) {
            $monto = trim($monto);
            // Rechazar strings vacíos o con caracteres raros
            if ($monto === '' || preg_match('/[^0-9.,\-\s]/', $monto)) {
                return ['valid' => false, 'monto' => null, 'error' => 'Formato de monto inválido'];
            }
            // Formato argentino: quitar puntos de miles, coma → punto
            $monto = str_replace('.', '', $monto);
            $monto = str_replace(',', '.', $monto);
        }
        
        // Convertir a float
        $montoFloat = (float)$monto;
        
        // Rechazar NaN, Infinity
        if (!is_finite($montoFloat)) {
            return ['valid' => false, 'monto' => null, 'error' => 'Monto no es un número válido'];
        }
        
        // Rechazar <= 0
        if ($montoFloat <= 0) {
            return ['valid' => false, 'monto' => null, 'error' => 'El monto debe ser mayor a cero'];
        }
        
        // Rechazar montos absurdamente grandes (> 999 millones)
        if ($montoFloat > 999999999.99) {
            return ['valid' => false, 'monto' => null, 'error' => 'Monto excede el máximo permitido'];
        }
        
        // Normalizar a 2 decimales
        $montoNormalizado = round($montoFloat, 2);
        
        return ['valid' => true, 'monto' => $montoNormalizado, 'error' => null];
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // CONSULTAS
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Obtiene datos de cuenta corriente de un cliente
     */
    public function getClienteCC(int $clienteId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT c.id, c.nombre, c.cuit, c.telefono, c.email, c.direccion,
                   c.cc_habilitado, c.cc_limite, c.cc_saldo, c.cc_fecha_ultimo_pago,
                   (c.cc_limite - c.cc_saldo) AS cc_disponible
            FROM clientes c
            WHERE c.id = ?
            LIMIT 1
        ");
        $st->execute([$clienteId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Lista clientes con CC para el dashboard
     */
    public function listarClientesCC(array $filtros = []): array
    {
        $where = ['c.activo = 1', 'c.cc_habilitado = 1'];
        $params = [];
        
        if (!empty($filtros['q'])) {
            $where[] = '(c.nombre LIKE ? OR c.cuit LIKE ? OR c.telefono LIKE ?)';
            $params[] = '%' . $filtros['q'] . '%';
            $params[] = '%' . $filtros['q'] . '%';
            $params[] = '%' . $filtros['q'] . '%';
        }
        
        switch ($filtros['estado'] ?? '') {
            case 'excedidos':
                $where[] = 'c.cc_saldo > c.cc_limite';
                break;
            case 'morosos':
                $where[] = 'c.cc_saldo > 0';
                $where[] = 'c.cc_fecha_ultimo_pago < DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
                break;
            case 'con_deuda':
                $where[] = 'c.cc_saldo > 0';
                break;
            case 'al_dia':
                $where[] = 'c.cc_saldo <= 0';
                break;
        }
        
        $page = max(1, (int)($filtros['page'] ?? 1));
        $perPage = max(10, min(100, (int)($filtros['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;
        
        $orderBy = match($filtros['orden'] ?? 'saldo_desc') {
            'nombre' => 'c.nombre ASC',
            'saldo_asc' => 'c.cc_saldo ASC',
            'ultimo_pago' => 'c.cc_fecha_ultimo_pago ASC',
            default => 'c.cc_saldo DESC'
        };
        
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        
        // Contar total
        $stCount = $this->pdo->prepare("SELECT COUNT(*) FROM clientes c {$whereSql}");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();
        
        // Obtener lista
        $sql = "
            SELECT c.id, c.nombre, c.cuit, c.telefono,
                   c.cc_limite, c.cc_saldo, c.cc_fecha_ultimo_pago,
                   (c.cc_limite - c.cc_saldo) AS cc_disponible,
                   CASE 
                     WHEN c.cc_saldo > c.cc_limite THEN 'EXCEDIDO'
                     WHEN c.cc_saldo > 0 AND c.cc_fecha_ultimo_pago < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 'MOROSO'
                     WHEN c.cc_saldo > 0 THEN 'CON_DEUDA'
                     ELSE 'AL_DIA'
                   END AS estado_cc,
                   DATEDIFF(CURDATE(), c.cc_fecha_ultimo_pago) AS dias_sin_pago
            FROM clientes c
            {$whereSql}
            ORDER BY {$orderBy}
            LIMIT {$perPage} OFFSET {$offset}
        ";
        
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        
        return [
            'clientes' => $st->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pages' => (int)ceil($total / $perPage),
            'page' => $page,
        ];
    }
    
    /**
     * KPIs para el dashboard
     */
    public function getKPIs(): array
    {
        $st = $this->pdo->query("
            SELECT 
                COUNT(*) AS total_clientes_cc,
                COALESCE(SUM(cc_saldo), 0) AS total_deuda,
                SUM(CASE WHEN cc_saldo > cc_limite THEN 1 ELSE 0 END) AS excedidos,
                SUM(CASE WHEN cc_saldo > 0 AND cc_fecha_ultimo_pago < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS morosos,
                SUM(CASE WHEN cc_saldo > 0 THEN 1 ELSE 0 END) AS con_deuda
            FROM clientes
            WHERE activo = 1 AND cc_habilitado = 1
        ");
        return $st->fetch(PDO::FETCH_ASSOC) ?: [
            'total_clientes_cc' => 0, 'total_deuda' => 0, 
            'excedidos' => 0, 'morosos' => 0, 'con_deuda' => 0
        ];
    }
    
    /**
     * Obtener movimientos de un cliente
     */
    public function getMovimientos(int $clienteId, array $filtros = []): array
    {
        $where = ['m.cliente_id = ?'];
        $params = [$clienteId];
        
        if (empty($filtros['incluir_anulados'])) {
            $where[] = 'm.estado = ?';
            $params[] = self::ESTADO_ACTIVO;
        }
        
        if (!empty($filtros['tipo'])) {
            $where[] = 'm.tipo = ?';
            $params[] = $filtros['tipo'];
        }
        
        if (!empty($filtros['desde'])) {
            $where[] = 'm.created_at >= ?';
            $params[] = $filtros['desde'] . ' 00:00:00';
        }
        if (!empty($filtros['hasta'])) {
            $where[] = 'm.created_at <= ?';
            $params[] = $filtros['hasta'] . ' 23:59:59';
        }
        
        $page = max(1, (int)($filtros['page'] ?? 1));
        $perPage = max(10, min(100, (int)($filtros['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;
        
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        
        $stCount = $this->pdo->prepare("SELECT COUNT(*) FROM cuenta_corriente_movimientos m {$whereSql}");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();
        
        $sql = "
            SELECT m.*, u.username AS usuario_nombre
            FROM cuenta_corriente_movimientos m
            LEFT JOIN users u ON u.id = m.created_by
            {$whereSql}
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        
        return [
            'movimientos' => $st->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pages' => (int)ceil($total / $perPage),
            'page' => $page,
        ];
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // OPERACIONES (con transacciones y FOR UPDATE)
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Registra un CARGO (venta a cuenta corriente)
     * 
     * @param int $clienteId
     * @param float $monto Monto positivo
     * @param int $usuarioId Quién registra (obligatorio)
     * @param int|null $ventaId FK a ventas si aplica
     * @param string|null $concepto
     * @param int|null $autorizadoPor Si excedió límite, quién autorizó
     * @param array $extras [caja_id, terminal_id, ip]
     * @return array ['success' => bool, 'error' => string|null, ...]
     */
    public function registrarCargo(
        int $clienteId,
        float $monto,
        int $usuarioId,
        ?int $ventaId = null,
        ?string $concepto = null,
        ?int $autorizadoPor = null,
        array $extras = []
    ): array {
        // Validar y normalizar monto
        $validacion = $this->validarMonto($monto);
        if (!$validacion['valid']) {
            return ['success' => false, 'error' => $validacion['error']];
        }
        $monto = $validacion['monto'];
        
        if ($usuarioId <= 0) {
            return ['success' => false, 'error' => 'Usuario inválido'];
        }
        
        // ═══════════════════════════════════════════════════════════════
        // SOPORTE TRANSACCIÓN EXTERNA (para llamadas desde registrar_venta)
        // ═══════════════════════════════════════════════════════════════
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        
        try {
            // ═══════════════════════════════════════════════════════════════
            // BLOQUEAR FILA DEL CLIENTE (FOR UPDATE) - evita race conditions
            // ═══════════════════════════════════════════════════════════════
            $stLock = $this->pdo->prepare("
                SELECT cc_habilitado, cc_limite, cc_saldo 
                FROM clientes 
                WHERE id = ? 
                FOR UPDATE
            ");
            $stLock->execute([$clienteId]);
            $cliente = $stLock->fetch(PDO::FETCH_ASSOC);
            
            if (!$cliente) {
                if ($ownTransaction) $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Cliente no encontrado'];
            }
            
            if (!$cliente['cc_habilitado']) {
                if ($ownTransaction) $this->pdo->rollBack();
                return ['success' => false, 'error' => 'El cliente no tiene cuenta corriente habilitada'];
            }
            
            $saldoAnterior = (float)$cliente['cc_saldo'];
            $limite = (float)$cliente['cc_limite'];
            $saldoPosterior = $saldoAnterior + $monto;
            $excede = $saldoPosterior > $limite;
            
            // ═══════════════════════════════════════════════════════════════
            // VALIDAR LÍMITE (estricto por defecto)
            // ═══════════════════════════════════════════════════════════════
            if ($excede && $autorizadoPor === null) {
                if ($ownTransaction) $this->pdo->rollBack();
                $disponible = max(0, $limite - $saldoAnterior);
                return [
                    'success' => false,
                    'error' => "Excede el límite de crédito. Disponible: $" . number_format($disponible, 2, ',', '.'),
                    'excede_limite' => true,
                    'disponible' => $disponible,
                    'requiere_autorizacion' => true,
                ];
            }
            
            // ═══════════════════════════════════════════════════════════════
            // INSERTAR MOVIMIENTO
            // ═══════════════════════════════════════════════════════════════
            $stMov = $this->pdo->prepare("
                INSERT INTO cuenta_corriente_movimientos 
                  (cliente_id, tipo, estado, monto, saldo_anterior, saldo_posterior,
                   venta_id, concepto, created_by, autorizado_por, caja_id, terminal_id, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stMov->execute([
                $clienteId,
                self::TIPO_CARGO,
                self::ESTADO_ACTIVO,
                $monto,
                $saldoAnterior,
                $saldoPosterior,
                $ventaId,
                $concepto ?? ($ventaId ? "Venta #$ventaId" : 'Cargo a cuenta'),
                $usuarioId,
                $autorizadoPor,
                $extras['caja_id'] ?? null,
                $extras['terminal_id'] ?? null,
                $extras['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            ]);
            
            $movimientoId = (int)$this->pdo->lastInsertId();
            
            // ═══════════════════════════════════════════════════════════════
            // ACTUALIZAR CACHE DEL CLIENTE
            // ═══════════════════════════════════════════════════════════════
            $stUpd = $this->pdo->prepare("UPDATE clientes SET cc_saldo = ? WHERE id = ?");
            $stUpd->execute([$saldoPosterior, $clienteId]);
            
            if ($ownTransaction) {
                $this->pdo->commit();
            }
            
            return [
                'success' => true,
                'movimiento_id' => $movimientoId,
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoPosterior,
                'excede_limite' => $excede,
            ];
            
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("CuentaCorrienteController::registrarCargo ERROR: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error interno al registrar cargo'];
        }
    }
    
    /**
     * Registra un PAGO del cliente (reduce su deuda)
     * 
     * @param int $clienteId
     * @param float $monto Monto positivo a pagar
     * @param string $medioPago EFECTIVO, TRANSFERENCIA, MP, DEBITO, CREDITO, CHEQUE
     * @param int $usuarioId Quién registra
     * @param string|null $referencia Número de transferencia, etc.
     * @param string|null $concepto
     * @param array $extras [caja_id, terminal_id, ip, registrar_caja_mov, usuario_nombre]
     * @return array ['success' => bool, 'error' => string|null, ...]
     */
    public function registrarPago(
        int $clienteId,
        float $monto,
        string $medioPago,
        int $usuarioId,
        ?string $referencia = null,
        ?string $concepto = null,
        array $extras = []
    ): array {
        // Validar y normalizar monto
        $validacion = $this->validarMonto($monto);
        if (!$validacion['valid']) {
            return ['success' => false, 'error' => $validacion['error']];
        }
        $monto = $validacion['monto'];
        
        if (!array_key_exists($medioPago, self::MEDIOS_PAGO)) {
            return ['success' => false, 'error' => 'Medio de pago inválido'];
        }
        
        // ═══════════════════════════════════════════════════════════════
        // SOPORTE TRANSACCIÓN EXTERNA (para llamadas desde otros procesos)
        // ═══════════════════════════════════════════════════════════════
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        
        try {
            // Bloquear cliente
            $stLock = $this->pdo->prepare("
                SELECT cc_habilitado, cc_saldo, nombre FROM clientes WHERE id = ? FOR UPDATE
            ");
            $stLock->execute([$clienteId]);
            $cliente = $stLock->fetch(PDO::FETCH_ASSOC);
            
            if (!$cliente) {
                if ($ownTransaction) $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Cliente no encontrado'];
            }
            
            $saldoAnterior = (float)$cliente['cc_saldo'];
            
            // Validar que no se pague más de lo que debe (opcional: permitir saldo a favor)
            // Por ahora: NO permitimos sobrepago
            if ($monto > $saldoAnterior + 0.01) {
                if ($ownTransaction) $this->pdo->rollBack();
                return [
                    'success' => false, 
                    'error' => 'El monto excede la deuda actual ($' . number_format($saldoAnterior, 2, ',', '.') . ')'
                ];
            }
            
            $saldoPosterior = $saldoAnterior - $monto;
            
            // Insertar movimiento CC
            $stMov = $this->pdo->prepare("
                INSERT INTO cuenta_corriente_movimientos 
                  (cliente_id, tipo, estado, monto, saldo_anterior, saldo_posterior,
                   medio_pago, referencia, concepto, created_by, caja_id, terminal_id, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $cajaId = (int)($extras['caja_id'] ?? 0) ?: null;
            $terminalId = (int)($extras['terminal_id'] ?? 0) ?: null;
            
            $stMov->execute([
                $clienteId,
                self::TIPO_PAGO,
                self::ESTADO_ACTIVO,
                $monto,
                $saldoAnterior,
                $saldoPosterior,
                $medioPago,
                $referencia,
                $concepto ?? 'Pago de cuenta',
                $usuarioId,
                $cajaId,
                $terminalId,
                $extras['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            ]);
            
            $movimientoId = (int)$this->pdo->lastInsertId();
            
            // ═══════════════════════════════════════════════════════════════
            // REGISTRAR MOVIMIENTO DE CAJA (cuando se cobra desde Caja)
            // ═══════════════════════════════════════════════════════════════
            $cajaMovId = null;
            
            if (!empty($extras['registrar_caja_mov']) && $cajaId > 0) {
                $usrName = (string)($extras['usuario_nombre'] ?? ('user#' . $usuarioId));
                $usrName = mb_substr($usrName, 0, 100);
                $cliName = (string)($cliente['nombre'] ?? '');
                $cliName = mb_substr($cliName, 0, 80);
                
                $conceptoCaja = "Cobro CC";
                if ($cliName !== '') $conceptoCaja .= " - {$cliName}";
                $conceptoCaja .= " (#{$clienteId})";
                if ($referencia) $conceptoCaja .= " Ref: " . mb_substr($referencia, 0, 40);
                
                // Verificar si existe columna medio_pago (compatibilidad)
                $hasMedioPagoCol = false;
                try {
                    $hasMedioPagoCol = $this->hasColumn('caja_movimientos', 'medio_pago');
                } catch (Throwable $e) {
                    // Ignorar
                }
                
                // Insertar en caja_movimientos CON medio_pago para arqueo correcto
                if ($hasMedioPagoCol) {
                    $stCaja = $this->pdo->prepare("
                        INSERT INTO caja_movimientos (caja_id, tipo, medio_pago, concepto, monto, usuario_registro, cc_movimiento_id)
                        VALUES (?, 'ingreso', ?, ?, ?, ?, ?)
                    ");
                    $stCaja->execute([$cajaId, $medioPago, $conceptoCaja, $monto, $usrName, $movimientoId]);
                } else {
                    // Fallback para instalaciones sin la columna
                    $stCaja = $this->pdo->prepare("
                        INSERT INTO caja_movimientos (caja_id, tipo, concepto, monto, usuario_registro, cc_movimiento_id)
                        VALUES (?, 'ingreso', ?, ?, ?, ?)
                    ");
                    $stCaja->execute([$cajaId, $conceptoCaja, $monto, $usrName, $movimientoId]);
                }
                $cajaMovId = (int)$this->pdo->lastInsertId();
                
                // Actualizar referencia en el movimiento CC
                $stUpdRef = $this->pdo->prepare("
                    UPDATE cuenta_corriente_movimientos SET caja_movimiento_id = ? WHERE id = ?
                ");
                $stUpdRef->execute([$cajaMovId, $movimientoId]);
                
                // Actualizar totales de caja_sesiones según el medio de pago
                $this->actualizarTotalesCaja($cajaId, $medioPago, $monto);
            }
            
            // Actualizar cliente
            $stUpd = $this->pdo->prepare("
                UPDATE clientes SET cc_saldo = ?, cc_fecha_ultimo_pago = CURDATE() WHERE id = ?
            ");
            $stUpd->execute([$saldoPosterior, $clienteId]);
            
            if ($ownTransaction) {
                $this->pdo->commit();
            }
            
            return [
                'success' => true,
                'movimiento_id' => $movimientoId,
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoPosterior,
                'caja_movimiento_id' => $cajaMovId,
            ];
            
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("CuentaCorrienteController::registrarPago ERROR: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error interno al registrar pago'];
        }
    }
    
    /**
     * Actualiza totales de caja_sesiones según medio de pago
     * @internal
     */
    private function actualizarTotalesCaja(int $cajaId, string $medioPago, float $monto): void
    {
        $m = strtoupper(trim($medioPago));
        
        // Mapeo estricto: cada medio a su columna correspondiente
        // CRÍTICO: TRANSFERENCIA NO debe ir a total_efectivo
        switch ($m) {
            case 'EFECTIVO':
                $campo = 'total_efectivo';
                break;
            case 'MP':
            case 'MERCADOPAGO':
                $campo = 'total_mp';
                break;
            case 'DEBITO':
                $campo = 'total_debito';
                break;
            case 'CREDITO':
                $campo = 'total_credito';
                break;
            case 'TRANSFERENCIA':
            case 'TRANSFER':
                $campo = 'total_transferencia';
                break;
            default:
                // Medio no reconocido - loggear y no actualizar
                error_log("actualizarTotalesCaja: Medio de pago no soportado '{$medioPago}'");
                return;
        }
        
        // Verificar que la columna existe (compatibilidad instalaciones viejas)
        try {
            if ($this->hasColumn('caja_sesiones', $campo)) {
                $sql = "UPDATE caja_sesiones SET {$campo} = COALESCE({$campo}, 0) + ? WHERE id = ?";
                $st = $this->pdo->prepare($sql);
                $st->execute([$monto, $cajaId]);
            } else {
                error_log("actualizarTotalesCaja: columna {$campo} no existe en caja_sesiones");
            }
        } catch (Throwable $e) {
            error_log("actualizarTotalesCaja: Error actualizando {$campo}: " . $e->getMessage());
        }
    }
    
    /**
     * Registra un AJUSTE manual
     */
    public function registrarAjuste(
        int $clienteId,
        float $monto,
        bool $aumentaDeuda,
        string $concepto,
        int $usuarioId,
        ?string $referencia = null,
        array $extras = []
    ): array {
        // Validar y normalizar monto
        $validacion = $this->validarMonto($monto);
        if (!$validacion['valid']) {
            return ['success' => false, 'error' => $validacion['error']];
        }
        $monto = $validacion['monto'];
        
        if (trim($concepto) === '') {
            return ['success' => false, 'error' => 'El concepto es obligatorio para ajustes'];
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stLock = $this->pdo->prepare("SELECT cc_saldo FROM clientes WHERE id = ? FOR UPDATE");
            $stLock->execute([$clienteId]);
            $cliente = $stLock->fetch(PDO::FETCH_ASSOC);
            
            if (!$cliente) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Cliente no encontrado'];
            }
            
            $saldoAnterior = (float)$cliente['cc_saldo'];
            $saldoPosterior = $aumentaDeuda 
                ? $saldoAnterior + $monto 
                : $saldoAnterior - $monto;
            
            $tipo = $aumentaDeuda ? self::TIPO_AJUSTE_POS : self::TIPO_AJUSTE_NEG;
            
            $stMov = $this->pdo->prepare("
                INSERT INTO cuenta_corriente_movimientos 
                  (cliente_id, tipo, estado, monto, saldo_anterior, saldo_posterior,
                   referencia, concepto, created_by, caja_id, terminal_id, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stMov->execute([
                $clienteId,
                $tipo,
                self::ESTADO_ACTIVO,
                $monto,
                $saldoAnterior,
                $saldoPosterior,
                $referencia,
                $concepto,
                $usuarioId,
                $extras['caja_id'] ?? null,
                $extras['terminal_id'] ?? null,
                $extras['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            ]);
            
            $movimientoId = (int)$this->pdo->lastInsertId();
            
            $stUpd = $this->pdo->prepare("UPDATE clientes SET cc_saldo = ? WHERE id = ?");
            $stUpd->execute([$saldoPosterior, $clienteId]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'movimiento_id' => $movimientoId,
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoPosterior,
            ];
            
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log("CuentaCorrienteController::registrarAjuste ERROR: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error interno al registrar ajuste'];
        }
    }
    
    /**
     * REVERSAR un movimiento (anular sin editar historial)
     * Crea un movimiento de tipo REVERSA que enlaza al original
     */
    public function reversarMovimiento(
        int $movimientoId,
        string $motivo,
        int $usuarioId,
        array $extras = []
    ): array {
        if (trim($motivo) === '') {
            return ['success' => false, 'error' => 'El motivo es obligatorio'];
        }
        
        $this->pdo->beginTransaction();
        
        try {
            // Obtener movimiento original
            $stMov = $this->pdo->prepare("
                SELECT * FROM cuenta_corriente_movimientos WHERE id = ? FOR UPDATE
            ");
            $stMov->execute([$movimientoId]);
            $movOriginal = $stMov->fetch(PDO::FETCH_ASSOC);
            
            if (!$movOriginal) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Movimiento no encontrado'];
            }
            
            if ($movOriginal['estado'] !== self::ESTADO_ACTIVO) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'El movimiento ya está anulado'];
            }
            
            if ($movOriginal['tipo'] === self::TIPO_REVERSA) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'No se puede reversar una reversa'];
            }
            
            // Verificar si ya tiene reversa
            $stCheck = $this->pdo->prepare("
                SELECT id FROM cuenta_corriente_movimientos 
                WHERE reversa_de_id = ? AND estado = ?
            ");
            $stCheck->execute([$movimientoId, self::ESTADO_ACTIVO]);
            if ($stCheck->fetch()) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Este movimiento ya fue reversado'];
            }
            
            $clienteId = (int)$movOriginal['cliente_id'];
            $montoOriginal = (float)$movOriginal['monto'];
            $tipoOriginal = $movOriginal['tipo'];
            
            // Bloquear cliente
            $stLock = $this->pdo->prepare("SELECT cc_saldo FROM clientes WHERE id = ? FOR UPDATE");
            $stLock->execute([$clienteId]);
            $cliente = $stLock->fetch(PDO::FETCH_ASSOC);
            
            $saldoAnterior = (float)$cliente['cc_saldo'];
            
            // Calcular saldo (reversa hace lo contrario)
            $saldoPosterior = match($tipoOriginal) {
                self::TIPO_CARGO, self::TIPO_AJUSTE_POS => $saldoAnterior - $montoOriginal,
                self::TIPO_PAGO, self::TIPO_AJUSTE_NEG => $saldoAnterior + $montoOriginal,
                default => $saldoAnterior
            };
            
            // Insertar reversa
            $stIns = $this->pdo->prepare("
                INSERT INTO cuenta_corriente_movimientos 
                  (cliente_id, tipo, estado, monto, saldo_anterior, saldo_posterior,
                   reversa_de_id, concepto, created_by, caja_id, terminal_id, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stIns->execute([
                $clienteId,
                self::TIPO_REVERSA,
                self::ESTADO_ACTIVO,
                $montoOriginal,
                $saldoAnterior,
                $saldoPosterior,
                $movimientoId,
                "REVERSA: $motivo",
                $usuarioId,
                $extras['caja_id'] ?? null,
                $extras['terminal_id'] ?? null,
                $extras['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            ]);
            
            $reversaId = (int)$this->pdo->lastInsertId();
            
            // Marcar original como anulado
            $stMark = $this->pdo->prepare("
                UPDATE cuenta_corriente_movimientos SET estado = ?, updated_at = NOW() WHERE id = ?
            ");
            $stMark->execute([self::ESTADO_ANULADO, $movimientoId]);
            
            // Actualizar cliente
            $stUpd = $this->pdo->prepare("UPDATE clientes SET cc_saldo = ? WHERE id = ?");
            $stUpd->execute([$saldoPosterior, $clienteId]);
            
            // Si se reversó un PAGO, recalcular cc_fecha_ultimo_pago
            if ($tipoOriginal === self::TIPO_PAGO) {
                $stFecha = $this->pdo->prepare("
                    SELECT MAX(DATE(created_at)) 
                    FROM cuenta_corriente_movimientos 
                    WHERE cliente_id = ? AND tipo = ? AND estado = ?
                ");
                $stFecha->execute([$clienteId, self::TIPO_PAGO, self::ESTADO_ACTIVO]);
                $nuevaFecha = $stFecha->fetchColumn() ?: null;
                
                $stUpdFecha = $this->pdo->prepare("UPDATE clientes SET cc_fecha_ultimo_pago = ? WHERE id = ?");
                $stUpdFecha->execute([$nuevaFecha, $clienteId]);
            }
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'reversa_id' => $reversaId,
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoPosterior,
            ];
            
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log("CuentaCorrienteController::reversarMovimiento ERROR: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error interno al reversar'];
        }
    }
    
    /**
     * Recalcular saldo de un cliente desde movimientos (corregir inconsistencias)
     */
    public function recalcularSaldo(int $clienteId): array
    {
        try {
            $sql = "
                SELECT COALESCE(SUM(
                    CASE 
                        WHEN tipo IN ('CARGO', 'AJUSTE_POS') THEN monto
                        WHEN tipo IN ('PAGO', 'AJUSTE_NEG', 'REVERSA') THEN -monto
                        ELSE 0
                    END
                ), 0) AS saldo_calculado,
                MAX(CASE WHEN tipo = 'PAGO' THEN DATE(created_at) END) AS ultimo_pago
                FROM cuenta_corriente_movimientos
                WHERE cliente_id = ? AND estado = ?
            ";
            
            $st = $this->pdo->prepare($sql);
            $st->execute([$clienteId, self::ESTADO_ACTIVO]);
            $result = $st->fetch(PDO::FETCH_ASSOC);
            
            $saldoCalculado = (float)($result['saldo_calculado'] ?? 0);
            $ultimoPago = $result['ultimo_pago'];
            
            // Obtener saldo actual
            $stCli = $this->pdo->prepare("SELECT cc_saldo FROM clientes WHERE id = ?");
            $stCli->execute([$clienteId]);
            $saldoActual = (float)$stCli->fetchColumn();
            
            $diferencia = abs($saldoCalculado - $saldoActual);
            $habiaDiferencia = $diferencia > 0.01;
            
            // Actualizar si hay diferencia
            if ($habiaDiferencia) {
                $stUpd = $this->pdo->prepare("
                    UPDATE clientes SET cc_saldo = ?, cc_fecha_ultimo_pago = ? WHERE id = ?
                ");
                $stUpd->execute([$saldoCalculado, $ultimoPago, $clienteId]);
            }
            
            return [
                'success' => true,
                'saldo_anterior' => $saldoActual,
                'saldo_calculado' => $saldoCalculado,
                'diferencia' => $diferencia,
                'corregido' => $habiaDiferencia,
            ];
            
        } catch (Throwable $e) {
            error_log("CuentaCorrienteController::recalcularSaldo ERROR: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error al recalcular'];
        }
    }
    
    /**
     * Verificar disponibilidad de crédito para una venta
     */
    public function verificarDisponibilidad(int $clienteId, float $monto): array
    {
        $cliente = $this->getClienteCC($clienteId);
        
        if (!$cliente) {
            return ['ok' => false, 'error' => 'Cliente no encontrado'];
        }
        if (!$cliente['cc_habilitado']) {
            return ['ok' => false, 'error' => 'Cliente sin cuenta corriente'];
        }
        
        $saldo = (float)$cliente['cc_saldo'];
        $limite = (float)$cliente['cc_limite'];
        $disponible = $limite - $saldo;
        $excede = ($saldo + $monto) > $limite;
        
        return [
            'ok' => !$excede,
            'saldo_actual' => $saldo,
            'limite' => $limite,
            'disponible' => $disponible,
            'monto_solicitado' => $monto,
            'excede' => $excede,
        ];
    }
    
    /**
     * Habilitar CC a un cliente
     */
    public function habilitarCC(int $clienteId, float $limite): array
    {
        if ($limite < 0) {
            return ['success' => false, 'error' => 'El límite no puede ser negativo'];
        }
        
        try {
            $st = $this->pdo->prepare("
                UPDATE clientes SET cc_habilitado = 1, cc_limite = ? WHERE id = ?
            ");
            $st->execute([$limite, $clienteId]);
            return ['success' => $st->rowCount() > 0];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Actualizar límite de crédito
     */
    public function actualizarLimite(int $clienteId, float $nuevoLimite): array
    {
        if ($nuevoLimite < 0) {
            return ['success' => false, 'error' => 'El límite no puede ser negativo'];
        }
        
        try {
            $st = $this->pdo->prepare("
                UPDATE clientes SET cc_limite = ? WHERE id = ? AND cc_habilitado = 1
            ");
            $st->execute([$nuevoLimite, $clienteId]);
            return ['success' => $st->rowCount() > 0];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
