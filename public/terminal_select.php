<?php
// public/terminal_select.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

/**
 * Base dinámico del public (soporta subcarpeta y docroot directo)
 * - DocRoot=/public => base '' (URLs tipo /caja.php)
 * - Subcarpeta=/kiosco/public => base '/kiosco/public'
 */
$base   = flus_public_base_path();
$prefix = ($base !== '') ? rtrim(dirname($base), '/') : '';

/**
 * next: normalizado y restringido a la raíz pública (seguridad)
 */
$next = (string)($_GET['next'] ?? '');
if ($next === '') $next = ($base !== '' ? ($base . '/caja.php') : '/caja.php');

$next = str_replace('\\', '/', $next);

// si viene relativo, lo hacemos absoluto dentro del public
if ($next !== '' && $next[0] !== '/') {
  $next = ($base !== '' ? ($base . '/') : '/') . ltrim($next, '/');
}

// si viene /prefix/... pero sin /public (caso /kiosco/... vs /kiosco/public/...), lo arreglamos
if ($prefix !== '' && str_starts_with($next, $prefix . '/') && ($base !== '' && !str_starts_with($next, $base . '/'))) {
  $next = $base . substr($next, strlen($prefix));
}

// seguridad: si no apunta a la raíz pública, fallback
$allowedPrefix = ($base !== '' ? ($base . '/') : '/');
if (!str_starts_with($next, $allowedPrefix)) {
  $next = ($base !== '' ? ($base . '/caja.php') : '/caja.php');
}

$csrf = csrf_token();

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h($csrf) ?>">
  <title>Seleccionar terminal</title>

  <link rel="stylesheet" href="assets/css/core.css">
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="stylesheet" href="assets/css/terminal_select.css">

  <!-- aplica theme claro/oscuro y el resto del “shell” -->
  <script src="assets/js/app.js" defer></script>
  <script
    src="assets/js/terminal_select.js"
    defer
    data-base="<?= h($base) ?>"
    data-next="<?= h($next) ?>"
  ></script>
</head>

<body>
  <?php
    // ✅ NAV adentro del body (evita HTML inválido)
    $navFile = __DIR__ . '/partials/nav.php';
    if (is_file($navFile)) require $navFile;
  ?>

  <main class="ts-page">
    <section class="ts-head">
      <div class="ts-title">
        <h1>Seleccionar terminal</h1>
        <p id="msg" class="ts-sub">
          <span class="ts-loader" aria-hidden="true"></span>
          Cargando terminales…
        </p>
      </div>

      <div class="ts-pill" title="<?= h($next) ?>">
        Luego te llevo a:
        <strong class="ts-next"><?= h($next) ?></strong>
      </div>
    </section>

    <section id="grid" class="ts-grid" aria-live="polite"></section>
  </main>
</body>
</html>