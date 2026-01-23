<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
if (function_exists('require_login')) { require_login(); } // opcional según tu política
require_once __DIR__ . '/partials/license_widget.php';

$pageTitle = 'Acerca de FLUS';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <?php /* include head global si aplica */ ?>
</head>
<body>
  <div class="root" style="max-width:900px;margin:0 auto;padding:16px;">
    <h1 style="margin:0 0 10px;"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

    <!-- Card: versión / entorno -->
    <div style="padding:14px;border:1px solid var(--border-color,#333);background:var(--bg-secondary,#111);border-radius:14px;">
      <div style="font-size:18px;margin-bottom:8px;">
        <?= htmlspecialchars(function_exists('flus_version_label') ? flus_version_label() : 'FLUS', ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div style="opacity:.85;line-height:1.6;">
        <div><b>Build:</b> <?= htmlspecialchars(defined('FLUS_BUILD') ? (string)FLUS_BUILD : 'N/D', ENT_QUOTES, 'UTF-8') ?></div>
        <div><b>PHP:</b> <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></div>
        <div><b>Server:</b> <?= htmlspecialchars((string)($_SERVER['SERVER_SOFTWARE'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></div>
        <div><b>Timezone:</b> <?= htmlspecialchars((string)date_default_timezone_get(), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>

    <!-- Card: licencia -->
    <div style="margin-top:12px;">
      <?= flus_license_widget(); ?>
    </div>

    <div style="margin-top:12px;">
      <a href="javascript:history.back()" style="text-decoration:none;">← Volver</a>
    </div>
  </div>
</body>
</html>
