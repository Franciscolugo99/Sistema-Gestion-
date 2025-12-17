<?php
// public/partials/footer.php

$extraJs  = $extraJs  ?? [];
$inlineJs = $inlineJs ?? '';
$env      = $env ?? 'prod';
$ver      = $env === 'dev' ? time() : '1.0.0';
?>

</div> <!-- /.root container-global -->

<!-- ✅ Toast global (necesario para showToast) -->
<div id="toast" class="toast" aria-live="polite" aria-atomic="true"></div>

<!-- JS base del sistema -->
<script src="assets/js/app.js?v=<?= $ver ?>"></script>

<!-- JS adicionales por página -->
<?php foreach ($extraJs as $src): ?>
  <script src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>?v=<?= $ver ?>"></script>
<?php endforeach; ?>

<!-- Inline JS específico (opcional) -->
<?php if ($inlineJs): ?>
  <script>
  <?= $inlineJs ?>
  </script>
<?php endif; ?>

</body>
</html>
