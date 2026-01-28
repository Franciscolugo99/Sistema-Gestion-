<?php
/**
 * FLUS - Migración: Actualizar tabla clientes con todas las columnas necesarias
 * 
 * Este script agrega las columnas faltantes a la tabla clientes:
 * - Campos de cuenta corriente (cc_habilitado, cc_limite, cc_saldo, etc.)
 * - Campos adicionales (tipo_cliente, descuento_porcentaje, zona_reparto, notas)
 * - Campos de auditoría (created_by, updated_by)
 * 
 * El script es idempotente (puede ejecutarse múltiples veces sin problemas).
 * 
 * USO:
 * php scripts/migrate_clientes_cc.php
 * 
 * O desde el navegador: /scripts/migrate_clientes_cc.php
 */

declare(strict_types=1);

// Detectar si se ejecuta desde CLI o navegador
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>Migración Clientes</title><style>body{font-family:system-ui;max-width:900px;margin:40px auto;padding:20px;background:#1a1a2e;color:#eee}pre{background:#16213e;padding:15px;border-radius:8px;overflow-x:auto;line-height:1.6}.ok{color:#4ade80}.err{color:#f87171}.skip{color:#94a3b8}.warn{color:#fbbf24}h1{color:#38bdf8}</style></head><body>";
    echo "<h1>🔧 Migración: Tabla Clientes Completa</h1><pre>";
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

// Verificar que existe la tabla clientes
try {
    $check = $pdo->query("SHOW TABLES LIKE 'clientes'");
    if (!$check->fetch()) {
        output("❌ ERROR: La tabla 'clientes' no existe.", 'err');
        exit(1);
    }
    output("✓ Tabla 'clientes' encontrada", 'ok');
} catch (Throwable $e) {
    output("❌ ERROR verificando tabla: " . $e->getMessage(), 'err');
    exit(1);
}

// Obtener columnas actuales
$stmt = $pdo->query("SHOW COLUMNS FROM clientes");
$existingColumns = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN, 0));
output("\n→ Columnas actuales: " . count($existingColumns));

// Lista de columnas a agregar con su definición
// El orden importa para el AFTER
$columns = [
    // Campos básicos que pueden faltar
    'cuit' => [
        'definition' => "VARCHAR(20) DEFAULT NULL COMMENT 'CUIT/CUIL del cliente'",
        'after' => 'nombre'
    ],
    'cond_iva' => [
        'definition' => "VARCHAR(5) DEFAULT NULL COMMENT 'Condición frente al IVA'",
        'after' => 'cuit'
    ],
    'tipo_cliente' => [
        'definition' => "ENUM('MINORISTA','MAYORISTA','CORPORATIVO') DEFAULT 'MINORISTA' COMMENT 'Tipo de cliente'",
        'after' => 'cond_iva'
    ],
    'descuento_porcentaje' => [
        'definition' => "DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Descuento permanente %'",
        'after' => 'tipo_cliente'
    ],
    'email' => [
        'definition' => "VARCHAR(100) DEFAULT NULL",
        'after' => 'descuento_porcentaje'
    ],
    'telefono' => [
        'definition' => "VARCHAR(50) DEFAULT NULL",
        'after' => 'email'
    ],
    'direccion' => [
        'definition' => "VARCHAR(255) DEFAULT NULL",
        'after' => 'telefono'
    ],
    'zona_reparto' => [
        'definition' => "VARCHAR(50) DEFAULT NULL COMMENT 'Código de zona de reparto'",
        'after' => 'direccion'
    ],
    'notas' => [
        'definition' => "TEXT DEFAULT NULL COMMENT 'Notas internas'",
        'after' => 'zona_reparto'
    ],
    'activo' => [
        'definition' => "TINYINT(1) NOT NULL DEFAULT 1",
        'after' => 'notas'
    ],
    
    // Campos de cuenta corriente
    'cc_habilitado' => [
        'definition' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Cuenta corriente habilitada'",
        'after' => 'activo'
    ],
    'cc_limite' => [
        'definition' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Límite de crédito'",
        'after' => 'cc_habilitado'
    ],
    'cc_saldo' => [
        'definition' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo actual (deuda)'",
        'after' => 'cc_limite'
    ],
    'cc_fecha_ultimo_pago' => [
        'definition' => "DATE DEFAULT NULL COMMENT 'Fecha del último pago'",
        'after' => 'cc_saldo'
    ],
    
    // Campos de auditoría
    'created_by' => [
        'definition' => "INT UNSIGNED DEFAULT NULL COMMENT 'Usuario que creó'",
        'after' => 'cc_fecha_ultimo_pago'
    ],
    'updated_by' => [
        'definition' => "INT UNSIGNED DEFAULT NULL COMMENT 'Usuario que actualizó'",
        'after' => 'created_by'
    ],
    'created_at' => [
        'definition' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'after' => 'updated_by'
    ],
    'updated_at' => [
        'definition' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'after' => 'created_at'
    ],
];

output("\n========== AGREGANDO COLUMNAS ==========\n");

$added = 0;
$skipped = 0;

foreach ($columns as $colName => $colInfo) {
    try {
        if (isset($existingColumns[$colName])) {
            output("→ Columna '{$colName}' ya existe", 'skip');
            $skipped++;
            continue;
        }
        
        // Verificar que la columna AFTER existe
        $afterCol = $colInfo['after'];
        if (!isset($existingColumns[$afterCol])) {
            // Buscar la última columna que sí existe
            $afterCol = null;
        }
        
        $afterSql = $afterCol ? " AFTER `{$afterCol}`" : '';
        $sql = "ALTER TABLE clientes ADD COLUMN `{$colName}` {$colInfo['definition']}{$afterSql}";
        
        $pdo->exec($sql);
        output("✓ Columna '{$colName}' agregada", 'ok');
        $added++;
        
        // Actualizar lista de columnas existentes
        $existingColumns[$colName] = true;
        
    } catch (Throwable $e) {
        output("⚠ Error agregando '{$colName}': " . $e->getMessage(), 'warn');
    }
}

// Crear índices si no existen
output("\n========== CREANDO ÍNDICES ==========\n");

$indexes = [
    'idx_clientes_cuit' => 'cuit',
    'idx_clientes_activo' => 'activo',
    'idx_clientes_cc_habilitado' => 'cc_habilitado',
    'idx_clientes_cc_saldo' => 'cc_saldo',
    'idx_clientes_tipo' => 'tipo_cliente',
];

foreach ($indexes as $idxName => $idxCol) {
    // Verificar que la columna existe
    if (!isset($existingColumns[$idxCol])) {
        output("→ Índice '{$idxName}' omitido (columna no existe)", 'skip');
        continue;
    }
    
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM clientes WHERE Key_name = ?");
        $stmt->execute([$idxName]);
        
        if ($stmt->fetch()) {
            output("→ Índice '{$idxName}' ya existe", 'skip');
            continue;
        }
        
        $pdo->exec("CREATE INDEX `{$idxName}` ON clientes(`{$idxCol}`)");
        output("✓ Índice '{$idxName}' creado", 'ok');
        
    } catch (Throwable $e) {
        output("⚠ Error creando índice '{$idxName}': " . $e->getMessage(), 'warn');
    }
}

// Crear tabla de movimientos de cuenta corriente si no existe
output("\n========== TABLA CUENTA_CORRIENTE_MOVIMIENTOS ==========\n");

try {
    $check = $pdo->query("SHOW TABLES LIKE 'cuenta_corriente_movimientos'");
    if ($check->fetch()) {
        output("→ Tabla 'cuenta_corriente_movimientos' ya existe", 'skip');
    } else {
        $sql = "
            CREATE TABLE cuenta_corriente_movimientos (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT UNSIGNED NOT NULL,
                tipo ENUM('CARGO', 'PAGO', 'AJUSTE_POS', 'AJUSTE_NEG', 'REVERSA') NOT NULL,
                estado ENUM('ACTIVO', 'ANULADO') NOT NULL DEFAULT 'ACTIVO',
                monto DECIMAL(12,2) NOT NULL,
                saldo_anterior DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                saldo_posterior DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                venta_id INT UNSIGNED DEFAULT NULL COMMENT 'Venta asociada (si aplica)',
                reversa_de_id INT UNSIGNED DEFAULT NULL COMMENT 'ID del movimiento reversado',
                concepto VARCHAR(255) DEFAULT NULL,
                medio_pago VARCHAR(50) DEFAULT NULL,
                referencia VARCHAR(100) DEFAULT NULL COMMENT 'Nro cheque, transferencia, etc',
                created_by INT UNSIGNED DEFAULT NULL,
                caja_id INT UNSIGNED DEFAULT NULL,
                terminal_id INT UNSIGNED DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_cc_mov_cliente (cliente_id),
                INDEX idx_cc_mov_tipo (tipo),
                INDEX idx_cc_mov_estado (estado),
                INDEX idx_cc_mov_venta (venta_id),
                INDEX idx_cc_mov_fecha (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Movimientos de cuenta corriente de clientes'
        ";
        $pdo->exec($sql);
        output("✓ Tabla 'cuenta_corriente_movimientos' creada", 'ok');
    }
} catch (Throwable $e) {
    output("⚠ Error con tabla movimientos: " . $e->getMessage(), 'warn');
}

// Resumen
output("\n========================================");
output("RESUMEN DE MIGRACIÓN:");
output("  - Columnas agregadas: {$added}");
output("  - Columnas omitidas (ya existían): {$skipped}");
output("========================================");
output("\n✅ Migración completada exitosamente", 'ok');

if (!$isCli) {
    echo "</pre>";
    echo "<p style='margin-top:20px'>";
    echo "<a href='../public/clientes.php' style='color:#38bdf8'>← Volver a Clientes</a>";
    echo "</p>";
    echo "</body></html>";
}
