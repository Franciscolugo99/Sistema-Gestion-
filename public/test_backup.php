<?php
/**
 * Script de Diagnóstico - Compatible con define()
 * Para tu configuración específica de FLUS
 * 
 * PROTEGIDO: Requiere autenticación y permiso de backups
 */

declare(strict_types=1);

// Cargar bootstrap completo para autenticación
require_once __DIR__ . '/bootstrap.php';

// SEGURIDAD: Verificar que el usuario está logueado y tiene permisos
require_login();
require_permission('gestionar_backups');

// Cargar config (ya cargado por bootstrap, pero por compatibilidad)
// require_once __DIR__ . '/../src/config.php';

$isWeb = php_sapi_name() !== 'cli';

if ($isWeb) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Diagnóstico FLUS - Sistema de Backups</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { 
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 2rem;
        }
        .subtitle {
            color: #718096;
            margin-bottom: 30px;
            font-size: 1.1rem;
        }
        .section { 
            background: #f7fafc; 
            border-left: 4px solid #667eea;
            border-radius: 8px; 
            padding: 20px; 
            margin: 20px 0;
        }
        h2 { 
            color: #2d3748; 
            margin-bottom: 15px;
            font-size: 1.4rem;
        }
        .check-item {
            padding: 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .ok { color: #48bb78; font-weight: 600; }
        .error { color: #f56565; font-weight: 600; }
        .warning { color: #ed8936; font-weight: 600; }
        .info { color: #4299e1; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .badge-ok { background: #c6f6d5; color: #22543d; }
        .badge-error { background: #fed7d7; color: #742a2a; }
        .badge-warning { background: #feebc8; color: #7c2d12; }
        pre { 
            background: #2d3748; 
            color: #e2e8f0;
            padding: 15px; 
            border-radius: 6px; 
            overflow-x: auto;
            margin: 10px 0;
        }
        .alert {
            background: #edf2f7;
            border-left: 4px solid #4299e1;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .alert-success {
            background: #f0fff4;
            border-color: #48bb78;
        }
        .alert-error {
            background: #fff5f5;
            border-color: #f56565;
        }
        .summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 8px;
            margin: 30px 0;
        }
        .summary h3 {
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        th { 
            background: #edf2f7;
            font-weight: 600;
            color: #2d3748;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Diagnóstico del Sistema de Backups</h1>
    <p class="subtitle">FLUS - Análisis completo de configuración</p>';
}

function println($msg, $type = 'info') {
    global $isWeb;
    
    if ($isWeb) {
        $class = $type;
        echo "<div class='check-item'><span class='$class'>$msg</span></div>";
    } else {
        echo $msg . "\n";
    }
}

function section($title) {
    global $isWeb;
    if ($isWeb) {
        echo "<div class='section'><h2>$title</h2>";
    } else {
        echo "\n" . str_repeat('=', 60) . "\n$title\n" . str_repeat('=', 60) . "\n";
    }
}

function endsection() {
    global $isWeb;
    if ($isWeb) echo "</div>";
}

// ============================================
// 1. Sistema
// ============================================
section('1. Información del Sistema');

println("✓ Sistema: " . PHP_OS . " (" . PHP_OS_FAMILY . ")", 'ok');
println("✓ PHP: " . PHP_VERSION, 'ok');
println("✓ Usuario: " . get_current_user(), 'info');

endsection();

// ============================================
// 2. Configuración
// ============================================
section('2. Configuración de Base de Datos (define)');

$checks = [
    'DB_HOST' => defined('DB_HOST') ? DB_HOST : null,
    'DB_NAME' => defined('DB_NAME') ? DB_NAME : null,
    'DB_USER' => defined('DB_USER') ? DB_USER : null,
    'DB_PASS' => defined('DB_PASS') ? (DB_PASS !== '' ? '***configurado***' : '(vacío)') : null,
];

$allDefined = true;
foreach ($checks as $const => $value) {
    if ($value !== null) {
        println("✓ $const = $value", 'ok');
    } else {
        println("✗ $const NO está definida", 'error');
        $allDefined = false;
    }
}

// Probar conexión
if ($allDefined) {
    println("\n🔌 Probando conexión...", 'info');
    try {
        $pdo = getPDO();
        println("✓ Conexión exitosa a: " . DB_NAME, 'ok');

        if (function_exists('flus_table_exists')) {
            $coreTables = ['users', 'productos', 'ventas', 'venta_items', 'backups'];
            $detected = [];
            foreach ($coreTables as $table) {
                if (flus_table_exists($pdo, $table)) {
                    $detected[] = $table;
                }
            }
            println("✓ Tablas base detectadas: " . count($detected) . '/' . count($coreTables), 'info');
        }
        
    } catch (Exception $e) {
        println("✗ Error de conexión: " . $e->getMessage(), 'error');
    }
}

endsection();

// ============================================
// 3. mysqldump
// ============================================
section('3. Búsqueda de mysqldump');

// Verificar constante MYSQLDUMP_BIN
if (defined('MYSQLDUMP_BIN')) {
    println("✓ MYSQLDUMP_BIN definida: " . MYSQLDUMP_BIN, 'info');
    
    if (file_exists(MYSQLDUMP_BIN)) {
        println("✓ El archivo existe", 'ok');
        
        // Probar ejecución
        $output = [];
        $returnCode = 0;
        @exec('"' . MYSQLDUMP_BIN . '" --version 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            println("✓ mysqldump es ejecutable", 'ok');
            println("  Versión: " . implode(' ', $output), 'info');
        } else {
            println("✗ mysqldump no es ejecutable (código: $returnCode)", 'error');
        }
    } else {
        println("✗ El archivo NO existe en esa ruta", 'error');
    }
} else {
    println("⚠ MYSQLDUMP_BIN no está definida", 'warning');
}

// Buscar en rutas comunes
println("\n📂 Buscando en rutas comunes...", 'info');

$isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
$portableRoot = defined('FLUS_ROOT') ? dirname((string) FLUS_ROOT) : dirname(__DIR__, 2);
if ($isWindows) {
    $paths = [
        $portableRoot . DIRECTORY_SEPARATOR . 'stack' . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
        $portableRoot . DIRECTORY_SEPARATOR . 'stack' . DIRECTORY_SEPARATOR . 'mariadb' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
    ];
} else {
    $paths = [
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
    ];
}

$found = false;
foreach ($paths as $path) {
    if (file_exists($path)) {
        println("✓ Encontrado: $path", 'ok');
        $found = true;
        break;
    } else {
        println("✗ No existe: $path", 'error');
    }
}

if (!$found && !defined('MYSQLDUMP_BIN')) {
    if ($isWeb) {
        echo '<div class="alert alert-error">
            <strong>⚠️ mysqldump no encontrado</strong>
            <p>Agregá esta línea a tu <code>src/config.php</code>:</p>
            <pre>define(\'MYSQLDUMP_BIN\', \'C:\\ruta\\a\\mysql\\bin\\mysqldump.exe\');</pre>
        </div>';
    }
}

endsection();

// ============================================
// 4. Directorio de Backups
// ============================================
section('4. Directorio de Backups');

$backupsDir = defined('BACKUPS_PATH') ? BACKUPS_PATH : (dirname(__DIR__) . '/storage/backups');
println("📁 Directorio: $backupsDir", 'info');

if (is_dir($backupsDir)) {
    println("✓ El directorio existe", 'ok');
    
    if (is_writable($backupsDir)) {
        println("✓ Tiene permisos de escritura", 'ok');
        
        // Test de escritura
        $testFile = $backupsDir . '/test_' . time() . '.txt';
        if (@file_put_contents($testFile, 'test') !== false) {
            println("✓ Prueba de escritura exitosa", 'ok');
            @unlink($testFile);
        }
    } else {
        println("✗ NO tiene permisos de escritura", 'error');
    }
} else {
    println("✗ El directorio NO existe", 'error');
    
    if (@mkdir($backupsDir, 0775, true)) {
        println("✓ Directorio creado exitosamente", 'ok');
    }
}

// Listar backups existentes
$backups = glob($backupsDir . '/*.sql');
println("\n📦 Backups existentes: " . count($backups ?: []), 'info');

endsection();

// ============================================
// 5. Resumen
// ============================================
if ($isWeb) {
    $hasConfig = defined('DB_HOST') && defined('DB_NAME');
    $hasMysqldump = (defined('MYSQLDUMP_BIN') && file_exists(MYSQLDUMP_BIN)) || $found;
    $hasPerms = is_writable($backupsDir);
    
    $allOk = $hasConfig && $hasMysqldump && $hasPerms;
    
    echo '<div class="summary">';
    echo '<h3>📊 Resumen del Diagnóstico</h3>';
    echo '<table style="color: white; border-color: rgba(255,255,255,0.2);">';
    echo '<tr><td>✓ Configuración de BD</td><td><span class="badge ' . ($hasConfig ? 'badge-ok' : 'badge-error') . '">' . ($hasConfig ? 'OK' : 'Falta') . '</span></td></tr>';
    echo '<tr><td>✓ mysqldump disponible</td><td><span class="badge ' . ($hasMysqldump ? 'badge-ok' : 'badge-error') . '">' . ($hasMysqldump ? 'OK' : 'Falta') . '</span></td></tr>';
    echo '<tr><td>✓ Permisos de escritura</td><td><span class="badge ' . ($hasPerms ? 'badge-ok' : 'badge-error') . '">' . ($hasPerms ? 'OK' : 'Falta') . '</span></td></tr>';
    echo '</table>';
    echo '</div>';
    
    if ($allOk) {
        echo '<div class="alert alert-success">';
        echo '<h3>✅ Sistema Listo para Backups</h3>';
        echo '<p><strong>Siguiente paso:</strong> Volvé al panel de Backups y creá una copia de prueba.</p>';
        echo '<p>Conservá al menos una copia descargada fuera del equipo.</p>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-error">';
        echo '<h3>⚠️ Correcciones Necesarias</h3>';
        echo '<ul>';
        if (!$hasConfig) echo '<li>Verificá las constantes de BD en config.php</li>';
        if (!$hasMysqldump) echo '<li>Definí MYSQLDUMP_BIN en config.php</li>';
        if (!$hasPerms) echo '<li>Otorgá permisos al directorio storage/backups</li>';
        echo '</ul>';
        echo '</div>';
    }
    
    echo '</div></body></html>';
}
