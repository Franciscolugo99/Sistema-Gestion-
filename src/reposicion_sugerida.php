<?php
// src/reposicion_sugerida.php
declare(strict_types=1);

/**
 * FLUS Reposición Sugerida
 * Sistema de alertas de stock bajo y sugerencias de compra
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

// ============================================
// CONFIGURACIÓN DE STOCK MÍNIMO/MÁXIMO
// ============================================

/**
 * Actualizar configuración de stock para un producto
 */
function reposicion_set_config(
    int $productoId,
    ?float $stockMinimo = null,
    ?float $stockMaximo = null,
    ?float $puntoReorden = null,
    ?int $proveedorPredeterminadoId = null
): bool {
    try {
        $pdo = getPDO();
        
        // Verificar si existen las columnas
        $cols = $pdo->query("SHOW COLUMNS FROM productos")->fetchAll(PDO::FETCH_COLUMN);
        
        $updates = [];
        $params = [];
        
        if ($stockMinimo !== null && in_array('stock_minimo', $cols)) {
            $updates[] = 'stock_minimo = ?';
            $params[] = $stockMinimo;
        }
        
        if ($stockMaximo !== null && in_array('stock_maximo', $cols)) {
            $updates[] = 'stock_maximo = ?';
            $params[] = $stockMaximo;
        }
        
        if ($puntoReorden !== null && in_array('punto_reorden', $cols)) {
            $updates[] = 'punto_reorden = ?';
            $params[] = $puntoReorden;
        }
        
        if ($proveedorPredeterminadoId !== null && in_array('proveedor_id', $cols)) {
            $updates[] = 'proveedor_id = ?';
            $params[] = $proveedorPredeterminadoId;
        }
        
        if (empty($updates)) {
            // Si no hay columnas, crear tabla auxiliar
            return reposicion_set_config_aux($productoId, $stockMinimo, $stockMaximo, $puntoReorden, $proveedorPredeterminadoId);
        }
        
        $params[] = $productoId;
        
        $sql = "UPDATE productos SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return true;
        
    } catch (Throwable $e) {
        flus_log_error('reposicion_set_config failed', ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Configuración auxiliar si productos no tiene las columnas
 */
function reposicion_set_config_aux(
    int $productoId,
    ?float $stockMinimo,
    ?float $stockMaximo,
    ?float $puntoReorden,
    ?int $proveedorId
): bool {
    try {
        $pdo = getPDO();
        
        // Asegurar tabla auxiliar
        reposicion_ensure_tables($pdo);
        
        $stmt = $pdo->prepare("
            INSERT INTO producto_reposicion 
            (producto_id, stock_minimo, stock_maximo, punto_reorden, proveedor_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                stock_minimo = COALESCE(VALUES(stock_minimo), stock_minimo),
                stock_maximo = COALESCE(VALUES(stock_maximo), stock_maximo),
                punto_reorden = COALESCE(VALUES(punto_reorden), punto_reorden),
                proveedor_id = COALESCE(VALUES(proveedor_id), proveedor_id)
        ");
        $stmt->execute([$productoId, $stockMinimo, $stockMaximo, $puntoReorden, $proveedorId]);
        
        return true;
        
    } catch (Throwable $e) {
        flus_log_error('reposicion_set_config_aux failed', ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Obtener configuración de reposición de un producto
 */
function reposicion_get_config(int $productoId): array {
    try {
        $pdo = getPDO();
        
        // Intentar desde productos primero
        $stmt = $pdo->prepare("
            SELECT stock_minimo, stock_maximo, punto_reorden, proveedor_id
            FROM productos
            WHERE id = ?
        ");
        $stmt->execute([$productoId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($config && ($config['stock_minimo'] !== null || $config['stock_maximo'] !== null)) {
            return $config;
        }
        
        // Intentar desde tabla auxiliar
        $stmt = $pdo->prepare("
            SELECT stock_minimo, stock_maximo, punto_reorden, proveedor_id
            FROM producto_reposicion
            WHERE producto_id = ?
        ");
        $stmt->execute([$productoId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $config ?: [
            'stock_minimo' => null,
            'stock_maximo' => null,
            'punto_reorden' => null,
            'proveedor_id' => null,
        ];
        
    } catch (Throwable $e) {
        return [];
    }
}

// ============================================
// ALERTAS DE STOCK BAJO
// ============================================

/**
 * Obtener productos con stock bajo (bajo mínimo o punto de reorden)
 */
function reposicion_get_stock_bajo(int $limit = 100): array {
    try {
        $pdo = getPDO();
        
        // Primero intentar con columnas en productos
        $colsCheck = $pdo->query("SHOW COLUMNS FROM productos LIKE 'stock_minimo'")->fetchColumn();
        
        if ($colsCheck) {
            $stmt = $pdo->prepare("
                SELECT 
                    p.id, p.codigo, p.nombre, p.stock, p.costo, p.precio,
                    p.stock_minimo, p.stock_maximo, p.punto_reorden,
                    p.proveedor_id, pv.nombre as proveedor_nombre,
                    CASE 
                        WHEN p.stock <= 0 THEN 'SIN_STOCK'
                        WHEN p.stock < COALESCE(p.stock_minimo, 0) THEN 'BAJO_MINIMO'
                        WHEN p.stock < COALESCE(p.punto_reorden, p.stock_minimo, 0) THEN 'REORDEN'
                        ELSE 'NORMAL'
                    END as estado_stock,
                    COALESCE(p.stock_maximo, p.stock_minimo * 3, p.stock * 2) - p.stock as cantidad_sugerida
                FROM productos p
                LEFT JOIN proveedores pv ON p.proveedor_id = pv.id
                WHERE p.activo = 1
                  AND (
                      p.stock <= 0
                      OR p.stock < COALESCE(p.stock_minimo, 0)
                      OR p.stock < COALESCE(p.punto_reorden, 0)
                  )
                ORDER BY 
                    CASE 
                        WHEN p.stock <= 0 THEN 1
                        WHEN p.stock < COALESCE(p.stock_minimo, 0) THEN 2
                        ELSE 3
                    END,
                    p.stock ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Fallback: usar tabla auxiliar
        $stmt = $pdo->prepare("
            SELECT 
                p.id, p.codigo, p.nombre, p.stock, p.costo, p.precio,
                r.stock_minimo, r.stock_maximo, r.punto_reorden,
                r.proveedor_id, pv.nombre as proveedor_nombre,
                CASE 
                    WHEN p.stock <= 0 THEN 'SIN_STOCK'
                    WHEN p.stock < COALESCE(r.stock_minimo, 0) THEN 'BAJO_MINIMO'
                    WHEN p.stock < COALESCE(r.punto_reorden, r.stock_minimo, 0) THEN 'REORDEN'
                    ELSE 'NORMAL'
                END as estado_stock,
                COALESCE(r.stock_maximo, r.stock_minimo * 3, p.stock * 2) - p.stock as cantidad_sugerida
            FROM productos p
            LEFT JOIN producto_reposicion r ON p.id = r.producto_id
            LEFT JOIN proveedores pv ON r.proveedor_id = pv.id
            WHERE p.activo = 1
              AND (
                  p.stock <= 0
                  OR p.stock < COALESCE(r.stock_minimo, 0)
                  OR p.stock < COALESCE(r.punto_reorden, 0)
              )
            ORDER BY 
                CASE 
                    WHEN p.stock <= 0 THEN 1
                    WHEN p.stock < COALESCE(r.stock_minimo, 0) THEN 2
                    ELSE 3
                END,
                p.stock ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Throwable $e) {
        flus_log_error('reposicion_get_stock_bajo failed', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Obtener conteo de productos por estado de stock
 */
function reposicion_conteo_estados(): array {
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->query("
            SELECT 
                SUM(CASE WHEN p.stock <= 0 THEN 1 ELSE 0 END) as sin_stock,
                SUM(CASE WHEN p.stock > 0 AND p.stock < COALESCE(
                    (SELECT stock_minimo FROM producto_reposicion WHERE producto_id = p.id),
                    5
                ) THEN 1 ELSE 0 END) as bajo_minimo,
                SUM(CASE WHEN p.stock >= COALESCE(
                    (SELECT stock_minimo FROM producto_reposicion WHERE producto_id = p.id),
                    5
                ) THEN 1 ELSE 0 END) as stock_ok,
                COUNT(*) as total
            FROM productos p
            WHERE p.activo = 1
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
    } catch (Throwable $e) {
        return [];
    }
}

// ============================================
// SUGERENCIAS DE REPOSICIÓN
// ============================================

/**
 * Generar lista de reposición sugerida
 */
function reposicion_generar_lista(?int $proveedorId = null): array {
    try {
        $pdo = getPDO();
        
        $sql = "
            SELECT 
                p.id, p.codigo, p.nombre, p.stock, p.costo,
                COALESCE(r.stock_minimo, 5) as stock_minimo,
                COALESCE(r.stock_maximo, r.stock_minimo * 3, 15) as stock_maximo,
                COALESCE(r.punto_reorden, r.stock_minimo, 5) as punto_reorden,
                COALESCE(r.proveedor_id, p.proveedor_id) as proveedor_id,
                pv.nombre as proveedor_nombre,
                GREATEST(0, COALESCE(r.stock_maximo, r.stock_minimo * 3, 15) - p.stock) as cantidad_sugerida,
                GREATEST(0, COALESCE(r.stock_maximo, r.stock_minimo * 3, 15) - p.stock) * COALESCE(p.costo, 0) as costo_estimado
            FROM productos p
            LEFT JOIN producto_reposicion r ON p.id = r.producto_id
            LEFT JOIN proveedores pv ON COALESCE(r.proveedor_id, p.proveedor_id) = pv.id
            WHERE p.activo = 1
              AND p.stock < COALESCE(r.punto_reorden, r.stock_minimo, 5)
        ";
        
        $params = [];
        
        if ($proveedorId) {
            $sql .= " AND COALESCE(r.proveedor_id, p.proveedor_id) = ?";
            $params[] = $proveedorId;
        }
        
        $sql .= " ORDER BY pv.nombre, p.nombre";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Throwable $e) {
        flus_log_error('reposicion_generar_lista failed', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Agrupar lista de reposición por proveedor
 */
function reposicion_lista_por_proveedor(): array {
    $items = reposicion_generar_lista();
    $resultado = [];
    
    foreach ($items as $item) {
        $provId = $item['proveedor_id'] ?? 0;
        $provNombre = $item['proveedor_nombre'] ?? 'Sin proveedor';
        
        if (!isset($resultado[$provId])) {
            $resultado[$provId] = [
                'proveedor_id' => $provId,
                'proveedor_nombre' => $provNombre,
                'productos' => [],
                'total_productos' => 0,
                'costo_total_estimado' => 0,
            ];
        }
        
        $resultado[$provId]['productos'][] = $item;
        $resultado[$provId]['total_productos']++;
        $resultado[$provId]['costo_total_estimado'] += (float)$item['costo_estimado'];
    }
    
    // Ordenar por cantidad de productos
    usort($resultado, fn($a, $b) => $b['total_productos'] <=> $a['total_productos']);
    
    return array_values($resultado);
}

/**
 * Calcular cantidad óptima de reposición basado en historial
 */
function reposicion_cantidad_optima(int $productoId, int $diasAnalisis = 30): array {
    try {
        $pdo = getPDO();
        
        // Obtener ventas del período
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(vi.cantidad), 0) as total_vendido,
                COUNT(DISTINCT v.id) as cantidad_ventas,
                COUNT(DISTINCT DATE(v.fecha)) as dias_con_ventas
            FROM venta_items vi
            JOIN ventas v ON vi.venta_id = v.id
            WHERE vi.producto_id = ?
              AND v.fecha >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND v.estado = 'EMITIDA'
        ");
        $stmt->execute([$productoId, $diasAnalisis]);
        $ventas = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Obtener stock actual y config
        $stmt = $pdo->prepare("SELECT stock, codigo, nombre FROM productos WHERE id = ?");
        $stmt->execute([$productoId]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $config = reposicion_get_config($productoId);
        
        $totalVendido = (float)($ventas['total_vendido'] ?? 0);
        $diasConVentas = (int)($ventas['dias_con_ventas'] ?? 0);
        $stockActual = (float)($producto['stock'] ?? 0);
        
        // Calcular promedio diario
        $promedioDiario = $diasConVentas > 0 ? $totalVendido / $diasConVentas : 0;
        
        // Días de cobertura actuales
        $diasCobertura = $promedioDiario > 0 ? $stockActual / $promedioDiario : 999;
        
        // Cantidad sugerida para 30 días de stock
        $diasObjetivo = 30;
        $cantidadSugerida = max(0, ($promedioDiario * $diasObjetivo) - $stockActual);
        
        // Ajustar según stock máximo si está definido
        if (!empty($config['stock_maximo'])) {
            $cantidadSugerida = min($cantidadSugerida, $config['stock_maximo'] - $stockActual);
        }
        
        return [
            'producto_id' => $productoId,
            'codigo' => $producto['codigo'],
            'nombre' => $producto['nombre'],
            'stock_actual' => $stockActual,
            'total_vendido_periodo' => $totalVendido,
            'dias_analisis' => $diasAnalisis,
            'promedio_diario' => round($promedioDiario, 2),
            'dias_cobertura_actual' => round($diasCobertura, 1),
            'cantidad_sugerida' => ceil($cantidadSugerida),
            'config' => $config,
        ];
        
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

// ============================================
// EXPORTACIÓN
// ============================================

/**
 * Exportar lista de reposición para proveedor (CSV)
 */
function reposicion_exportar_csv(?int $proveedorId = null): string {
    $items = $proveedorId 
        ? reposicion_generar_lista($proveedorId)
        : reposicion_generar_lista();
    
    $csv = "Código,Producto,Stock Actual,Stock Mínimo,Cantidad a Pedir,Costo Unitario,Costo Total\n";
    
    foreach ($items as $item) {
        $csv .= sprintf(
            "%s,%s,%s,%s,%s,%s,%s\n",
            $item['codigo'],
            '"' . str_replace('"', '""', $item['nombre']) . '"',
            $item['stock'],
            $item['stock_minimo'],
            $item['cantidad_sugerida'],
            number_format((float)$item['costo'], 2, '.', ''),
            number_format((float)$item['costo_estimado'], 2, '.', '')
        );
    }
    
    return $csv;
}

/**
 * Exportar como array para JSON/Excel
 */
function reposicion_exportar_array(?int $proveedorId = null): array {
    return $proveedorId 
        ? reposicion_generar_lista($proveedorId)
        : reposicion_generar_lista();
}

// ============================================
// TABLAS AUXILIARES
// ============================================

/**
 * Asegurar que existen las tablas auxiliares
 */
function reposicion_ensure_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS producto_reposicion (
            producto_id INT UNSIGNED PRIMARY KEY,
            stock_minimo DECIMAL(12,3) NULL,
            stock_maximo DECIMAL(12,3) NULL,
            punto_reorden DECIMAL(12,3) NULL,
            proveedor_id INT UNSIGNED NULL,
            dias_reposicion INT NULL DEFAULT 7,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_proveedor (proveedor_id),
            INDEX idx_minimo (stock_minimo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Migrar columnas de productos a tabla auxiliar (si no existen en productos)
 */
function reposicion_ensure_columns(PDO $pdo): void {
    // Verificar si productos tiene las columnas necesarias
    $cols = $pdo->query("SHOW COLUMNS FROM productos")->fetchAll(PDO::FETCH_COLUMN);
    
    $alterSql = [];
    
    if (!in_array('stock_minimo', $cols)) {
        $alterSql[] = "ADD COLUMN stock_minimo DECIMAL(12,3) NULL";
    }
    if (!in_array('stock_maximo', $cols)) {
        $alterSql[] = "ADD COLUMN stock_maximo DECIMAL(12,3) NULL";
    }
    if (!in_array('punto_reorden', $cols)) {
        $alterSql[] = "ADD COLUMN punto_reorden DECIMAL(12,3) NULL";
    }
    
    if (!empty($alterSql)) {
        try {
            $pdo->exec("ALTER TABLE productos " . implode(', ', $alterSql));
            flus_log_info('Columnas de reposición agregadas a productos');
        } catch (Throwable $e) {
            // Usar tabla auxiliar en su lugar
            reposicion_ensure_tables($pdo);
        }
    }
}
