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

$next = str_replace('\\', '/', $next);

if ($next !== '' && $next[0] !== '/') {
    $next = ($base !== '' ? ($base . '/') : '/') . ltrim($next, '/');
}

if ($prefix !== '' && str_starts_with($next, $prefix . '/') && ($base !== '' && !str_starts_with($next, $base . '/'))) {
    $next = $base . substr($next, strlen($prefix));
}

$allowedPrefix = ($base !== '' ? ($base . '/') : '/');
if (!str_starts_with($next, $allowedPrefix)) {
    $next = ($base !== '' ? ($base . '/caja.php') : '/caja.php');
}

$pageTitle = 'Seleccionar terminal';
$currentSection = '';
$extraCss = ['assets/css/terminal_select.css'];
$extraJs = ['assets/js/terminal_select.js'];

require __DIR__ . '/partials/header.php';
?>

<main class="ts-page">
  <div id="terminalSelectConfig"
       data-base="<?= h($base) ?>"
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
