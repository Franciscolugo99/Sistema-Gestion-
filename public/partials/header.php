<?php
declare(strict_types=1);
// public/partials/header.php

// ------------------------------
// CONFIGURACION BASE
// ------------------------------
$pageTitle       = $pageTitle       ?? 'FLUS - Sistema de gestion';
$currentSection  = $currentSection  ?? '';
$theme           = $_COOKIE['theme'] ?? 'dark';

$extraCss        = $extraCss  ?? [];
$extraJs         = $extraJs   ?? [];

$env             = $env ?? 'prod';
$defaultVer      = ($env === 'dev') ? (string)time() : '1.0.0';

$metaExtra       = $metaExtra ?? '';
$inlineCss       = $inlineCss ?? '';
$bodyClass       = $bodyClass ?? '';
$extraHead       = $extraHead ?? '';

// CSS Extended: aliases opt-in (ver docs/CSS_MIGRATION_GUIDE.md)
// Opciones: '' (nada), 'aliases' (todos), 'productos', 'roles', 'compras', o combinados
$cssExtended     = $cssExtended ?? '';

// Helper para versionar por mtime (cache-busting real)
$verFor = function (string $rel) use ($defaultVer): string {
  $fs = __DIR__ . '/../' . ltrim($rel, '/'); // desde public/partials -> public/...
  return file_exists($fs) ? (string)filemtime($fs) : $defaultVer;
};
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- CSRF token para fetch/ajax -->
  <?php
    $csrfMeta = __DIR__ . '/csrf_meta.php';
    if (is_file($csrfMeta)) {
      require_once $csrfMeta;
    } else {
      // Fallback ultra compatible: token directo
      require_once __DIR__ . '/../lib/csrf.php';
      csrf_init();
      $t = csrf_token();
      echo '<meta name="csrf-token" content="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
    }
  ?>

  <link rel="icon" type="image/x-icon" href="/favicon.ico">

  <!-- CSS base global -->
  <link rel="stylesheet" href="assets/css/theme.css?v=<?= htmlspecialchars($verFor('assets/css/theme.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/theme-extended.css?v=<?= htmlspecialchars($verFor('assets/css/theme-extended.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/core.css?v=<?= htmlspecialchars($verFor('assets/css/core.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= htmlspecialchars($verFor('assets/css/app.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/components.css?v=<?= htmlspecialchars($verFor('assets/css/components.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/utilities.css?v=<?= htmlspecialchars($verFor('assets/css/utilities.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/nav.css?v=<?= htmlspecialchars($verFor('assets/css/nav.css'), ENT_QUOTES, 'UTF-8') ?>">

  <!-- CSS especifico de pagina -->
  <?php foreach ($extraCss as $href): ?>
    <?php
      $hrefStr = (string)$href;
      $clean   = strtok($hrefStr, '?'); // quita query si vino con params
      $fsPath  = __DIR__ . '/../' . ltrim($clean, '/');
      $v       = file_exists($fsPath) ? (string)filemtime($fsPath) : $defaultVer;
      $sep     = (strpos($hrefStr, '?') !== false) ? '&' : '?';
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($hrefStr, ENT_QUOTES, 'UTF-8') ?><?= $sep ?>v=<?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?>">
  <?php endforeach; ?>

  <!-- CSS de consistencia transversal -->
  <link rel="stylesheet" href="assets/css/kpis.css?v=<?= htmlspecialchars($verFor('assets/css/kpis.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/flus_list_search.css?v=<?= htmlspecialchars($verFor('assets/css/flus_list_search.css'), ENT_QUOTES, 'UTF-8') ?>">

  <?php if ($inlineCss): ?>
    <style><?= $inlineCss ?></style>
  <?php endif; ?>

  <?= $metaExtra ?>
  <!-- FLUS Notifications (SweetAlert2 local) -->
  <link rel="stylesheet" href="assets/vendor/sweetalert2/sweetalert2.min.css?v=<?= htmlspecialchars($verFor('assets/vendor/sweetalert2/sweetalert2.min.css'), ENT_QUOTES, 'UTF-8') ?>">
  <script src="assets/vendor/sweetalert2/sweetalert2.min.js?v=<?= htmlspecialchars($verFor('assets/vendor/sweetalert2/sweetalert2.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="assets/js/flus_notif.js?v=<?= htmlspecialchars($verFor('assets/js/flus_notif.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <?= $extraHead ?>
</head>

<body
  data-theme="<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>"
  <?php if ($cssExtended): ?>data-css-extended="<?= htmlspecialchars($cssExtended, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>
  class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>"
>
  <?php require_once __DIR__ . '/nav.php'; ?>

  <!-- Contenedor global de TODO el contenido de la app -->
  <div class="root container-global">
