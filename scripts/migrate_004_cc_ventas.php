<?php
/**
 * FLUS - Migración 004: Integración CC con Ventas
 * 
 * Este script ejecuta las migraciones necesarias para:
 * - Separar "venta" (ingreso) de "cobro" (entrada de dinero) en cuenta corriente
 * - Las ventas a CC ya no inflan efectivo en caja
 * - El dinero entra a caja solo cuando se cobra la deuda de CC
 * 
 * EJECUTAR:
 * php scripts/migrate_004_cc_ventas.php
 * 
 * O desde navegador: /scripts/migrate_004_cc_ventas.php
 */

declare(strict_types=1);

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>Migración 004 - CC Ventas</title>";
    echo "<style>body{font-family:system-ui;max-width:900px;margin:40px auto;padding:20px;background:#1a1a2e;color:#eee}";
    echo "pre{background:#16213e;padding:15px;border-radius:8px;overflow-x:auto;line-height:1.6}";
    echo ".ok{color:#4ade80}.err{color:#f87171}.skip{color:#94a3b8}.warn{color:#fbbf24}h1{color:#38bdf8}</style>";
    echo "</head><body><h1>🔧 Migración 004: Integración CC con Ventas</h1><pre>";
}

function output(string $msg, string $type = ''): void {
    global $isCli;
    if ($isCli) {
        echo $msg . "\n";
    } else {
        $class = $type ? " class=\"{$type}\"" : '';
        echo "<span{$class}>" . htmlspecialchars($msg) . "</span>\n";
    }
}

// Cargar configuración
$configFile = dirname(__DIR__) . '/src/config.php';
if (!file_exists($configFile)) {
    output("❌ ERROR: No existe src/config.php. Ejecutá primero la instalación.", 'err');
    exit(1);
}

require_once $configFile;

try {
    $pdo = getPDO();
    output("✓ Conexión a base de datos OK", 'ok');
} catch (Throwable $e) {
    output("❌ ERROR de conexión: " . $e->getMessage(), 'err');
    exit(1);
}

// Helper para agregar columna si no existe
function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $definition, string $after = ''): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $st->execute([$column]);
        if ($st->fetch()) {
            output("→ Columna {$table}.{$column} ya existe", 'skip');
            return false;
        }
        
        $afterSql = $after ? " AFTER `{$after}`" : '';
        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}{$afterSql}";
        $pdo->exec($sql);
        output("✓ Columna {$table}.{$column} agregada", 'ok');
        return true;
    } catch (Throwable $e) {
        output("⚠ Error agregando {$table}.{$column}: " . $e->getMessage(), 'warn');
        return false;
    }
}

// Helper para crear índice si no existe
function createIndexIfNotExists(PDO $pdo, string $table, string $indexName, string $columns): bool {
    try {
        $st = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
        $st->execute([$indexName]);
        if ($st->fetch()) {
            output("→ Índice {$indexName} ya existe", 'skip');
            return false;
        }
        
        $pdo->exec("CREATE INDEX `{$indexName}` ON `{$table}` ({$columns})");
        output("✓ Índice {$indexName} creado", 'ok');
        return true;
    } catch (Throwable $e) {
        output("⚠ Error creando índice {$indexName}: " . $e->getMessage(), 'warn');
        return false;
    }
}

output("\n========== TABLA VENTAS ==========\n");

// 1. Agregar cliente_id a ventas
addColumnIfNotExists($pdo, 'ventas', 'cliente_id', 'INT UNSIGNED DEFAULT NULL COMMENT "Cliente asociado (para CC y facturación)"', 'caja_id');
createIndexIfNotExists($pdo, 'ventas', 'idx_ventas_cliente', 'cliente_id');

// 2. Agregar monto_cc a ventas
addColumnIfNotExists($pdo, 'ventas', 'monto_cc', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT "Monto cargado a cuenta corriente (no entró a caja)"', 'vuelto');

output("\n========== TABLA CUENTA_CORRIENTE_MOVIMIENTOS ==========\n");

// 3. Agregar autorizado_por
addColumnIfNotExists($pdo, 'cuenta_corriente_movimientos', 'autorizado_por', 'INT UNSIGNED DEFAULT NULL COMMENT "Usuario que autorizó exceder límite"', 'created_by');

// 4. Agregar caja_movimiento_id
addColumnIfNotExists($pdo, 'cuenta_corriente_movimientos', 'caja_movimiento_id', 'INT UNSIGNED DEFAULT NULL COMMENT "ID del movimiento de caja generado al cobrar"', 'caja_id');

createIndexIfNotExists($pdo, 'cuenta_corriente_movimientos', 'idx_cc_mov_caja_mov', 'caja_movimiento_id');

output("\n========== TABLA VENTA_PAGOS ==========\n");

// 5. Crear tabla venta_pagos si no existe
try {
    $check = $pdo->query("SHOW TABLES LIKE 'venta_pagos'");
    if ($check->fetch()) {
        output("→ Tabla venta_pagos ya existe", 'skip');
        
        // Agregar columnas CC si no existen
        addColumnIfNotExists($pdo, 'venta_pagos', 'cc_cliente_id', 'INT UNSIGNED DEFAULT NULL COMMENT "Cliente si es pago CC"', 'referencia');
        addColumnIfNotExists($pdo, 'venta_pagos', 'cc_movimiento_id', 'INT UNSIGNED DEFAULT NULL COMMENT "Movimiento CC generado"', 'cc_cliente_id');
        
    } else {
        $sql = "
            CREATE TABLE venta_pagos (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                venta_id INT UNSIGNED NOT NULL,
                medio_pago VARCHAR(30) NOT NULL COMMENT 'EFECTIVO, MP, DEBITO, CREDITO, CC',
                monto DECIMAL(12,2) NOT NULL,
                referencia VARCHAR(100) DEFAULT NULL COMMENT 'Nro de operación, etc.',
                cc_cliente_id INT UNSIGNED DEFAULT NULL COMMENT 'Cliente si es pago CC',
                cc_movimiento_id INT UNSIGNED DEFAULT NULL COMMENT 'Movimiento CC generado',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_venta_pagos_venta (venta_id),
                INDEX idx_venta_pagos_medio (medio_pago),
                INDEX idx_venta_pagos_cc_cliente (cc_cliente_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Pagos individuales de cada venta (soporta pagos mixtos)'
        ";
        $pdo->exec($sql);
        output("✓ Tabla venta_pagos creada", 'ok');
    }
} catch (Throwable $e) {
    output("⚠ Error con tabla venta_pagos: " . $e->getMessage(), 'warn');
}

output("\n========== TABLA CAJA_MOVIMIENTOS ==========\n");

// 6. Agregar columnas a caja_movimientos
addColumnIfNotExists($pdo, 'caja_movimientos', 'cc_movimiento_id', 'INT UNSIGNED DEFAULT NULL COMMENT "Referencia al movimiento de CC que generó este ingreso"');
addColumnIfNotExists($pdo, 'caja_movimientos', 'user_id', 'INT UNSIGNED DEFAULT NULL COMMENT "Usuario que registró el movimiento"');

// 6.1 CRÍTICO: Agregar medio_pago a caja_movimientos para arqueo correcto
// Sin esto, no se puede distinguir EFECTIVO de TRANSFERENCIA al listar movimientos
addColumnIfNotExists($pdo, 'caja_movimientos', 'medio_pago', 'VARCHAR(30) DEFAULT NULL COMMENT "EFECTIVO, MP, DEBITO, CREDITO, TRANSFERENCIA - para arqueo correcto"', 'tipo');

output("\n========== TABLA CAJA_SESIONES ==========\n");

// 6.2 CRÍTICO: Agregar total_transferencia a caja_sesiones
// Sin esto, TRANSFERENCIA suma a total_efectivo (bug de arqueo fantasma)
addColumnIfNotExists($pdo, 'caja_sesiones', 'total_transferencia', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT "Total de ingresos por transferencia bancaria"', 'total_credito');

output("\n========== VISTA AUXILIAR ==========\n");

// 7. Crear vista auxiliar para reportes
try {
    $sql = "
        CREATE OR REPLACE VIEW v_ventas_medios_reales AS
        SELECT 
            v.id AS venta_id,
            v.fecha,
            v.total,
            COALESCE(v.monto_cc, 0) AS monto_cc,
            (v.total - COALESCE(v.monto_cc, 0)) AS monto_caja,
            v.estado,
            CASE 
                WHEN COALESCE(v.monto_cc, 0) >= v.total THEN 'CC_TOTAL'
                WHEN COALESCE(v.monto_cc, 0) > 0 THEN 'MIXTO'
                ELSE 'CONTADO'
            END AS tipo_venta,
            v.medio_pago,
            v.cliente_id,
            c.nombre AS cliente_nombre,
            c.cc_saldo AS cliente_saldo
        FROM ventas v
        LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.estado != 'ANULADA'
    ";
    $pdo->exec($sql);
    output("✓ Vista v_ventas_medios_reales creada/actualizada", 'ok');
} catch (Throwable $e) {
    output("⚠ Error creando vista: " . $e->getMessage(), 'warn');
}

// Resumen
output("\n========================================");
output("✅ MIGRACIÓN 004 COMPLETADA", 'ok');
output("========================================");
output("");
output("CAMBIOS APLICADOS:");
output("• Ventas ahora tienen cliente_id y monto_cc");
output("• Los pagos CC NO suman a efectivo en caja");
output("• El cobro de CC registra ingreso en caja");
output("• Nueva tabla venta_pagos para pagos mixtos");
output("• caja_movimientos.medio_pago para arqueo correcto");
output("• caja_sesiones.total_transferencia separado de efectivo");
output("");
output("PRÓXIMOS PASOS:");
output("1. Las ventas existentes a CC NO se modifican");
output("2. Solo nuevas ventas usarán el nuevo flujo");
output("3. Opcionalmente, reconciliar ventas antiguas");

if (!$isCli) {
    echo "</pre>";
    echo "<p style='margin-top:20px'>";
    echo "<a href='../public/caja.php' style='color:#38bdf8'>← Ir a Caja</a> | ";
    echo "<a href='../public/cuenta_corriente.php' style='color:#38bdf8'>Cuenta Corriente</a>";
    echo "</p>";
    echo "</body></html>";
}
