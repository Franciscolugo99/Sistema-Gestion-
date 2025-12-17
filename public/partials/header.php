<?php
// public/partials/header.php

// ------------------------------
// CONFIGURACIÓN BASE
// ------------------------------

// Título de la pestaña
$pageTitle = $pageTitle ?? 'FLUS - Sistema de gestión';

// Sección actual para resaltar en el nav (dashboard, caja, promos, etc.)
$currentSection = $currentSection ?? '';

// Tema actual (lo usa app.js para alternar claro/oscuro)
$theme = $_COOKIE['theme'] ?? 'dark';

// Archivos CSS / JS adicionales (por página)
$extraCss = $extraCss ?? [];
$extraJs  = $extraJs  ?? [];

// Entorno actual: 'dev' o 'prod' (para cache busting)
$env = $env ?? 'prod';
$ver = $env === 'dev' ? time() : '1.0.0';

// Soporte para meta o inline CSS extra
$metaExtra = $metaExtra ?? '';
$inlineCss = $inlineCss ?? '';

// Clase opcional para el <body> (por página, ej: "page-index")
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link rel="icon" type="image/x-icon" href="/favicon.ico">

  <!-- CSS base global -->
  <link
    rel="stylesheet"
    href="assets/css/theme.css?v=<?= $ver ?>"
  >
  <link
    rel="stylesheet"
    href="assets/css/core.css?v=<?= $ver ?>"
  >
  <link
    rel="stylesheet"
    href="assets/css/app.css?v=<?= $ver ?>"
  >

  <!-- CSS específico de página -->
  <?php foreach ($extraCss as $href): ?>
    <link
      rel="stylesheet"
      href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>?v=<?= $ver ?>"
    >
  <?php endforeach; ?>

  <?php if ($inlineCss): ?>
    <style><?= $inlineCss ?></style>
  <?php endif; ?>
</head>

<body
  data-theme="<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>"
  class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>"
>

  <?php require __DIR__ . '/nav.php'; ?>

  <!-- Contenedor global de TODO el contenido de la app -->
  <div class="root container-global">
