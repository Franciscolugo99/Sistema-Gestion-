<?php
// public/partials/footer.php
declare(strict_types=1);

$extraJs  = $extraJs  ?? [];
$inlineJs = $inlineJs ?? '';
$env      = $env ?? 'prod';

// Cache-busting real por archivo (evita "no hace nada" por caché)
$appJsPath = __DIR__ . '/../assets/js/app.js'; // public/partials -> public/assets/js/app.js
$appVer    = file_exists($appJsPath) ? (string)filemtime($appJsPath) : '1';

// Version general para otros assets (podés dejarlo así)
$ver = ($env === 'dev') ? (string)time() : '1.0.0';
?>

</div> <!-- /.root container-global -->

<!-- ✅ Toast global (necesario para showToast) -->
<div id="toast" class="toast" aria-live="polite" aria-atomic="true"></div>

<!-- JS base del sistema (SIN caché vieja) -->
<script src="assets/js/app.js?v=<?= htmlspecialchars($appVer, ENT_QUOTES, 'UTF-8') ?>"></script>

<!-- JS adicionales por página -->
<?php foreach ($extraJs as $src): ?>
  <?php $sep = (strpos((string)$src, '?') !== false) ? '&' : '?'; ?>
  <script src="<?= htmlspecialchars((string)$src, ENT_QUOTES, 'UTF-8') ?><?= $sep ?>v=<?= htmlspecialchars($ver, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endforeach; ?>

<!-- Inline JS específico (opcional) -->
<?php if ($inlineJs): ?>
  <script>
  <?= $inlineJs ?>
  </script>
<?php endif; ?>

</body>
</html>
