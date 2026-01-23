<?php
declare(strict_types=1);
// public/partials/header.php

// ------------------------------
// CONFIGURACIÓN BASE
// ------------------------------
$pageTitle       = $pageTitle       ?? 'FLUS - Sistema de gestión';
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
  <?php require_once __DIR__ . '/csrf_meta.php'; ?>

  <link rel="icon" type="image/x-icon" href="/favicon.ico">

  <!-- CSS base global -->
  <link rel="stylesheet" href="assets/css/theme.css?v=<?= htmlspecialchars($verFor('assets/css/theme.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/core.css?v=<?= htmlspecialchars($verFor('assets/css/core.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= htmlspecialchars($verFor('assets/css/app.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/css/components.css?v=<?= htmlspecialchars($verFor('assets/css/components.css'), ENT_QUOTES, 'UTF-8') ?>">

  <!-- CSS específico de página -->
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

  <?php if ($inlineCss): ?>
    <style><?= $inlineCss ?></style>
  <?php endif; ?>

  <?= $metaExtra ?>
  <?= $extraHead ?>
</head>

<body
  data-theme="<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>"
  class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>"
>
  <?php require_once __DIR__ . '/nav.php'; ?>

  <!-- Contenedor global de TODO el contenido de la app -->
  <div class="root container-global">
