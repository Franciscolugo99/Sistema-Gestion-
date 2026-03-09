<?php
// public/tecnico.php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$canTecnico = function_exists('user_has_permission')
    ? (user_has_permission('administrar_config') || user_has_permission('gestionar_backups'))
    : false;

if (!$canTecnico) {
    http_response_code(403);
    echo 'No tenes permisos para acceder a esta seccion.';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

function tecnico_extract_headings(string $markdown, string $prefix): array
{
    $items = [];
    foreach (preg_split('/\R/', $markdown) as $line) {
        if (str_starts_with($line, $prefix . ' ')) {
            $items[] = trim(substr($line, strlen($prefix) + 1));
        }
    }
    return $items;
}

function tecnico_render_markdownish(string $markdown): string
{
    $lines = preg_split('/\R/', $markdown) ?: [];
    $html = [];
    $listType = null;

    $closeList = static function () use (&$html, &$listType): void {
        if ($listType !== null) {
            $html[] = '</' . $listType . '>';
            $listType = null;
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $closeList();
            continue;
        }

        if (str_starts_with($trimmed, '### ')) {
            $closeList();
            $html[] = '<h3>' . tecnico_h(substr($trimmed, 4)) . '</h3>';
            continue;
        }
        if (str_starts_with($trimmed, '## ')) {
            $closeList();
            $html[] = '<h2>' . tecnico_h(substr($trimmed, 3)) . '</h2>';
            continue;
        }
        if (str_starts_with($trimmed, '# ')) {
            $closeList();
            $html[] = '<h1>' . tecnico_h(substr($trimmed, 2)) . '</h1>';
            continue;
        }
        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
            if ($listType !== 'ol') {
                $closeList();
                $html[] = '<ol>';
                $listType = 'ol';
            }
            $html[] = '<li>' . tecnico_h($m[1]) . '</li>';
            continue;
        }
        if (str_starts_with($trimmed, '- ')) {
            if ($listType !== 'ul') {
                $closeList();
                $html[] = '<ul>';
                $listType = 'ul';
            }
            $html[] = '<li>' . tecnico_h(substr($trimmed, 2)) . '</li>';
            continue;
        }

        $closeList();
        $html[] = '<p>' . tecnico_h($trimmed) . '</p>';
    }

    $closeList();
    return implode("\n", $html);
}

$pageTitle = 'Tecnico - FLUS';
$currentSection = 'tecnico';
$extraCss = ['assets/css/tecnico.css'];
$extraJs = [];

$info = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
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
$roadmapPath = FLUS_ROOT . '/docs/ROADMAP_POS.md';
$inventoryPath = FLUS_ROOT . '/docs/LEGACY_API_INVENTORY.md';
$roadmapText = tecnico_read_text($roadmapPath);
$inventoryText = tecnico_read_text($inventoryPath);
$publicPageCount = count(glob(__DIR__ . '/*.php') ?: []);
$apiPageCount = tecnico_count_php_files(__DIR__ . '/api');
$roadmapStages = tecnico_extract_headings($roadmapText, '##');
$inventoryDomains = tecnico_extract_headings($inventoryText, '####');
$phpBinary = tecnico_detect_php_binary();

require __DIR__ . '/partials/header.php';
?>

<div class="panel tecnico-panel">
  <div class="panel-head">
    <div>
      <h1>Panel Tecnico</h1>
      <p class="panel-subtitle">Hoja de ruta, inventario legacy/API y ejecucion de pruebas rapidas desde la UI.</p>
    </div>
    <div class="tecnico-actions">
      <form method="post" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= tecnico_h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="run_smoke">
        <button class="btn btn-primary" type="submit">Correr pruebas rapidas</button>
      </form>
    </div>
  </div>

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
      <div class="stat-label">Pantallas legacy</div>
      <div class="stat-value"><?= (int)$publicPageCount ?></div>
      <div class="stat-note">Archivos PHP en `public/`.</div>
    </div>

    <div class="tecnico-card stat-card">
      <div class="stat-label">Endpoints de API</div>
      <div class="stat-value"><?= (int)$apiPageCount ?></div>
      <div class="stat-note">Archivos PHP en `public/api/`.</div>
    </div>

    <div class="tecnico-card stat-card">
      <div class="stat-label">Binario PHP</div>
      <div class="stat-value small"><?= tecnico_h($phpBinary ?? 'No detectado') ?></div>
      <div class="stat-note">CLI usado para ejecutar `tests/smoke.php`.</div>
    </div>
  </div>

  <div class="tecnico-grid">
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
      </div>

      <pre class="terminal-output"><?= tecnico_h(tecnico_translate_smoke_output($smoke['stdout'] ?? 'Todavia no hay salida registrada.')) ?></pre>
      <?php if (!empty($smoke['stderr'])): ?>
        <details class="tecnico-details">
          <summary>Ver stderr</summary>
          <pre class="terminal-output terminal-output--error"><?= tecnico_h((string)$smoke['stderr']) ?></pre>
        </details>
      <?php endif; ?>
    </section>

    <section class="tecnico-card">
      <div class="section-head">
        <h2>Hoja de ruta</h2>
        <span class="chip ok"><?= count($roadmapStages) ?> etapas</span>
      </div>
      <div class="tag-row">
        <?php foreach ($roadmapStages as $stage): ?>
          <span class="chip chip-inline"><?= tecnico_h($stage) ?></span>
        <?php endforeach; ?>
      </div>
      <div class="doc-render"><?= tecnico_render_markdownish($roadmapText) ?></div>
    </section>

    <section class="tecnico-card tecnico-card--wide">
      <div class="section-head">
        <h2>Inventario Legacy / API</h2>
        <span class="chip warning"><?= count($inventoryDomains) ?> dominios</span>
      </div>
      <div class="tag-row">
        <?php foreach ($inventoryDomains as $domain): ?>
          <span class="chip chip-inline warning"><?= tecnico_h($domain) ?></span>
        <?php endforeach; ?>
      </div>
      <div class="doc-render"><?= tecnico_render_markdownish($inventoryText) ?></div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
