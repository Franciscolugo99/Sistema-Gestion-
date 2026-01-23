<?php
// public/partials/footer.php
declare(strict_types=1);

$extraJs  = $extraJs  ?? [];
$inlineJs = $inlineJs ?? '';
$env      = $env ?? 'prod';

// Cache-busting real por archivo (evita “no hace nada” por caché)
$appJsPath = __DIR__ . '/../assets/js/app.js'; // public/partials -> public/assets/js/app.js
$appVer    = file_exists($appJsPath) ? (string)filemtime($appJsPath) : '1';

// Version general para otros assets
$ver = ($env === 'dev') ? (string)time() : '1.0.0';

// ✅ Version (fallback por si algún entrypoint no incluyó bootstrap/version)
if (!defined('FLUS_VERSION') || !function_exists('flus_version_label')) {
  $verFile = __DIR__ . '/../../src/version.php'; // public/partials -> src/version.php
  if (is_file($verFile)) require_once $verFile;
}

// ✅ Licencia (widget + datos para “Copiar info”)
require_once __DIR__ . '/license_widget.php';

$serverSw = (string)($_SERVER['SERVER_SOFTWARE'] ?? 'N/A');
$tz       = (string)date_default_timezone_get();

// Resumen de licencia para el texto “Copiar info”
$licPlan = 'N/D'; $licVence = 'N/D'; $licDias = 'N/D'; $licEstado = 'N/D';
$licPaths = [
  __DIR__ . '/../storage/license.json',
  __DIR__ . '/../../storage/license.json',
];
foreach ($licPaths as $p) {
  if (is_file($p)) {
    $j = json_decode((string)file_get_contents($p), true);
    if (is_array($j)) {
      $licPlan = (string)($j['plan'] ?? 'N/D');
      $expStr  = $j['expires_at'] ?? null;
      if ($expStr) {
        try {
          $hoy   = new DateTime('today');
          $vence = new DateTime((string)$expStr);
          $diff  = (int)$hoy->diff($vence)->format('%r%a');
          $licDias   = (string)$diff;
          $licEstado = ($diff < 0) ? 'vencida' : (($diff <= 7) ? 'por vencer' : 'activa');
          $licVence  = $vence->format('Y-m-d');
        } catch (Throwable $e) {
          $licVence = (string)$expStr;
        }
      }
    }
    break;
  }
}

$aboutText =
  (function_exists('flus_version_label') ? flus_version_label() : 'FLUS') . "\n" .
  "Build: " . (defined('FLUS_BUILD') ? FLUS_BUILD : 'N/A') . "\n" .
  "PHP: " . PHP_VERSION . "\n" .
  "Server: " . $serverSw . "\n" .
  "Timezone: " . $tz . "\n" .
  "Licencia: " . $licPlan . " (" . $licEstado . ")\n" .
  "Vence: " . $licVence . "\n" .
  "Dias restantes: " . $licDias;
?>

</div> <!-- /.root container-global -->

<!-- ✅ Toast global (necesario para showToast) -->
<div id="toast" class="toast" aria-live="polite" aria-atomic="true"></div>

<!-- ✅ Versión FLUS (abre modal, fallback a acerca.php si JS falla) -->
<div class="flus-version-badge">
  <a
    href="acerca.php"
    class="flus-version-link"
    data-open-flus-about="1"
    aria-haspopup="dialog"
    aria-controls="flusAboutModal"
    title="Acerca de FLUS"
  >
    <?= htmlspecialchars(function_exists('flus_version_label') ? flus_version_label() : 'FLUS', ENT_QUOTES, 'UTF-8') ?>
  </a>
</div>

<!-- ✅ Modal Acerca de FLUS -->
<div id="flusAboutModal" class="flus-modal" role="dialog" aria-modal="true" aria-labelledby="flusAboutTitle" hidden>
  <div class="flus-modal__backdrop" data-close-flus-about></div>

  <div class="flus-modal__card" role="document">
    <div class="flus-modal__header">
      <h3 id="flusAboutTitle" class="flus-modal__title">Acerca de FLUS</h3>
      <button type="button" class="flus-icon-btn" data-close-flus-about aria-label="Cerrar">✕</button>
    </div>

    <div class="flus-modal__body">
      <div class="flus-about__version">
        <?= htmlspecialchars(function_exists('flus_version_label') ? flus_version_label() : 'FLUS', ENT_QUOTES, 'UTF-8') ?>
      </div>

      <div class="flus-about__grid">
        <div class="flus-about__row"><span>Build</span><b><?= htmlspecialchars((string)(defined('FLUS_BUILD') ? FLUS_BUILD : 'N/A'), ENT_QUOTES, 'UTF-8') ?></b></div>
        <div class="flus-about__row"><span>PHP</span><b><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></b></div>
        <div class="flus-about__row"><span>Server</span><b><?= htmlspecialchars($serverSw, ENT_QUOTES, 'UTF-8') ?></b></div>
        <div class="flus-about__row"><span>Timezone</span><b><?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?></b></div>
      </div>

      <!-- Tarjeta de Licencia -->
      <div class="flus-about__license" style="margin-top:12px;">
        <?= flus_license_widget(); ?>
      </div>

      <!-- texto para copiar (soporte) -->
      <pre id="flusAboutCopyText" class="flus-about__copy" aria-hidden="true"><?= htmlspecialchars($aboutText, ENT_QUOTES, 'UTF-8') ?></pre>

      <div class="flus-about__hint">
        Tip: “Copiar info” te deja todo listo para pegar en soporte.
      </div>
    </div>

    <div class="flus-modal__footer">
      <button type="button" class="flus-btn" data-close-flus-about>Cancelar</button>
      <button type="button" class="flus-btn flus-btn-primary" id="flusAboutCopy">Copiar info</button>
    </div>
  </div>
</div>

<!-- JS base del sistema -->
<script src="assets/js/app.js?v=<?= htmlspecialchars($appVer, ENT_QUOTES, 'UTF-8') ?>"></script>

<!-- JS adicionales por página -->
<?php foreach ($extraJs as $src): ?>
  <?php
    $srcStr = (string)$src;
    $clean  = strtok($srcStr, '?'); // quita query si trae
    $fsPath = __DIR__ . '/../' . ltrim($clean, '/'); // public/partials -> public/...
    $mtime  = file_exists($fsPath) ? (string)filemtime($fsPath) : $ver;
    $sep    = (strpos($srcStr, '?') !== false) ? '&' : '?';
  ?>
  <script src="<?= htmlspecialchars($srcStr, ENT_QUOTES, 'UTF-8') ?><?= $sep ?>v=<?= htmlspecialchars($mtime, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endforeach; ?>

<!-- Inline JS específico (opcional) -->
<?php if (!empty($inlineJs)) { ?>
  <script>
  <?= $inlineJs ?>
  </script>
<?php } ?>

</body>
</html>
