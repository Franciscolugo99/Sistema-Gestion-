<?php
// public/tecnico.php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_technical_permission();

csrf_token();

function tecnico_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function tecnico_status_file(): string
{
    $dir = FLUS_ROOT . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/technical_smoke_status.json';
}

function tecnico_load_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function tecnico_save_json_file(string $path, array $data): void
{
    @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function tecnico_is_php_cli_binary(string $path): bool
{
    if ($path === '' || !is_file($path)) {
        return false;
    }

    $name = strtolower(basename($path));
    return in_array($name, ['php.exe', 'php-cli.exe', 'php-cgi.exe'], true);
}

function tecnico_detect_php_binary(): ?string
{
    $phpBinary = defined('PHP_BINARY') ? (string) PHP_BINARY : '';

    $candidates = array_values(array_unique(array_filter([
        'C:/xampp/php/php.exe',
        dirname($phpBinary) . '/php.exe',
        $phpBinary,
    ])));

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && tecnico_is_php_cli_binary($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function tecnico_translate_smoke_output(string $stdout): string
{
    $map = [
        '[OK]' => '[OK]',
        '[FAIL]' => '[FALLA]',
        'Total:' => 'Total:',
        'failed:' => 'fallidas:',
        'sh_quote handles Windows quoting' => 'sh_quote maneja comillas de Windows',
        'backup_restore_in_progress detects active lock' => 'backup_restore_in_progress detecta bloqueo activo',
        'flus_make_shareable_path masks FLUS_ROOT' => 'flus_make_shareable_path oculta FLUS_ROOT',
        'flus_sanitize_log_line redacts obvious secrets' => 'flus_sanitize_log_line oculta secretos evidentes',
        'flus_get_sanitized_config masks shareable db values' => 'flus_get_sanitized_config oculta valores compartibles de BD',
        'flus_build_diagnostic_overview escalates active problems' => 'flus_build_diagnostic_overview eleva problemas activos',
        'flus_format_bytes keeps current UI format' => 'flus_format_bytes mantiene el formato actual de la UI',
        'flus_is_critical_role recognizes protected admin slugs' => 'flus_is_critical_role reconoce slugs protegidos de administrador',
        'flus_validate_user_payload checks duplicates and role existence' => 'flus_validate_user_payload valida duplicados y existencia de rol',
        'flus_guard_user_admin_mutation blocks self deactivation' => 'flus_guard_user_admin_mutation bloquea la auto desactivacion',
        'flus_guard_user_admin_mutation protects reserved admin account role' => 'flus_guard_user_admin_mutation protege el rol de la cuenta admin de resguardo',
        'flus_guard_reserved_admin_role_mutation locks reserved role permissions' => 'flus_guard_reserved_admin_role_mutation bloquea permisos del rol admin de resguardo',
        'flus_normalize_sale_status normalizes empty and custom states' => 'flus_normalize_sale_status normaliza estados vacios y personalizados',
        'flus_sale_helpers keep annulled criteria consistent' => 'flus_sale_helpers mantiene consistente el criterio de anulacion',
        'flus_calcular_estado_producto keeps product status rules consistent' => 'flus_calcular_estado_producto mantiene consistentes las reglas de estado de producto',
        'facturacion mode helpers normalize aliases consistently' => 'los helpers de facturacion normalizan alias de modo de forma consistente',
        'facturacion iva and comprobante helpers stay stable' => 'los helpers de IVA y comprobante de facturacion se mantienen estables',
        'facturacion manual items normalize totals and validate iva' => 'los items de facturacion manual normalizan totales y validan IVA',
        'compras schema lives in migrations instead of runtime DDL' => 'compras usa migraciones y no DDL en runtime',
        'pagination helper is centralized in src helpers' => 'la paginacion sigue centralizada en src/helpers.php',
        'schema checks are centralized outside public pages' => 'los chequeos de esquema estan centralizados fuera de las paginas publicas',
        'diagnostics access keeps dedicated permission compatibility' => 'diagnostico conserva compatibilidad con su permiso dedicado',
        'technical panel access stays centralized and visible in nav' => 'el acceso al panel tecnico sigue centralizado y visible en la navegacion',
        'admin pages rely on bootstrap session startup' => 'las paginas admin usan bootstrap para iniciar sesion',
    ];

    return strtr($stdout, $map);
}

function tecnico_parse_smoke_output(string $stdout, string $stderr, int $exitCode, string $phpBinary, float $durationMs): array
{
    $total = null;
    $failed = null;
    if (preg_match('/Total:\s*(\d+),\s*failed:\s*(\d+)/i', $stdout, $m)) {
        $total = (int)$m[1];
        $failed = (int)$m[2];
    }

    if ($total === null) {
        $okCount = preg_match_all('/^\[OK\]/m', $stdout);
        $failCount = preg_match_all('/^\[FAIL\]/m', $stdout);
        $total = (int)$okCount + (int)$failCount;
        $failed = (int)$failCount;
    }

    return [
        'ran_at' => date('c'),
        'exit_code' => $exitCode,
        'ok' => $exitCode === 0,
        'total' => $total,
        'failed' => $failed,
        'passed' => max(0, (int)$total - (int)$failed),
        'php_binary' => $phpBinary,
        'duration_ms' => round($durationMs, 1),
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}

function tecnico_run_smoke_tests(?string &$error = null): ?array
{
    $phpBinary = tecnico_detect_php_binary();
    if ($phpBinary === null) {
        $error = 'No se encontro un binario de PHP CLI para correr las pruebas rapidas.';
        return null;
    }

    $script = FLUS_ROOT . '/tests/smoke.php';
    if (!is_file($script)) {
        $error = 'No existe tests/smoke.php en el proyecto.';
        return null;
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $command = [$phpBinary, $script];
    $start = microtime(true);
    $process = @proc_open($command, $descriptorSpec, $pipes, FLUS_ROOT, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        $error = 'No se pudo iniciar el proceso para ejecutar las pruebas rapidas.';
        return null;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $durationMs = (microtime(true) - $start) * 1000;

    return tecnico_parse_smoke_output((string)$stdout, (string)$stderr, (int)$exitCode, $phpBinary, $durationMs);
}

function tecnico_read_text(string $path): string
{
    $raw = @file_get_contents($path);
    return is_string($raw) ? trim($raw) : '';
}

function tecnico_count_php_files(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $count = 0;
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && strtolower($file->getExtension()) === 'php') {
            $count++;
        }
    }
    return $count;
}

function tecnico_list_php_files(string $dir, bool $recursive = true): array
{
    if (!is_dir($dir)) {
        return [];
    }

    if (!$recursive) {
        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);
        return $files;
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

function tecnico_count_pattern_matches(array $paths, string $pattern): int
{
    $total = 0;
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $matches = preg_match_all($pattern, $raw);
        if ($matches !== false) {
            $total += (int)$matches;
        }
    }
    return $total;
}

function tecnico_files_with_pattern(array $paths, string $pattern): array
{
    $matches = [];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        if (preg_match($pattern, $raw) === 1) {
            $matches[] = $path;
        }
    }
    return $matches;
}

function tecnico_visible_source_for_encoding_check(string $path): string
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $tokens = token_get_all($raw);
    $visible = '';

    foreach ($tokens as $token) {
        if (is_string($token)) {
            $visible .= $token;
            continue;
        }

        [$id, $text] = $token;
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }

        if ($id === T_INLINE_HTML) {
            $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? $text;
            $text = preg_replace('/\/\*.*?\*\//s', ' ', $text) ?? $text;
            $text = preg_replace('/^\s*\/\/.*$/m', ' ', $text) ?? $text;
        }

        $visible .= $text;
    }

    return $visible;
}

function tecnico_files_with_visible_mojibake(array $paths, string $pattern): array
{
    $matches = [];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }

        $visible = tecnico_visible_source_for_encoding_check($path);
        if ($visible !== '' && preg_match($pattern, $visible) === 1) {
            $matches[] = $path;
        }
    }

    return $matches;
}

function tecnico_relative_path(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', FLUS_ROOT), '/') . '/';
    return str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : basename($path);
}

function tecnico_build_check(string $label, bool $ok, string $okDetail, string $failDetail): array
{
    return [
        'label' => $label,
        'ok' => $ok,
        'detail' => $ok ? $okDetail : $failDetail,
    ];
}

$pageTitle = 'Tecnico - FLUS';
$currentSection = 'tecnico';
$extraCss = ['assets/css/tecnico.css'];
$extraJs = [];

$info = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_verify($token)) {
        $error = 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.';
    } else {
        $action = (string)($_POST['accion'] ?? '');
        if ($action === 'run_smoke') {
            $runError = null;
            $result = tecnico_run_smoke_tests($runError);
            if ($result === null) {
                $error = $runError ?: 'No se pudieron ejecutar las pruebas rapidas.';
            } else {
                tecnico_save_json_file(tecnico_status_file(), $result);
                $info = $result['ok']
                    ? 'Pruebas rapidas ejecutadas correctamente.'
                    : 'Pruebas rapidas ejecutadas con fallas. Revisa la salida.';
            }
        }
    }
}

$smoke = tecnico_load_json_file(tecnico_status_file());
$publicPages = tecnico_list_php_files(__DIR__, false);
$publicScanPages = array_values(array_filter(
    $publicPages,
    static fn(string $path): bool => basename($path) !== 'tecnico.php'
));
$partialPages = tecnico_list_php_files(__DIR__ . '/partials');
$publicPageCount = count($publicPages);
$apiPageCount = tecnico_count_php_files(__DIR__ . '/api');
$phpBinary = tecnico_detect_php_binary();

$paginationPages = array_map(
    static fn(string $path): string => FLUS_ROOT . '/' . $path,
    ['public/caja_historial.php', 'public/movimientos.php', 'public/stock.php', 'public/ventas.php']
);
$schemaPages = array_map(
    static fn(string $path): string => FLUS_ROOT . '/' . $path,
    ['public/proveedores.php', 'public/productos.php', 'public/precios_historial.php']
);
$adminPages = array_map(
    static fn(string $path): string => FLUS_ROOT . '/' . $path,
    [
        'public/roles.php',
        'public/rol_guardar.php',
        'public/rol_permisos.php',
        'public/usuarios.php',
        'public/usuario_editar.php',
        'public/usuario_guardar.php',
        'public/usuario_nuevo.php',
        'public/tecnico.php',
        'public/diagnostico_download.php',
    ]
);
$helpersPath = FLUS_ROOT . '/src/helpers.php';
$schemaHelperPath = FLUS_ROOT . '/src/db_schema.php';

$paginationDuplicates = tecnico_count_pattern_matches($paginationPages, '/function\s+render_pagination\s*\(/');
$publicRuntimeDdlCount = tecnico_count_pattern_matches($publicScanPages, '/ALTER\\s+TABLE/i');
$publicMojibakeFiles = tecnico_files_with_visible_mojibake(
    array_merge($publicScanPages, $partialPages),
    '/(\x{00C3}|\x{00E2}|\x{00F0}|\x{00C2}|\x{FFFD})/u'
);
$schemaIntrospectionCount = tecnico_count_pattern_matches($schemaPages, '/SHOW\s+COLUMNS/i');
$adminSessionStartCount = tecnico_count_pattern_matches($adminPages, '/session_start\s*\(|startSecureSession\s*\(/i');
$smokeSuiteGreen = !empty($smoke['ok']) && (int)($smoke['failed'] ?? 0) === 0;
$smokeTotal = (int)($smoke['total'] ?? 0);
$smokePassed = (int)($smoke['passed'] ?? 0);

$baseChecks = [
    tecnico_build_check(
        'Smoke estable',
        $smokeSuiteGreen,
        'La ultima corrida guardada paso completa: ' . $smokePassed . '/' . $smokeTotal . ' OK.',
        $smoke
            ? 'La ultima corrida guardada tiene fallas o quedo incompleta.'
            : 'Todavia no hay corrida guardada de smoke tests.'
    ),
    tecnico_build_check(
        'Paginacion centralizada',
        is_file($helpersPath) && tecnico_count_pattern_matches([$helpersPath], '/function\s+render_pagination\s*\(/') === 1 && $paginationDuplicates === 0,
        'Las pantallas clave usan el helper compartido en src/helpers.php.',
        'Aparecieron duplicados de render_pagination en paginas publicas.'
    ),
    tecnico_build_check(
        'Sin DDL web en public/',
        $publicRuntimeDdlCount === 0,
        'No hay ALTER TABLE ejecutandose desde paginas publicas.',
        'Se detectaron sentencias ALTER TABLE dentro de public/.'
    ),
    tecnico_build_check(
        'Esquema concentrado',
        is_file($schemaHelperPath) && $schemaIntrospectionCount === 0,
        'Los chequeos de columnas viven en src/db_schema.php.',
        'Hay paginas publicas haciendo SHOW COLUMNS por su cuenta.'
    ),
    tecnico_build_check(
        'Sesion admin unificada',
        $adminSessionStartCount === 0,
        'Roles, usuarios, diagnostico y tecnico dependen de bootstrap.',
        'Quedaron inicios manuales de sesion o wrappers legacy dentro del bloque admin.'
    ),
    tecnico_build_check(
        'UI sin texto roto',
        count($publicMojibakeFiles) === 0,
        'No se detecto mojibake visible en paginas publicas ni en parciales.',
        'Hay archivos con texto visible roto: ' . implode(', ', array_map('tecnico_relative_path', array_slice($publicMojibakeFiles, 0, 4))) . (count($publicMojibakeFiles) > 4 ? '...' : '') . '.'
    ),
];
$healthChecksOk = count(array_filter($baseChecks, static fn(array $check): bool => !empty($check['ok'])));
$healthChecksTotal = count($baseChecks);
$healthChipClass = $healthChecksOk === $healthChecksTotal ? 'ok' : ($healthChecksOk >= max(1, $healthChecksTotal - 1) ? 'warning' : 'error');

$quickLinks = [];
if (function_exists('user_can_access_diagnostics') && user_can_access_diagnostics()) {
    $quickLinks[] = [
        'href' => 'diagnostico.php',
        'title' => 'Diagnostico',
        'desc' => 'Revisar salud del sistema, logs y paquetes compartibles.',
    ];
}
if (function_exists('user_has_permission') && user_has_permission('gestionar_backups')) {
    $quickLinks[] = [
        'href' => 'backups.php',
        'title' => 'Backups',
        'desc' => 'Crear, validar o restaurar respaldos operativos.',
    ];
}
if (function_exists('user_has_permission') && user_has_permission('administrar_usuarios')) {
    $quickLinks[] = [
        'href' => 'usuarios.php',
        'title' => 'Usuarios',
        'desc' => 'Revisar accesos, roles y permisos activos.',
    ];
}

require __DIR__ . '/partials/header.php';
?>

<div class="panel tecnico-panel">
  <header class="panel-head page-header module-header">
    <div class="page-header-main module-header-main">
      <div class="module-header-hero">
        <span class="module-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <path d="M14.7 6.3a1 1 0 0 1 1.4 0l1.6 1.6a1 1 0 0 1 0 1.4l-7.9 7.9-3.2.8.8-3.2 7.3-7.3Z"/>
            <path d="M16 8l-1-1"/>
            <path d="M2 21h20"/>
          </svg>
        </span>
        <div class="module-header-copy">
          <span class="module-eyebrow">Soporte avanzado</span>
          <h1 class="page-title">Panel Tecnico</h1>
          <p class="page-sub panel-subtitle">Estado técnico del backoffice, saneamiento base y accesos operativos desde una sola pantalla.</p>
        </div>
      </div>
    </div>
    <div class="tecnico-actions module-header-actions">
      <form method="post" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= tecnico_h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="run_smoke">
        <button class="btn btn-primary" type="submit">Correr pruebas rapidas</button>
      </form>
      <?php if (function_exists('user_can_access_diagnostics') && user_can_access_diagnostics()): ?>
        <a href="diagnostico.php" class="btn btn-ghost">Abrir diagnostico</a>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($info): ?>
    <div class="alert alert-ok"><span><?= tecnico_h($info) ?></span></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-err"><span><?= tecnico_h($error) ?></span></div>
  <?php endif; ?>

  <div class="tecnico-stats">
    <div class="tecnico-card stat-card">
      <div class="stat-label">Pruebas rapidas</div>
      <div class="stat-value <?= !empty($smoke['ok']) ? 'ok' : (!empty($smoke) ? 'error' : '') ?>">
        <?php if (!$smoke): ?>
          Sin ejecutar
        <?php elseif (!empty($smoke['ok'])): ?>
          <?= (int)($smoke['passed'] ?? 0) ?>/<?= (int)($smoke['total'] ?? 0) ?> OK
        <?php else: ?>
          <?= (int)($smoke['failed'] ?? 0) ?> falla(s)
        <?php endif; ?>
      </div>
      <div class="stat-note">
        <?= $smoke ? tecnico_h(date('Y-m-d H:i:s', strtotime((string)$smoke['ran_at']))) : 'Todavia no hay corrida guardada.' ?>
      </div>
    </div>

    <div class="tecnico-card stat-card">
      <div class="stat-label">Salud base</div>
      <div class="stat-value <?= $healthChipClass === 'ok' ? 'ok' : ($healthChipClass === 'error' ? 'error' : '') ?>">
        <?= $healthChecksOk ?>/<?= $healthChecksTotal ?>
      </div>
      <div class="stat-note">Chequeos de saneamiento activos en el repo.</div>
    </div>
  </div>

  <div class="tecnico-grid">
    <section class="tecnico-card">
      <div class="section-head">
        <h2>Estado actual</h2>
        <span class="chip <?= $healthChipClass ?>"><?= $healthChecksOk ?>/<?= $healthChecksTotal ?> checks OK</span>
      </div>
      <p class="tecnico-copy">Este bloque mira el codigo actual del proyecto y resume si la base tecnica quedo alineada con el saneamiento que venimos haciendo.</p>
      <div class="health-list">
        <?php foreach ($baseChecks as $check): ?>
          <article class="health-item <?= !empty($check['ok']) ? 'is-ok' : 'is-warning' ?>">
            <div class="health-item-head">
              <h3><?= tecnico_h($check['label']) ?></h3>
              <span class="chip <?= !empty($check['ok']) ? 'ok' : 'warning' ?>">
                <?= !empty($check['ok']) ? 'OK' : 'Revisar' ?>
              </span>
            </div>
            <p><?= tecnico_h($check['detail']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="tecnico-card">
      <div class="section-head">
        <h2>Pruebas rapidas</h2>
        <span class="chip <?= !empty($smoke['ok']) ? 'ok' : (!empty($smoke) ? 'error' : 'warning') ?>">
          <?= !$smoke ? 'Pendiente' : (!empty($smoke['ok']) ? 'OK' : 'Con fallas') ?>
        </span>
      </div>

      <div class="meta-grid">
        <div><strong>Total:</strong> <?= (int)($smoke['total'] ?? 0) ?></div>
        <div><strong>Fallidas:</strong> <?= (int)($smoke['failed'] ?? 0) ?></div>
        <div><strong>Duracion:</strong> <?= $smoke ? tecnico_h((string)($smoke['duration_ms'] ?? '0')) . ' ms' : '-' ?></div>
        <div><strong>Codigo de salida:</strong> <?= $smoke ? (int)($smoke['exit_code'] ?? 0) : '-' ?></div>
        <div><strong>PHP CLI:</strong> <?= tecnico_h($phpBinary ?? 'No detectado') ?></div>
        <div><strong>Pantallas relevadas:</strong> <?= (int)$publicPageCount ?> public / <?= (int)$apiPageCount ?> api</div>
      </div>

      <details class="tecnico-details">
        <summary>Ver salida completa del smoke</summary>
        <pre class="terminal-output"><?= tecnico_h(tecnico_translate_smoke_output($smoke['stdout'] ?? 'Todavia no hay salida registrada.')) ?></pre>
      </details>
      <?php if (!empty($smoke['stderr'])): ?>
        <details class="tecnico-details">
          <summary>Ver stderr</summary>
          <pre class="terminal-output terminal-output--error"><?= tecnico_h((string)$smoke['stderr']) ?></pre>
        </details>
      <?php endif; ?>
    </section>

    <section class="tecnico-card">
      <div class="section-head">
        <h2>Operacion tecnica</h2>
        <span class="chip chip-inline"><?= count($quickLinks) ?> accesos</span>
      </div>
      <p class="tecnico-copy">Atajos para las herramientas que hoy siguen vivas en soporte y administracion.</p>
      <div class="tecnico-link-grid">
        <?php foreach ($quickLinks as $link): ?>
          <a href="<?= tecnico_h($link['href']) ?>" class="tecnico-link-card">
            <strong><?= tecnico_h($link['title']) ?></strong>
            <span><?= tecnico_h($link['desc']) ?></span>
          </a>
        <?php endforeach; ?>
        <div class="tecnico-link-card tecnico-link-card--muted">
          <strong>API publica</strong>
          <span><?= (int)$apiPageCount ?> endpoints PHP relevados en `public/api/`.</span>
        </div>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
