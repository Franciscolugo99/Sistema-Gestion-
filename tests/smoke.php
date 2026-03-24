<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/compras_helpers.php';
require_once __DIR__ . '/../src/facturacion_manual_lib.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

$results = [];

function flus_collect_permission_slugs_from_public(string $publicRoot): array
{
    if (!is_dir($publicRoot)) {
        throw new RuntimeException('Missing public root for permission scan');
    }

    $slugs = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($publicRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $php = (string)file_get_contents($file->getPathname());

        if (preg_match_all('/\b(?:require_permission|user_has_permission|\$can)\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $php, $matches)) {
            foreach ($matches[1] as $slug) {
                $slugs[] = $slug;
            }
        }

        if (preg_match_all('/\b(?:require_any_permission|user_has_any_permission)\(\s*\[(.*?)\]\s*\)/s', $php, $matches)) {
            foreach ($matches[1] as $list) {
                if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $list, $slugMatches)) {
                    foreach ($slugMatches[1] as $slug) {
                        $slugs[] = $slug;
                    }
                }
            }
        }
    }

    $slugs = array_values(array_unique(array_map('strval', $slugs)));
    sort($slugs, SORT_STRING);

    return $slugs;
}

function flus_collect_permission_slugs_from_sql_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing SQL file: ' . $path);
    }

    $sql = (string)file_get_contents($path);
    $slugs = [];

    if (preg_match_all("/\\(\\s*\\d+\\s*,\\s*'[^']+'\\s*,\\s*'([^']+)'\\s*,\\s*NOW\\(\\)\\s*\\)/", $sql, $matches)) {
        $slugs = $matches[1];
    } elseif (preg_match_all("/\\(\\s*'[^']+'\\s*,\\s*'([^']+)'\\s*,\\s*NOW\\(\\)\\s*\\)/", $sql, $matches)) {
        $slugs = $matches[1];
    }

    $slugs = array_values(array_unique(array_map('strval', $slugs)));
    sort($slugs, SORT_STRING);

    return $slugs;
}

function flus_collect_permission_slugs_from_sql_paths(array $paths): array
{
    $slugs = [];

    foreach ($paths as $path) {
        $slugs = array_merge($slugs, flus_collect_permission_slugs_from_sql_file($path));
    }

    $slugs = array_values(array_unique(array_map('strval', $slugs)));
    sort($slugs, SORT_STRING);

    return $slugs;
}

function flus_collect_permission_slugs_from_migrations(string $migrationsRoot): array
{
    if (!is_dir($migrationsRoot)) {
        throw new RuntimeException('Missing migrations root for permission scan');
    }

    $paths = glob($migrationsRoot . DIRECTORY_SEPARATOR . '*.sql');
    if ($paths === false) {
        throw new RuntimeException('Could not enumerate migration SQL files');
    }

    sort($paths, SORT_STRING);

    return flus_collect_permission_slugs_from_sql_paths($paths);
}

function flus_open_project_pdo(string $repoRoot): PDO
{
    $configPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Missing src/config.php for database permission test');
    }

    require_once $configPath;

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        throw new RuntimeException('Database constants are not available for permission test');
    }

    $port = defined('DB_PORT') ? (string)DB_PORT : '3306';
    $charset = defined('DB_CHARSET') ? (string)DB_CHARSET : 'utf8mb4';
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        (string)DB_HOST,
        $port,
        (string)DB_NAME,
        $charset
    );

    return new PDO($dsn, (string)DB_USER, (string)DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function flus_fetch_first_column(PDO $pdo, string $sql): array
{
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $values = array_values(array_unique(array_filter(array_map('strval', $rows), static fn(string $value): bool => $value !== '')));
    sort($values, SORT_STRING);

    return $values;
}

function flus_format_slug_diff(array $slugs): string
{
    return $slugs === [] ? 'ninguno' : implode(', ', $slugs);
}

$results[] = flus_run_test('sh_quote handles Windows quoting', function (): void {
    $quoted = sh_quote('C:\Program Files\MySQL\bin\mysqldump.exe');
    flus_assert_same('"C:\Program Files\MySQL\bin\mysqldump.exe"', $quoted);
});

$results[] = flus_run_test('backup_restore_in_progress detects active lock', function (): void {
    $lockPath = FLUS_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'restore.lock';

    flus_assert_false(backup_restore_in_progress(), 'restore should start inactive');

    $fp = fopen($lockPath, 'c');
    if (!$fp) {
        throw new RuntimeException('Could not open restore.lock for test');
    }

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        throw new RuntimeException('Could not lock restore.lock in test');
    }

    flus_assert_true(backup_restore_in_progress(), 'active flock should be detected');

    flock($fp, LOCK_UN);
    fclose($fp);

    flus_assert_false(backup_restore_in_progress(), 'released lock should not be detected as active');
});

$results[] = flus_run_test('flus_make_shareable_path masks FLUS_ROOT', function (): void {
    $path = FLUS_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
    $label = flus_make_shareable_path($path);
    flus_assert_same('[FLUS_ROOT]/storage/logs/app.log', $label);
});

$results[] = flus_run_test('flus_sanitize_log_line redacts obvious secrets', function (): void {
    $line = 'email=cliente@example.com token=abc123456789 127.0.0.1 path=' . FLUS_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
    $sanitized = flus_sanitize_log_line($line);

    flus_assert_contains('[EMAIL]', $sanitized);
    flus_assert_contains('[IP]', $sanitized);
    flus_assert_contains('[FLUS_ROOT]', str_replace('\\', '/', $sanitized));
    flus_assert_not_contains('cliente@example.com', $sanitized);
    flus_assert_not_contains('127.0.0.1', $sanitized);
});

$results[] = flus_run_test('flus_get_sanitized_config masks shareable db values', function (): void {
    $shareable = flus_get_sanitized_config(true);
    $normal = flus_get_sanitized_config(false);

    flus_assert_same('***SET***', $shareable['DB_HOST']);
    flus_assert_same('***SET***', $shareable['DB_NAME']);
    flus_assert_same('***SET***', $shareable['DB_USER']);
    flus_assert_same(APP_NAME, $shareable['APP_NAME']);
    flus_assert_same(DB_HOST, $normal['DB_HOST']);
});

$results[] = flus_run_test('flus_build_diagnostic_overview escalates active problems', function (): void {
    $baseHealth = [
        'database' => ['connected' => true, 'name' => 'kiosco', 'selected_db' => 'kiosco'],
        'critical_tables' => ['missing_count' => 0, 'check_failed' => false],
        'disk' => ['used_percent' => 20],
        'active_locks' => [],
        'locks' => ['restore_in_progress' => false],
        'maintenance' => ['active' => false],
    ];

    $ok = flus_build_diagnostic_overview($baseHealth, null, null, null, ['total_critical' => 0]);
    flus_assert_same('ok', $ok['status']);

    $warnHealth = $baseHealth;
    $warnHealth['locks']['restore_in_progress'] = true;
    $warn = flus_build_diagnostic_overview($warnHealth, null, null, null, ['total_critical' => 0]);
    flus_assert_same('warning', $warn['status']);

    $errorHealth = $baseHealth;
    $errorHealth['critical_tables']['missing_count'] = 2;
    $error = flus_build_diagnostic_overview($errorHealth, null, null, null, ['total_critical' => 0]);
    flus_assert_same('error', $error['status']);
});

$results[] = flus_run_test('flus_format_bytes keeps current UI format', function (): void {
    flus_assert_same('1,50 KB', flus_format_bytes(1536));
});

$results[] = flus_run_test('flus_is_critical_role recognizes protected admin slugs', function (): void {
    flus_assert_true(flus_is_critical_role('admin'));
    flus_assert_true(flus_is_critical_role('administrador'));
    flus_assert_false(flus_is_critical_role('cajero'));
});

$results[] = flus_run_test('flus_validate_user_payload checks duplicates and role existence', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO roles (id, nombre, slug) VALUES (1, 'Administrador', 'admin'), (2, 'Cajero', 'cajero')");
    $pdo->exec("INSERT INTO users (id, email, username, role_id, activo) VALUES (1, 'admin@flus.local', 'admin', 1, 1)");

    $result = flus_validate_user_payload($pdo, [
        'nombre' => 'Pe',
        'email' => 'admin@flus.local',
        'username' => 'admin',
        'password' => '123',
        'role_id' => 99,
        'activo' => 1,
    ], [
        'require_password' => true,
        'require_email' => true,
        'default_activo' => 1,
    ]);

    flus_assert_contains('El nombre debe tener al menos 3 caracteres', implode(' | ', $result['errors']));
    flus_assert_contains('Este email ya esta registrado', implode(' | ', $result['errors']));
    flus_assert_contains('Este nombre de usuario ya esta en uso', implode(' | ', $result['errors']));
    flus_assert_contains('La contrasena debe tener al menos 6 caracteres', implode(' | ', $result['errors']));
    flus_assert_contains('Debe seleccionar un rol valido', implode(' | ', $result['errors']));
});

$results[] = flus_run_test('flus_guard_user_admin_mutation blocks self deactivation', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO roles (id, nombre, slug) VALUES (1, 'Administrador', 'admin')");
    $pdo->exec('INSERT INTO users (id, role_id, activo) VALUES (1, 1, 1)');

    $error = flus_guard_user_admin_mutation($pdo, 1, 1, 0, false, null);
    flus_assert_same('No puedes desactivar tu propio usuario', $error);
});

$results[] = flus_run_test('flus_guard_user_admin_mutation protects reserved admin account role', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO roles (id, nombre, slug) VALUES (1, 'Administrador', 'admin'), (2, 'Operador', 'operador')");
    $pdo->exec("INSERT INTO users (id, email, username, role_id, activo) VALUES (1, 'admin@flus.local', 'admin', 1, 1), (2, 'owner@flus.local', 'owner', 1, 1)");

    $error = flus_guard_user_admin_mutation($pdo, 2, 1, 1, false, 2, 'admin');
    flus_assert_same('La cuenta admin de resguardo mantiene su rol original.', $error);
});

$results[] = flus_run_test('flus_guard_reserved_admin_role_mutation locks reserved role permissions', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, role_id INTEGER, activo INTEGER)');
    $pdo->exec("INSERT INTO users (id, email, username, role_id, activo) VALUES (1, 'admin@flus.local', 'admin', 7, 1)");

    flus_assert_same('El rol base de la cuenta admin de resguardo no se puede editar desde Roles y Permisos.', flus_guard_reserved_admin_role_mutation($pdo, 7));
    flus_assert_same(null, flus_guard_reserved_admin_role_mutation($pdo, 8));
});
$results[] = flus_run_test('flus_normalize_sale_status normalizes empty and custom states', function (): void {
    flus_assert_same('EMITIDA', flus_normalize_sale_status(null));
    flus_assert_same('ANULADA', flus_normalize_sale_status('anulada'));
    flus_assert_same('PENDIENTE', flus_normalize_sale_status('pendiente'));
});

$results[] = flus_run_test('flus_sale_helpers keep annulled criteria consistent', function (): void {
    flus_assert_true(flus_sale_is_annulled(['estado' => 'ANULADA']));
    flus_assert_false(flus_sale_can_be_annulled(['estado' => 'ANULADA']));
    flus_assert_true(flus_sale_can_be_annulled(['estado' => null]));
    flus_assert_true(flus_sale_can_be_annulled(['estado' => 'PARCIALMENTE_ANULADA']));
    flus_assert_same("(v.estado IS NULL OR v.estado <> 'ANULADA')", flus_sale_emitida_where('v'));
    flus_assert_same("(estado IS NULL OR estado <> 'ANULADA')", flus_sale_emitida_where(''));
});

$results[] = flus_run_test('flus_calcular_estado_producto keeps product status rules consistent', function (): void {
    flus_assert_same('inactivo', flus_calcular_estado_producto([
        'activo' => 0,
        'stock' => 10,
        'stock_minimo' => 5,
    ]));
    flus_assert_same('sin', flus_calcular_estado_producto([
        'activo' => 1,
        'stock' => 0,
        'stock_minimo' => 5,
    ]));
    flus_assert_same('bajo', flus_calcular_estado_producto([
        'activo' => 1,
        'stock' => 3,
        'stock_minimo' => 5,
    ]));
    flus_assert_same('ok', flus_calcular_estado_producto([
        'activo' => 1,
        'stock' => 8,
        'stock_minimo' => 5,
    ]));
});
$results[] = flus_run_test('facturacion mode helpers normalize aliases consistently', function (): void {
    flus_assert_same('homologacion', flus_facturacion_normalizar_modo('homo'));
    flus_assert_same('produccion', flus_facturacion_normalizar_modo('prod'));
    flus_assert_same('demo', flus_facturacion_normalizar_modo('demo'));
    flus_assert_same('Demo', flus_facturacion_modo_label('demo'));
    flus_assert_same('homo', flus_facturacion_arca_env_esperado('homologacion'));
    flus_assert_same('prod', flus_facturacion_arca_env_esperado('produccion'));
    flus_assert_same('', flus_facturacion_arca_env_esperado('demo'));
});

$results[] = flus_run_test('facturacion iva and comprobante helpers stay stable', function (): void {
    flus_assert_same('RI', determinarCondIvaReceptor(['cond_iva' => 'Responsable Inscripto']));
    flus_assert_same('MT', determinarCondIvaReceptor(['cond_iva' => 'Monotributo']));
    flus_assert_same('CF', determinarCondIvaReceptor(['cond_iva' => 'Consumidor Final']));
    flus_assert_same(5, obtenerIdAlicuotaAfip(21.0));
    flus_assert_same(4, obtenerIdAlicuotaAfip(10.5));
    flus_assert_same('FA', obtenerNombreTipoComprobante(1));
    flus_assert_same('FC', obtenerNombreTipoComprobante(11));
});

$results[] = flus_run_test('facturacion manual items normalize totals and validate iva', function (): void {
    $items = flus_facturacion_normalize_manual_items([
        [
            'codigo' => 'P001',
            'descripcion' => 'Producto demo',
            'cantidad' => '2',
            'precio' => '150.50',
            'iva_porcentaje' => '21',
        ],
        [
            'descripcion' => 'Servicio exento',
            'cantidad' => '1',
            'precio' => '99.99',
            'iva_porcentaje' => '0',
        ],
    ]);

    flus_assert_same(2, count($items));
    flus_assert_same(301.0, $items[0]['subtotal']);
    flus_assert_same(99.99, $items[1]['subtotal']);

    try {
        flus_facturacion_normalize_manual_items([[
            'descripcion' => 'IVA invalido',
            'cantidad' => '1',
            'precio' => '10',
            'iva_porcentaje' => '3',
        ]]);
        throw new RuntimeException('Expected invalid IVA to throw');
    } catch (RuntimeException $e) {
        flus_assert_contains('alicuota IVA', $e->getMessage());
    }
});

$results[] = flus_run_test('compras helpers keep item discount calculations consistent', function (): void {
    $porc = flus_compra_item_metrics([
        'cantidad' => 2,
        'costo_unitario' => 100,
        'descuento_tipo' => 'PORC',
        'descuento_porc' => 10,
        'unidad_venta' => 'UNIDAD',
    ]);

    flus_assert_same(200.0, $porc['subtotal']);
    flus_assert_same(20.0, $porc['descuento_monto']);
    flus_assert_same(180.0, $porc['neto']);

    $monto = flus_compra_item_metrics([
        'cantidad' => 1,
        'costo_unitario' => 50,
        'subtotal' => 50,
        'descuento_tipo' => 'MONTO',
        'descuento' => 80,
        'unidad_venta' => 'UNIDAD',
    ]);

    flus_assert_same(50.0, $monto['descuento_monto']);
    flus_assert_same(0.0, $monto['neto']);
});

$results[] = flus_run_test('compras schema lives in migrations instead of runtime DDL', function (): void {
    $repoRoot = dirname(__DIR__);
    $migrationPath = $repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '005_compras_descuentos_schema.sql';
    $comprasPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'compras.php';

    if (!is_file($migrationPath)) {
        throw new RuntimeException('Missing compras schema migration');
    }
    if (!is_file($comprasPath)) {
        throw new RuntimeException('Missing compras.php');
    }

    $migrationSql = (string)file_get_contents($migrationPath);
    $comprasPhp = (string)file_get_contents($comprasPath);

    flus_assert_contains('ALTER TABLE compras', $migrationSql);
    flus_assert_contains('ALTER TABLE compra_items', $migrationSql);
    flus_assert_contains('005_compras_descuentos_schema.sql', $comprasPhp);
    flus_assert_not_contains('function flus_compras_ensure_schema', $comprasPhp);
    flus_assert_not_contains('ALTER TABLE compras ADD COLUMN', $comprasPhp);
    flus_assert_not_contains('ALTER TABLE compra_items ADD COLUMN', $comprasPhp);
});

$results[] = flus_run_test('pagination helper is centralized in src helpers', function (): void {
    $repoRoot = dirname(__DIR__);
    $helpersPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helpers.php';
    $pages = [
        'public/caja_historial.php',
        'public/movimientos.php',
        'public/stock.php',
        'public/ventas.php',
    ];

    if (!is_file($helpersPath)) {
        throw new RuntimeException('Missing shared helpers.php');
    }

    $helpersPhp = (string)file_get_contents($helpersPath);
    flus_assert_contains('function render_pagination', $helpersPhp);

    foreach ($pages as $pageFile) {
        $pagePath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pageFile);
        if (!is_file($pagePath)) {
            throw new RuntimeException('Missing page: ' . $pageFile);
        }

        $pagePhp = (string)file_get_contents($pagePath);
        flus_assert_not_contains('function render_pagination', $pagePhp, $pageFile);
    }
});
$results[] = flus_run_test('schema checks are centralized outside public pages', function (): void {
    $repoRoot = dirname(__DIR__);
    $schemaPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db_schema.php';
    $pages = [
        'public/proveedores.php',
        'public/productos.php',
        'public/precios_historial.php',
    ];

    if (!is_file($schemaPath)) {
        throw new RuntimeException('Missing db_schema.php');
    }

    $schemaPhp = (string)file_get_contents($schemaPath);
    flus_assert_contains('function flus_table_columns', $schemaPhp);
    flus_assert_contains('SHOW COLUMNS FROM', $schemaPhp);

    foreach ($pages as $pageFile) {
        $pagePath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pageFile);
        if (!is_file($pagePath)) {
            throw new RuntimeException('Missing page: ' . $pageFile);
        }

        $pagePhp = (string)file_get_contents($pagePath);
        flus_assert_not_contains('SHOW COLUMNS', $pagePhp, $pageFile);
    }
});

$results[] = flus_run_test('diagnostics access keeps dedicated permission compatibility', function (): void {
    $repoRoot = dirname(__DIR__);
    $installPath = $repoRoot . DIRECTORY_SEPARATOR . 'install.sql';
    $migrationPath = $repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '006_diagnostics_permission.sql';
    $authPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'auth.php';
    $diagPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'diagnostico.php';
    $diagDownloadPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'diagnostico_download.php';
    $navPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'nav.php';

    foreach ([$installPath, $migrationPath, $authPath, $diagPath, $diagDownloadPath, $navPath] as $requiredPath) {
        if (!is_file($requiredPath)) {
            throw new RuntimeException('Missing file: ' . $requiredPath);
        }
    }

    $installSql = (string)file_get_contents($installPath);
    $migrationSql = (string)file_get_contents($migrationPath);
    $authPhp = (string)file_get_contents($authPath);
    $diagPhp = (string)file_get_contents($diagPath);
    $diagDownloadPhp = (string)file_get_contents($diagDownloadPath);
    $navPhp = (string)file_get_contents($navPath);

    flus_assert_contains('ver_diagnostico', $installSql);
    flus_assert_contains('ver_diagnostico', $migrationSql);
    flus_assert_contains('gestionar_backups', $migrationSql);
    flus_assert_contains('function user_can_access_diagnostics', $authPhp);
    flus_assert_contains('function require_diagnostics_permission', $authPhp);
    flus_assert_contains('require_diagnostics_permission();', $diagPhp);
    flus_assert_contains('user_can_access_diagnostics()', $diagDownloadPhp);
    flus_assert_contains("\$can('ver_diagnostico')", $navPhp);
});

$results[] = flus_run_test('support schema is versioned for clean installs and upgrades', function (): void {
    $repoRoot = dirname(__DIR__);
    $installPath = $repoRoot . DIRECTORY_SEPARATOR . 'install.sql';
    $migrationPath = $repoRoot . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '007_support_modules_schema.sql';
    $manualLibPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'facturacion_manual_lib.php';

    foreach ([$installPath, $migrationPath, $manualLibPath] as $requiredPath) {
        if (!is_file($requiredPath)) {
            throw new RuntimeException('Missing file: ' . $requiredPath);
        }
    }

    $installSql = (string)file_get_contents($installPath);
    $migrationSql = (string)file_get_contents($migrationPath);
    $manualLibPhp = (string)file_get_contents($manualLibPath);

    flus_assert_contains('flusadmin123', $installSql);
    flus_assert_contains('yPokhUEft2w2kngTRjoBkuaq7cwygVwwfYA.oY.lKVH7Sxytlkkde', $installSql);
    flus_assert_contains('ver_diagnostico', $installSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS factura_manual_items', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS producto_reposicion', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS producto_precios_hist', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS inventario_sesiones', $migrationSql);
    flus_assert_contains('CREATE TABLE IF NOT EXISTS cuenta_corriente_movimientos', $migrationSql);
    flus_assert_contains('migrations/007_support_modules_schema.sql', $manualLibPhp);
    flus_assert_not_contains('CREATE TABLE IF NOT EXISTS factura_manual_items', $manualLibPhp);
});

$results[] = flus_run_test('technical panel access stays centralized and visible in nav', function (): void {
    $repoRoot = dirname(__DIR__);
    $authPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'auth.php';
    $tecnicoPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'tecnico.php';
    $navPath = $repoRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'nav.php';

    foreach ([$authPath, $tecnicoPath, $navPath] as $requiredPath) {
        if (!is_file($requiredPath)) {
            throw new RuntimeException('Missing file: ' . $requiredPath);
        }
    }

    $authPhp = (string)file_get_contents($authPath);
    $tecnicoPhp = (string)file_get_contents($tecnicoPath);
    $navPhp = (string)file_get_contents($navPath);

    flus_assert_contains('function user_can_access_technical_panel', $authPhp);
    flus_assert_contains('function require_technical_permission', $authPhp);
    flus_assert_contains('require_technical_permission();', $tecnicoPhp);
    flus_assert_contains('Estado actual', $tecnicoPhp);
    flus_assert_contains('Operacion tecnica', $tecnicoPhp);
    flus_assert_contains('user_can_access_technical_panel', $navPhp);
});
$results[] = flus_run_test('admin pages rely on bootstrap session startup', function (): void {
    $repoRoot = dirname(__DIR__);
    $pages = [
        'public/roles.php',
        'public/rol_guardar.php',
        'public/rol_permisos.php',
        'public/usuarios.php',
        'public/usuario_editar.php',
        'public/usuario_guardar.php',
        'public/usuario_nuevo.php',
        'public/tecnico.php',
        'public/diagnostico_download.php',
    ];

    foreach ($pages as $pageFile) {
        $pagePath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pageFile);
        if (!is_file($pagePath)) {
            throw new RuntimeException('Missing page: ' . $pageFile);
        }

        $pagePhp = (string)file_get_contents($pagePath);
        flus_assert_not_contains('session_start(', $pagePhp, $pageFile);
        flus_assert_not_contains('startSecureSession(', $pagePhp, $pageFile);
    }
});

$results[] = flus_run_test('permissions stay aligned across code, install, migrations and admin role', function (): void {
    $repoRoot = dirname(__DIR__);
    $publicPath = $repoRoot . DIRECTORY_SEPARATOR . 'public';
    $installPath = $repoRoot . DIRECTORY_SEPARATOR . 'install.sql';
    $migrationsPath = $repoRoot . DIRECTORY_SEPARATOR . 'migrations';

    foreach ([$publicPath, $installPath, $migrationsPath] as $requiredPath) {
        if (!file_exists($requiredPath)) {
            throw new RuntimeException('Missing file or directory: ' . $requiredPath);
        }
    }

    $codePerms = flus_collect_permission_slugs_from_public($publicPath);
    $installPerms = flus_collect_permission_slugs_from_sql_file($installPath);
    $migrationPerms = flus_collect_permission_slugs_from_migrations($migrationsPath);

    $pdo = flus_open_project_pdo($repoRoot);
    $dbPerms = flus_fetch_first_column($pdo, 'SELECT slug FROM permissions ORDER BY slug');
    $adminPerms = flus_fetch_first_column(
        $pdo,
        "SELECT p.slug
         FROM permissions p
         INNER JOIN role_permission rp ON rp.permission_id = p.id
         INNER JOIN roles r ON r.id = rp.role_id
         WHERE LOWER(r.slug) IN ('admin', 'administrador')
         ORDER BY p.slug"
    );

    $missingInInstall = array_values(array_diff($codePerms, $installPerms));
    $missingInMigrations = array_values(array_diff($codePerms, $migrationPerms));
    $missingInDb = array_values(array_diff($codePerms, $dbPerms));
    $missingInAdmin = array_values(array_diff($codePerms, $adminPerms));

    if ($missingInInstall !== [] || $missingInMigrations !== [] || $missingInDb !== [] || $missingInAdmin !== []) {
        throw new RuntimeException(
            'Permisos desalineados'
            . ' | code->install: ' . flus_format_slug_diff($missingInInstall)
            . ' | code->migrations: ' . flus_format_slug_diff($missingInMigrations)
            . ' | code->db: ' . flus_format_slug_diff($missingInDb)
            . ' | code->admin: ' . flus_format_slug_diff($missingInAdmin)
        );
    }
});

$results[] = flus_run_test('fiscal scaffold bootstrap loads cleanly', function (): void {
    $repoRoot = dirname(__DIR__);
    require_once $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Fiscal' . DIRECTORY_SEPARATOR . 'bootstrap.php';

    flus_assert_true(interface_exists('NotaCreditoService', false));
    flus_assert_true(interface_exists('FiscalRecoveryService', false));
    flus_assert_true(interface_exists('FacturaFiscalRepository', false));
    flus_assert_true(interface_exists('AnulacionFiscalCoordinator', false));
    flus_assert_true(class_exists('EmitirNotaCreditoCommand', false));
    flus_assert_true(class_exists('EmitirNotaCreditoResult', false));
    flus_assert_true(class_exists('RecoveryResult', false));
    flus_assert_true(class_exists('AnulacionFiscalOutcome', false));
    flus_assert_true(class_exists('PdoFacturaFiscalRepository', false));
    flus_assert_true(class_exists('StubNotaCreditoService', false));
    flus_assert_true(class_exists('StubFiscalRecoveryService', false));
    flus_assert_true(class_exists('StubAnulacionFiscalCoordinator', false));
    flus_assert_true(class_exists('ArcaNotaCreditoService', false));
    flus_assert_true(class_exists('DbAnulacionFiscalCoordinator', false));
});

$results[] = flus_run_test('facturacion arca degradation keeps availability criteria consistent', function (): void {
    flus_assert_true(
        flus_facturacion_arca_is_availability_error(
            "Error invocando WSAA: SOAP-ERROR: Parsing WSDL: Couldn't load from 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL'"
        )
    );
    flus_assert_false(flus_facturacion_arca_is_availability_error('[10016] Comprobante duplicado'));
    flus_assert_same(
        'No se puede emitir ahora porque ARCA no responde.',
        flus_facturacion_humanizar_error_arca(
            "Error invocando WSAA: SOAP-ERROR: Parsing WSDL: Couldn't load from 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL'"
        )
    );

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE app_config (k TEXT PRIMARY KEY, v TEXT)');
    $pdo->exec("INSERT INTO app_config (k, v) VALUES ('facturacion_habilitada', '0')");

    $estado = flus_facturacion_arca_status_current($pdo, 'demo', false);
    flus_assert_same('not_required', $estado['status']);
    flus_assert_true((bool)$estado['can_emit']);
});

$results[] = flus_run_test('EmitirNotaCreditoCommand normalizes partial items safely', function (): void {
    require_once dirname(__DIR__) . '/src/Fiscal/bootstrap.php';

    $cmd = EmitirNotaCreditoCommand::fromArray([
        'venta_id' => 10,
        'venta_anulacion_id' => 20,
        'usuario_id' => 30,
        'scope' => 'partial',
        'modo' => 'HOMO',
        'legacy_tolerance' => '0.07',
        'partial_items' => [
            ['item_id' => 5, 'cantidad' => '1.250'],
            ['item_id' => 5, 'cantidad' => '0.250'],
            ['item_id' => 9, 'cantidad' => '2'],
            ['item_id' => 0, 'cantidad' => '9'],
        ],
    ]);

    flus_assert_same('PARTIAL', $cmd->scope);
    flus_assert_same('homo', $cmd->modo);
    flus_assert_same(0.07, $cmd->legacyTolerance);
    flus_assert_same(2, count($cmd->partialItems));
    flus_assert_same(5, $cmd->partialItems[0]['item_id']);
    flus_assert_same(1.5, $cmd->partialItems[0]['cantidad']);
    flus_assert_same(9, $cmd->partialItems[1]['item_id']);
    flus_assert_same(2.0, $cmd->partialItems[1]['cantidad']);
});

$failed = array_values(array_filter($results, static fn(array $result): bool => !$result['ok']));

foreach ($results as $result) {
    $prefix = $result['ok'] ? '[OK] ' : '[FAIL] ';
    echo $prefix . $result['name'] . ' - ' . $result['message'] . PHP_EOL;
}

echo PHP_EOL;
echo 'Total: ' . count($results) . ', failed: ' . count($failed) . PHP_EOL;

exit(count($failed) > 0 ? 1 : 0);
