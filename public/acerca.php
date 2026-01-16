<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login(); // si lo querés accesible sin login, lo sacamos

$pageTitle = 'Acerca de FLUS';

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <?php /* si tenés include de head global, usalo acá */ ?>
</head>
<body>
  <div class="root" style="max-width:900px;margin:0 auto;padding:16px;">
    <h1 style="margin:0 0 10px;"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

    <div style="padding:14px;border:1px solid var(--border-color);background:var(--bg-secondary);border-radius:14px;">
      <div style="font-size:18px;margin-bottom:8px;">
        <?= htmlspecialchars(flus_version_label(), ENT_QUOTES, 'UTF-8') ?>
      </div>

      <div style="opacity:.85;line-height:1.6;">
        <div><b>Build:</b> <?= htmlspecialchars((string)FLUS_BUILD, ENT_QUOTES, 'UTF-8') ?></div>
        <div><b>PHP:</b> <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></div>
        <div><b>Server:</b> <?= htmlspecialchars((string)($_SERVER['SERVER_SOFTWARE'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></div>
        <div><b>Timezone:</b> <?= htmlspecialchars((string)date_default_timezone_get(), ENT_QUOTES, 'UTF-8') ?></div>
      </div>

      <div style="margin-top:12px;">
        <a href="javascript:history.back()" style="text-decoration:none;">← Volver</a>
      </div>
    </div>
  </div>
</body>
</html>
