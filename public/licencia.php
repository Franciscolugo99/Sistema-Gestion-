<?php
// public/licencia.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_config');

csrf_init();

$errors = [];
$okMsg  = '';

$licensePath = FLUS_ROOT . '/storage/license.json';

// Guardar (POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $tok = (string)($_POST['csrf_token'] ?? '');
  if (!csrf_verify($tok)) {
    $errors[] = 'Token CSRF inválido. Recargá la página e intentá de nuevo.';
  }

  if (!$errors) {
    $f = $_FILES['license_file'] ?? null;
    if (!$f || !is_array($f)) {
      $errors[] = 'No se recibió el archivo.';
    } else {
      if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Error al subir archivo (code ' . (string)($f['error'] ?? 'N/D') . ').';
      } else {
        $tmp = (string)($f['tmp_name'] ?? '');
        $raw = @file_get_contents($tmp);

        if ($raw === false || trim((string)$raw) === '') {
          $errors[] = 'Archivo vacío o ilegible.';
        } else {
          $data = json_decode((string)$raw, true);
          if (!is_array($data)) {
            $errors[] = 'JSON inválido.';
          } else {
            // Validación central (si existe)
            if (function_exists('flus_license_validate_payload')) {
              $val = flus_license_validate_payload($data);
              if (!$val['ok']) {
                $errors[] = 'Licencia inválida: ' . (string)($val['error'] ?? 'N/D');
              } else {
                $data = $val['license'];
              }
            }

            if (!$errors) {
              // Backup anterior
              if (is_file($licensePath)) {
                $bak = FLUS_ROOT . '/storage/license.json.bak_' . date('Ymd_His');
                @copy($licensePath, $bak);
              }

              $saved = @file_put_contents(
                $licensePath,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
              );

              if ($saved === false) {
                $errors[] = 'No se pudo guardar storage/license.json (revisá permisos).';
              } else {
                header('Location: licencia.php?saved=1');
                exit;
              }
            }
          }
        }
      }
    }
  }
}

/* HEADER */
$pageTitle      = 'Licencia';
$currentSection = 'configuracion';
$extraCss       = []; // si querés, podemos sumar assets/css/licencia.css

require __DIR__ . '/partials/header.php';

$lic = (defined('FLUS_LICENSE') && is_array(FLUS_LICENSE)) ? FLUS_LICENSE : null;
?>

<div class="panel">

  <header class="page-header">
    <div>
      <h1 class="page-title">Licencia</h1>
      <p class="page-sub">Cargar / renovar <span class="mono">storage/license.json</span> (solo admins).</p>
    </div>
  </header>

  <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
    <div class="alert alert-success">✅ Licencia guardada.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-error"><?= h(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <div class="card" style="padding:14px; margin-bottom:12px;">
    <h3 style="margin:0 0 10px 0;">Estado actual</h3>

    <?php if ($lic): ?>
      <div class="flus-about__grid" style="max-width:520px;">
        <div class="flus-about__row"><span>Estado</span><b><?= h((string)($lic['status_label'] ?? $lic['status'] ?? 'N/D')) ?></b></div>
        <div class="flus-about__row"><span>Plan</span><b><?= h((string)($lic['plan_label'] ?? $lic['plan'] ?? 'N/D')) ?></b></div>
        <div class="flus-about__row"><span>Vence</span><b><?= h((string)($lic['valid_until'] ?? 'N/D')) ?></b></div>
        <div class="flus-about__row"><span>Días restantes</span><b><?= h((string)($lic['days_left'] ?? 'N/D')) ?></b></div>
        <div class="flus-about__row"><span>Modo limitado</span><b><?= (defined('FLUS_LIMITED') && FLUS_LIMITED) ? 'SI' : 'NO' ?></b></div>
      </div>
    <?php else: ?>
      <p>No hay datos de licencia cargados.</p>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:14px;">
    <h3 style="margin:0 0 10px 0;">Cargar / Renovar</h3>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_field('csrf_token') ?>

      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="file" name="license_file" accept="application/json" required>
        <button class="v-btn v-btn--primary" type="submit">Subir licencia</button>
        <a class="v-btn v-btn--ghost" href="configuracion.php">Volver</a>
      </div>

      <div style="opacity:.85; margin-top:10px;">
        Requiere JSON con <span class="mono">plan</span> y <span class="mono">expires_at</span> (YYYY-MM-DD).
        También acepta <span class="mono">valid_until</span>.
      </div>
    </form>
  </div>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
