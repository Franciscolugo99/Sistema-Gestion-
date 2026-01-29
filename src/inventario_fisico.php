<?php
// src/inventario_fisico.php
declare(strict_types=1);

/**
 * FLUS Inventario Físico
 * - Sesiones de conteo
 * - Registro de conteos por producto
 * - Resumen de diferencias vs stock del sistema
 * - Aplicación de ajustes (movimientos_stock + actualización de productos.stock)
 *
 * Nota: diseño simple y seguro para integrar sin romper instalaciones existentes.
 *
 * @version 1.0.0
 */

if (defined('FLUS_INVENTARIO_FISICO_LOADED')) return;
define('FLUS_INVENTARIO_FISICO_LOADED', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/audit_events.php';

function inventario_ensure_tables(): void {
    $pdo = getPDO();

    // Sesiones
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventario_sesiones (
            id INT(11) NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(120) NOT NULL,
            descripcion VARCHAR(255) NULL,
            estado ENUM('ABIERTA','CERRADA','APLICADA') NOT NULL DEFAULT 'ABIERTA',
            created_by INT(11) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_by INT(11) NULL,
            closed_at DATETIME NULL,
            cierre_motivo VARCHAR(255) NULL,
            applied_by INT(11) NULL,
            applied_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_estado (estado),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Conteos
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventario_conteos (
            id INT(11) NOT NULL AUTO_INCREMENT,
            sesion_id INT(11) NOT NULL,
            producto_id INT(11) NOT NULL,
            cantidad DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            ubicacion VARCHAR(120) NULL,
            notas VARCHAR(255) NULL,
            created_by INT(11) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sesion (sesion_id),
            KEY idx_producto (producto_id),
            KEY idx_created_at (created_at),
            CONSTRAINT fk_inv_conteos_sesion
                FOREIGN KEY (sesion_id) REFERENCES inventario_sesiones(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Listar sesiones (últimas primero)
 */
function inventario_session_list(int $limit = 50): array {
    try {
        $pdo = getPDO();
        inventario_ensure_tables();

        $limit = max(1, min($limit, 200));
        $st = $pdo->prepare("
            SELECT s.*,
              (SELECT COUNT(DISTINCT c.producto_id) FROM inventario_conteos c WHERE c.sesion_id = s.id) AS productos_contados
            FROM inventario_sesiones s
            ORDER BY s.created_at DESC
            LIMIT {$limit}
        ");
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        flus_log_error('inventario_session_list failed', ['error' => $e->getMessage()]);
        return [];
    }
}

function inventario_session_create(string $nombre, ?string $descripcion = null, ?int $userId = null): ?int {
    try {
        $pdo = getPDO();
        inventario_ensure_tables();

        $nombre = trim($nombre);
        if ($nombre === '') return null;

        $st = $pdo->prepare("
            INSERT INTO inventario_sesiones (nombre, descripcion, estado, created_by, created_at)
            VALUES (?, ?, 'ABIERTA', ?, NOW())
        ");
        $st->execute([$nombre, $descripcion, $userId]);

        $id = (int)$pdo->lastInsertId();
        audit_event(AuditEvents::INVENTARIO_SESSION_CREATE, AuditEntities::INVENTARIO, $id, [
            'nombre' => $nombre,
        ], $userId);

        return $id;
    } catch (Throwable $e) {
        flus_log_error('inventario_session_create failed', ['error' => $e->getMessage()]);
        return null;
    }
}

function inventario_session_get(int $sessionId): ?array {
    try {
        $pdo = getPDO();
        inventario_ensure_tables();

        $st = $pdo->prepare("SELECT * FROM inventario_sesiones WHERE id = ?");
        $st->execute([$sessionId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return null;

        // Adjuntar resumen rápido
        $s['resumen'] = inventario_get_resumen_diferencias($sessionId);
        return $s;
    } catch (Throwable $e) {
        flus_log_error('inventario_session_get failed', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Registrar conteo
 */
function inventario_registrar_conteo(
    int $sessionId,
    int $productoId,
    float $cantidad,
    ?string $ubicacion = null,
    ?string $notas = null,
    ?int $userId = null
): ?int {
    try {
        $pdo = getPDO();
        inventario_ensure_tables();

        if ($sessionId <= 0 || $productoId <= 0) return null;

        // Solo si está abierta
        $st = $pdo->prepare("SELECT estado FROM inventario_sesiones WHERE id = ?");
        $st->execute([$sessionId]);
        $estado = (string)$st->fetchColumn();
        if ($estado !== 'ABIERTA') return null;

        $cantidad = round((float)$cantidad, 3);
        $ubicacion = $ubicacion !== null ? trim($ubicacion) : null;
        $notas = $notas !== null ? trim($notas) : null;

        $st = $pdo->prepare("
            INSERT INTO inventario_conteos (sesion_id, producto_id, cantidad, ubicacion, notas, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $st->execute([$sessionId, $productoId, $cantidad, $ubicacion, $notas, $userId]);
        $id = (int)$pdo->lastInsertId();

        audit_event(AuditEvents::INVENTARIO_COUNT, AuditEntities::INVENTARIO, $sessionId, [
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'ubicacion' => $ubicacion,
        ], $userId);

        return $id;
    } catch (Throwable $e) {
        flus_log_error('inventario_registrar_conteo failed', ['error' => $e->getMessage()]);
        return null;
    }
}

function inventario_session_close(int $sessionId, ?string $motivo = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        inventario_ensure_tables();

        if ($sessionId <= 0) return false;

        $st = $pdo->prepare("SELECT estado FROM inventario_sesiones WHERE id = ?");
        $st->execute([$sessionId]);
        $estado = (string)$st->fetchColumn();
        if ($estado !== 'ABIERTA') return false;

        $motivo = $motivo ? trim($motivo) : null;

        $st = $pdo->prepare("
            UPDATE inventario_sesiones
            SET estado='CERRADA', closed_at=NOW(), closed_by=?, cierre_motivo=?
            WHERE id=? AND estado='ABIERTA'
        ");
        $st->execute([$userId, $motivo, $sessionId]);

        $ok = $st->rowCount() > 0;
        if ($ok) {
            audit_event(AuditEvents::INVENTARIO_SESSION_CLOSE, AuditEntities::INVENTARIO, $sessionId, [
                'motivo' => $motivo,
            ], $userId);
        }
        return $ok;
    } catch (Throwable $e) {
        flus_log_error('inventario_session_close failed', ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Obtener conteos con info del producto + diferencia
 *
 * @param bool $soloConDiferencia si true, filtra donde diferencia != 0
 */
function inventario_get_conteos(int $sessionId, bool $soloConDiferencia = false): array {
    try {
        $pdo = getPDO();
        inventario_ensure_tables();

        if ($sessionId <= 0) return [];

        // Tomar el ÚLTIMO conteo por producto (por created_at, id)
        $sql = "
            SELECT
                c.producto_id,
                p.codigo,
                p.nombre,
                p.costo,
                c.cantidad AS cantidad_contada,
                p.stock  AS cantidad_sistema,
                (c.cantidad - p.stock) AS diferencia,
                c.ubicacion,
                c.notas
            FROM inventario_conteos c
            JOIN (
                SELECT producto_id, MAX(id) AS max_id
                FROM inventario_conteos
                WHERE sesion_id = ?
                GROUP BY producto_id
            ) last ON last.producto_id = c.producto_id AND last.max_id = c.id
            JOIN productos p ON p.id = c.producto_id
            WHERE c.sesion_id = ?
        ";

        if ($soloConDiferencia) {
            $sql .= " AND (c.cantidad - p.stock) <> 0";
        }

        $sql .= " ORDER BY p.nombre ASC";

        $st = $pdo->prepare($sql);
        $st->execute([$sessionId, $sessionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Normalizar números
        foreach ($rows as &$r) {
            $r['cantidad_contada'] = (float)$r['cantidad_contada'];
            $r['cantidad_sistema'] = (float)$r['cantidad_sistema'];
            $r['diferencia'] = (float)$r['diferencia'];
            $r['costo'] = $r['costo'] !== null ? (float)$r['costo'] : null;
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        flus_log_error('inventario_get_conteos failed', ['error' => $e->getMessage()]);
        return [];
    }
}

function inventario_get_resumen_diferencias(int $sessionId): array {
    $base = [
        'productos_contados' => 0,
        'productos_con_diferencia' => 0,
        'productos_faltantes' => 0,
        'productos_sobrantes' => 0,
        'valor_diferencia' => 0.0,
    ];

    try {
        $rows = inventario_get_conteos($sessionId, false);
        if (!$rows) return $base;

        $base['productos_contados'] = count($rows);

        $valor = 0.0;
        foreach ($rows as $r) {
            $dif = (float)$r['diferencia'];
            if (abs($dif) < 0.0005) continue;

            $base['productos_con_diferencia']++;
            if ($dif < 0) $base['productos_faltantes']++;
            if ($dif > 0) $base['productos_sobrantes']++;

            $costo = $r['costo'] !== null ? (float)$r['costo'] : 0.0;
            $valor += ($dif * $costo);
        }

        $base['valor_diferencia'] = round($valor, 2);
        return $base;

    } catch (Throwable $e) {
        flus_log_error('inventario_get_resumen_diferencias failed', ['error' => $e->getMessage()]);
        return $base;
    }
}

/**
 * Buscar productos para autocompletar (código/nombre)
 */
function inventario_buscar_producto(string $q, int $limit = 12): array {
    try {
        $pdo = getPDO();
        $q = trim($q);
        if ($q === '') return [];

        $limit = max(1, min($limit, 50));
        $like = '%' . $q . '%';

        $st = $pdo->prepare("
            SELECT id, codigo, nombre, stock, costo
            FROM productos
            WHERE activo = 1
              AND (codigo LIKE ? OR nombre LIKE ?)
            ORDER BY
              CASE WHEN codigo = ? THEN 0 ELSE 1 END,
              nombre ASC
            LIMIT {$limit}
        ");
        $st->execute([$like, $like, $q]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['stock'] = (float)$r['stock'];
            $r['costo'] = $r['costo'] !== null ? (float)$r['costo'] : null;
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        flus_log_error('inventario_buscar_producto failed', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Aplicar ajustes de stock según el último conteo por producto.
 * Devuelve array con 'ajustes_realizados'.
 */
function inventario_aplicar_ajustes(int $sessionId, ?int $userId = null, ?string &$errMsg = null): ?array {
    $errMsg = null;

    try {
        $pdo = getPDO();
        inventario_ensure_tables();

        // Chequear estado
        $st = $pdo->prepare("SELECT estado FROM inventario_sesiones WHERE id = ?");
        $st->execute([$sessionId]);
        $estado = (string)$st->fetchColumn();
        if ($estado !== 'CERRADA') {
            $errMsg = 'La sesión debe estar CERRADA para aplicar ajustes.';
            return null;
        }

        $conteos = inventario_get_conteos($sessionId, false);
        if (!$conteos) {
            $errMsg = 'No hay conteos para aplicar.';
            return null;
        }

        $pdo->beginTransaction();

        $ajustes = 0;
        foreach ($conteos as $c) {
            $productoId = (int)$c['producto_id'];
            $contado = (float)$c['cantidad_contada'];

            // Releer stock actual para evitar race
            $st = $pdo->prepare("SELECT stock FROM productos WHERE id = ? FOR UPDATE");
            $st->execute([$productoId]);
            $stockActual = $st->fetchColumn();
            if ($stockActual === false) continue;

            $stockActual = (float)$stockActual;
            $dif = $contado - $stockActual;

            if (abs($dif) < 0.0005) continue;

            $tipo = $dif > 0 ? 'AJUSTE_POSITIVO' : 'AJUSTE_NEGATIVO';
            $cantidadMov = round(abs($dif), 3);

            // Insert movimiento (trigger calcula stock_nuevo)
            $st = $pdo->prepare("
                INSERT INTO movimientos_stock
                (venta_id, fecha, producto_id, tipo, cantidad, referencia_venta_id, referencia_compra_id, comentario, usuario_id)
                VALUES (NULL, NOW(), ?, ?, ?, NULL, NULL, ?, ?)
            ");
            $coment = "Inventario físico (sesión #{$sessionId})";
            $st->execute([$productoId, $tipo, $cantidadMov, $coment, $userId]);

            // Actualizar stock al valor contado
            $st = $pdo->prepare("UPDATE productos SET stock = ?, fecha_modificacion = NOW() WHERE id = ?");
            $st->execute([$contado, $productoId]);

            $ajustes++;
        }

        // Marcar sesión aplicada
        $st = $pdo->prepare("
            UPDATE inventario_sesiones
            SET estado='APLICADA', applied_at=NOW(), applied_by=?
            WHERE id=? AND estado='CERRADA'
        ");
        $st->execute([$userId, $sessionId]);

        $pdo->commit();

        audit_event(AuditEvents::INVENTARIO_ADJUST, AuditEntities::INVENTARIO, $sessionId, [
            'ajustes_realizados' => $ajustes,
        ], $userId);

        return [
            'ajustes_realizados' => $ajustes,
        ];

    } catch (Throwable $e) {
        try {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        } catch (Throwable $ignore) {}
        $errMsg = $e->getMessage();
        flus_log_error('inventario_aplicar_ajustes failed', ['error' => $e->getMessage()]);
        return null;
    }
}
