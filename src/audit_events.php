<?php
// src/audit_events.php
declare(strict_types=1);

/**
 * FLUS Audit Events System
 * Sistema de auditoría de alto valor con eventos estandarizados
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

// ============================================
// ESTÁNDAR DE EVENTOS DE AUDITORÍA
// ============================================

/**
 * Eventos estándar del sistema
 */
class AuditEvents {
    // Ventas
    public const VENTA_CREATE = 'VENTA_CREATE';
    public const VENTA_VOID = 'VENTA_VOID';
    public const VENTA_MODIFY = 'VENTA_MODIFY';
    public const VENTA_DELETE = 'VENTA_DELETE';
    
    // Stock
    public const STOCK_ADJUST = 'STOCK_ADJUST';
    public const STOCK_TRANSFER = 'STOCK_TRANSFER';
    public const STOCK_COUNT = 'STOCK_COUNT';
    
    // Compras
    public const COMPRA_CREATE = 'COMPRA_CREATE';
    public const COMPRA_VOID = 'COMPRA_VOID';
    public const COMPRA_RECEIVE = 'COMPRA_RECEIVE';
    
    // Productos
    public const PRODUCTO_CREATE = 'PRODUCTO_CREATE';
    public const PRODUCTO_UPDATE = 'PRODUCTO_UPDATE';
    public const PRODUCTO_DELETE = 'PRODUCTO_DELETE';
    public const PRODUCTO_PRECIO_CHANGE = 'PRODUCTO_PRECIO_CHANGE';
    
    // Caja
    public const CAJA_OPEN = 'CAJA_OPEN';
    public const CAJA_CLOSE = 'CAJA_CLOSE';
    public const CAJA_ADJUST = 'CAJA_ADJUST';
    public const CAJA_MOVEMENT = 'CAJA_MOVEMENT';
    
    // Usuarios y Auth
    public const USER_LOGIN = 'USER_LOGIN';
    public const USER_LOGOUT = 'USER_LOGOUT';
    public const USER_LOGIN_FAILED = 'USER_LOGIN_FAILED';
    public const USER_CREATE = 'USER_CREATE';
    public const USER_UPDATE = 'USER_UPDATE';
    public const USER_DELETE = 'USER_DELETE';
    public const USER_PASSWORD_CHANGE = 'USER_PASSWORD_CHANGE';
    public const USER_PERMISSION_CHANGE = 'USER_PERMISSION_CHANGE';
    
    // Sistema y Backups
    public const BACKUP_CREATE = 'BACKUP_CREATE';
    public const BACKUP_RESTORE = 'BACKUP_RESTORE';
    public const BACKUP_DELETE = 'BACKUP_DELETE';
    public const SYSTEM_CONFIG_CHANGE = 'SYSTEM_CONFIG_CHANGE';
    public const MAINTENANCE_ON = 'MAINTENANCE_ON';
    public const MAINTENANCE_OFF = 'MAINTENANCE_OFF';
    
    // Clientes y CC
    public const CLIENTE_CREATE = 'CLIENTE_CREATE';
    public const CLIENTE_UPDATE = 'CLIENTE_UPDATE';
    public const CLIENTE_DELETE = 'CLIENTE_DELETE';
    public const CC_CARGO = 'CC_CARGO';
    public const CC_PAGO = 'CC_PAGO';
    public const CC_AJUSTE = 'CC_AJUSTE';
    
    // Inventario Físico
    public const INVENTARIO_SESSION_CREATE = 'INVENTARIO_SESSION_CREATE';
    public const INVENTARIO_SESSION_CLOSE = 'INVENTARIO_SESSION_CLOSE';
    public const INVENTARIO_COUNT = 'INVENTARIO_COUNT';
    public const INVENTARIO_ADJUST = 'INVENTARIO_ADJUST';
}

/**
 * Entidades del sistema
 */
class AuditEntities {
    public const VENTA = 'VENTA';
    public const PRODUCTO = 'PRODUCTO';
    public const COMPRA = 'COMPRA';
    public const STOCK = 'STOCK';
    public const CAJA = 'CAJA';
    public const USER = 'USER';
    public const BACKUP = 'BACKUP';
    public const SYSTEM = 'SYSTEM';
    public const CLIENTE = 'CLIENTE';
    public const CC = 'CUENTA_CORRIENTE';
    public const INVENTARIO = 'INVENTARIO';
    public const TERMINAL = 'TERMINAL';
    public const PROMO = 'PROMO';
}

// ============================================
// FUNCIONES DE AUDITORÍA
// ============================================

/**
 * Registrar evento de auditoría
 * 
 * @param string $action Acción (usar constantes de AuditEvents)
 * @param string $entity Entidad (usar constantes de AuditEntities)
 * @param int|null $entityId ID de la entidad
 * @param array $meta Metadata adicional
 * @param int|null $userId ID del usuario (null = sistema)
 */
function audit_event(string $action, string $entity, ?int $entityId = null, array $meta = [], ?int $userId = null): void {
    try {
        $pdo = getPDO();
        
        // Obtener user_id de sesión si no se proporciona
        if ($userId === null) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        }

        // Agregar contexto automático
        $meta['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
        $meta['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : null;
        $meta['terminal_id'] = $_SESSION['terminal_id'] ?? null;
        $meta['request_uri'] = isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 0, 200) : null;

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // Verificar estructura de la tabla
        static $tableInfo = null;
        if ($tableInfo === null) {
            $tableInfo = audit_get_table_info($pdo);
        }

        if (!$tableInfo['exists']) {
            flus_log_error('audit_event skipped: missing audit_log table', [
                'action' => $action,
                'entity' => $entity,
                'migration' => 'install.sql',
            ]);
            return;
        }

        // Construir INSERT dinámico
        $cols = ['action', 'entity'];
        $vals = [':action', ':entity'];
        $params = [
            ':action' => substr($action, 0, 50),
            ':entity' => substr($entity, 0, 50),
        ];

        if ($tableInfo['has_user_id']) {
            $cols[] = 'user_id';
            $vals[] = ':user_id';
            $params[':user_id'] = $userId;
        }

        if ($tableInfo['has_entity_id']) {
            $cols[] = 'entity_id';
            $vals[] = ':entity_id';
            $params[':entity_id'] = $entityId;
        }

        if ($tableInfo['meta_column']) {
            $cols[] = $tableInfo['meta_column'];
            $vals[] = ':meta';
            $params[':meta'] = $metaJson;
        }

        if ($tableInfo['has_ip']) {
            $cols[] = 'ip';
            $vals[] = ':ip';
            $params[':ip'] = $meta['ip'] ? substr($meta['ip'], 0, 45) : null;
        }

        $sql = sprintf(
            'INSERT INTO audit_log (%s) VALUES (%s)',
            implode(', ', $cols),
            implode(', ', $vals)
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

    } catch (Throwable $e) {
        // No romper el flujo por auditoría
        flus_log_error('audit_event failed', [
            'action' => $action,
            'entity' => $entity,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * Obtener información de la tabla audit_log
 */
function audit_get_table_info(PDO $pdo): array {
    $info = [
        'exists' => false,
        'has_user_id' => false,
        'has_entity_id' => false,
        'has_ip' => false,
        'meta_column' => null,
    ];

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'audit_log'");
        if (!$stmt || !$stmt->fetchColumn()) {
            return $info;
        }
        
        $info['exists'] = true;

        $cols = $pdo->query("SHOW COLUMNS FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
        
        $info['has_user_id'] = in_array('user_id', $cols);
        $info['has_entity_id'] = in_array('entity_id', $cols);
        $info['has_ip'] = in_array('ip', $cols);
        
        if (in_array('meta', $cols)) {
            $info['meta_column'] = 'meta';
        } elseif (in_array('meta_json', $cols)) {
            $info['meta_column'] = 'meta_json';
        }

    } catch (Throwable $e) {
        // Tabla no existe o error de conexión
    }

    return $info;
}

/**
 * Compat helper: el esquema de audit_log debe venir desde install.sql/migraciones.
 */
function audit_ensure_table(PDO $pdo): bool {
    $info = audit_get_table_info($pdo);
    if ($info['exists']) {
        return true;
    }

    flus_log_error('audit_ensure_table skipped: schema must be provisioned externally', [
        'migration' => 'install.sql',
    ]);
    return false;
}

/**
 * Rotación de logs de auditoría
 * Elimina registros más antiguos que $days días
 */
function audit_rotate(int $days = 90): int {
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->prepare("
            DELETE FROM audit_log 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        
        $deleted = $stmt->rowCount();
        
        if ($deleted > 0) {
            flus_log_info('Audit log rotated', [
                'deleted_rows' => $deleted,
                'retention_days' => $days,
            ]);
        }
        
        return $deleted;
        
    } catch (Throwable $e) {
        flus_log_error('audit_rotate failed', ['error' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Obtener eventos de auditoría filtrados
 */
function audit_query(array $filters = [], int $limit = 100, int $offset = 0): array {
    try {
        $pdo = getPDO();
        
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }

        if (!empty($filters['entity'])) {
            $where[] = 'entity = :entity';
            $params[':entity'] = $filters['entity'];
        }

        if (!empty($filters['entity_id'])) {
            $where[] = 'entity_id = :entity_id';
            $params[':entity_id'] = (int)$filters['entity_id'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = (int)$filters['user_id'];
        }

        if (!empty($filters['from_date'])) {
            $where[] = 'created_at >= :from_date';
            $params[':from_date'] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $where[] = 'created_at <= :to_date';
            $params[':to_date'] = $filters['to_date'];
        }

        $sql = sprintf(
            "SELECT al.*, u.nombre as user_nombre
             FROM audit_log al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE %s
             ORDER BY al.created_at DESC
             LIMIT %d OFFSET %d",
            implode(' AND ', $where),
            $limit,
            $offset
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parsear meta JSON
        foreach ($rows as &$row) {
            if (isset($row['meta']) && is_string($row['meta'])) {
                $row['meta'] = json_decode($row['meta'], true);
            }
            if (isset($row['meta_json']) && is_string($row['meta_json'])) {
                $row['meta'] = json_decode($row['meta_json'], true);
                unset($row['meta_json']);
            }
        }
        
        return $rows;

    } catch (Throwable $e) {
        flus_log_error('audit_query failed', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Contar eventos de auditoría
 */
function audit_count(array $filters = []): int {
    try {
        $pdo = getPDO();
        
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }

        if (!empty($filters['entity'])) {
            $where[] = 'entity = :entity';
            $params[':entity'] = $filters['entity'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = (int)$filters['user_id'];
        }

        $sql = sprintf(
            "SELECT COUNT(*) FROM audit_log WHERE %s",
            implode(' AND ', $where)
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn();

    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Resumen de actividad de auditoría
 */
function audit_summary(int $days = 7): array {
    try {
        $pdo = getPDO();
        
        // Eventos por tipo
        $stmt = $pdo->prepare("
            SELECT action, COUNT(*) as count
            FROM audit_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY action
            ORDER BY count DESC
        ");
        $stmt->execute([$days]);
        $byAction = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Eventos por entidad
        $stmt = $pdo->prepare("
            SELECT entity, COUNT(*) as count
            FROM audit_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY entity
            ORDER BY count DESC
        ");
        $stmt->execute([$days]);
        $byEntity = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Eventos por usuario
        $stmt = $pdo->prepare("
            SELECT COALESCE(u.nombre, 'Sistema') as user_name, COUNT(*) as count
            FROM audit_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY al.user_id, u.nombre
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute([$days]);
        $byUser = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Total
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM audit_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        $total = (int)$stmt->fetchColumn();

        return [
            'period_days' => $days,
            'total_events' => $total,
            'by_action' => $byAction,
            'by_entity' => $byEntity,
            'by_user' => $byUser,
        ];

    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
            'period_days' => $days,
            'total_events' => 0,
        ];
    }
}

// ============================================
// HELPERS DE AUDITORÍA POR MÓDULO
// ============================================

/**
 * Auditar venta
 */
function audit_venta(string $action, int $ventaId, array $extra = []): void {
    $meta = array_merge([
        'venta_id' => $ventaId,
    ], $extra);
    
    audit_event($action, AuditEntities::VENTA, $ventaId, $meta);
}

/**
 * Auditar producto
 */
function audit_producto(string $action, int $productoId, array $extra = []): void {
    $meta = array_merge([
        'producto_id' => $productoId,
    ], $extra);
    
    audit_event($action, AuditEntities::PRODUCTO, $productoId, $meta);
}

/**
 * Auditar cambio de precio
 */
function audit_precio_change(int $productoId, float $precioAnterior, float $precioNuevo, ?string $motivo = null): void {
    audit_event(
        AuditEvents::PRODUCTO_PRECIO_CHANGE,
        AuditEntities::PRODUCTO,
        $productoId,
        [
            'precio_anterior' => $precioAnterior,
            'precio_nuevo' => $precioNuevo,
            'diferencia' => round($precioNuevo - $precioAnterior, 2),
            'diferencia_pct' => $precioAnterior > 0 ? round((($precioNuevo - $precioAnterior) / $precioAnterior) * 100, 2) : null,
            'motivo' => $motivo,
        ]
    );
}

/**
 * Auditar ajuste de stock
 */
function audit_stock_adjust(int $productoId, float $cantAnterior, float $cantNueva, string $motivo): void {
    audit_event(
        AuditEvents::STOCK_ADJUST,
        AuditEntities::STOCK,
        $productoId,
        [
            'producto_id' => $productoId,
            'cantidad_anterior' => $cantAnterior,
            'cantidad_nueva' => $cantNueva,
            'diferencia' => round($cantNueva - $cantAnterior, 2),
            'motivo' => $motivo,
        ]
    );
}

/**
 * Auditar operación de caja
 */
function audit_caja(string $action, int $cajaId, array $extra = []): void {
    $meta = array_merge([
        'caja_id' => $cajaId,
    ], $extra);
    
    audit_event($action, AuditEntities::CAJA, $cajaId, $meta);
}

/**
 * Auditar operación de backup
 */
function audit_backup(string $action, array $extra = []): void {
    audit_event($action, AuditEntities::BACKUP, null, $extra);
}

/**
 * Auditar login/logout
 */
function audit_auth(string $action, ?int $userId = null, array $extra = []): void {
    audit_event($action, AuditEntities::USER, $userId, $extra, $userId);
}
