<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$base = flus_public_base_path();
$prefix = ($base !== '') ? rtrim(dirname($base), '/') : '';

$next = (string)($_GET['next'] ?? '');
if ($next === '') {
    $next = ($base !== '') ? ($base . '/caja.php') : '/caja.php';
}

$nextParts = parse_url($next);
$nextPath = str_replace('\\', '/', (string)($nextParts['path'] ?? $next));
$nextPath = preg_replace('#/+#', '/', $nextPath) ?? $nextPath;

if ($nextPath !== '' && $nextPath[0] !== '/') {
    $nextPath = ($base !== '' ? ($base . '/') : '/') . ltrim($nextPath, '/');
}

if ($base !== '') {
    $baseLeaf = trim((string)basename($base), '/');
    if ($baseLeaf !== '') {
        $duplicateBasePrefix = $base . '/' . $baseLeaf . '/';
        while (str_starts_with($nextPath, $duplicateBasePrefix)) {
            $nextPath = $base . '/' . ltrim(substr($nextPath, strlen($duplicateBasePrefix)), '/');
        }
    }
}

if ($prefix !== '' && str_starts_with($nextPath, $prefix . '/') && ($base !== '' && !str_starts_with($nextPath, $base . '/'))) {
    $nextPath = $base . substr($nextPath, strlen($prefix));
}

$next = $nextPath;
$query = isset($nextParts['query']) && $nextParts['query'] !== '' ? ('?' . $nextParts['query']) : '';
$fragment = isset($nextParts['fragment']) && $nextParts['fragment'] !== '' ? ('#' . $nextParts['fragment']) : '';

$allowedPrefix = ($base !== '' ? ($base . '/') : '/');
if (!str_starts_with($next, $allowedPrefix)) {
    $next = ($base !== '' ? ($base . '/caja.php') : '/caja.php');
}

$next .= $query . $fragment;

$noticeCode = trim((string)($_GET['notice'] ?? ''));
$noticeMap = [
    'terminal_released' => 'Un administrador liberó la terminal que estabas usando. Elegí una terminal para continuar.',
    'terminal_required' => 'Necesitás seleccionar una terminal antes de entrar a caja.',
];
$noticeMessage = $noticeMap[$noticeCode] ?? '';

$pageTitle = 'Seleccionar terminal';
$currentSection = '';
$extraCss = ['assets/css/terminal_select.css'];
$extraJs = ['assets/js/terminal_select.js'];

require __DIR__ . '/partials/header.php';
?>

<main class="ts-page">
  <div id="terminalSelectConfig"
       data-base="<?= h($base) ?>"
       data-notice="<?= h($noticeCode) ?>"
       data-notice-message="<?= h($noticeMessage) ?>"
       data-next="<?= h($next) ?>"
       hidden></div>

  <section class="ts-head">
    <div class="ts-title">
      <h1>Seleccionar terminal</h1>
      <p id="msg" class="ts-sub">
        <span class="ts-loader" aria-hidden="true"></span>
        Cargando terminales...
      </p>
    </div>

    <div class="ts-pill" title="<?= h($next) ?>">
      Luego te llevo a:
      <strong class="ts-next"><?= h($next) ?></strong>
    </div>
  </section>

  <section id="grid" class="ts-grid" aria-live="polite"></section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
