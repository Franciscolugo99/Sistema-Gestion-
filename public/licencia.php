<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('administrar_config');

csrf_init();

$errors = [];
$successMessage = '';
$licenseMeta = function_exists('flus_license_meta') ? flus_license_meta() : [];

$formatDate = static function (?string $value, bool $withTime = false): string {
    $raw = trim((string)$value);
    if ($raw === '' || $raw === 'N/D') {
        return 'N/D';
    }

    $formats = $withTime
        ? ['d/m/Y H:i', 'd/m/Y']
        : ['d/m/Y', 'd/m/Y H:i'];

    try {
        $date = new DateTimeImmutable($raw);
        return $date->format($formats[0]);
    } catch (Throwable $e) {
        return $raw;
    }
};

$uploadErrorMessage = static function (int $code): string {
    return match ($code) {
        UPLOAD_ERR_OK => '',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo permitido.',
        UPLOAD_ERR_PARTIAL => 'La carga quedó incompleta. Volvé a intentarlo.',
        UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal para subir archivos.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal.',
        UPLOAD_ERR_EXTENSION => 'La subida fue detenida por una extensión de PHP.',
        default => 'No se pudo recibir el archivo de licencia.',
    };
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? 'upload');
    $tok = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_verify($tok)) {
        $errors[] = 'Token CSRF inválido. Recargá la página e intentá de nuevo.';
    }

    if ($errors === [] && $action === 'revalidate_cloud') {
        if (function_exists('flus_license_cloud_save_state')) {
            flus_license_cloud_save_state([]);
            header('Location: licencia.php?revalidated=1');
            exit;
        }
        $errors[] = 'La validacion en la nube no esta disponible en esta instalacion.';
    } elseif ($errors === [] && $action !== 'upload') {
        $errors[] = 'Accion de licencia no valida.';
    }

    if ($errors === [] && $action === 'upload') {
        $file = $_FILES['license_file'] ?? null;
        if (!$file || !is_array($file)) {
            $errors[] = 'No se recibió el archivo de licencia.';
        } else {
            $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {
                $errors[] = $uploadErrorMessage($uploadError);
            } else {
                $tmp = (string)($file['tmp_name'] ?? '');
                $raw = @file_get_contents($tmp);

                if ($raw === false || trim((string)$raw) === '') {
                    $errors[] = 'El archivo está vacío o no se puede leer.';
                } else {
                    $decoded = json_decode((string)$raw, true);
                    if (!is_array($decoded)) {
                        $errors[] = 'El archivo no contiene un JSON válido.';
                    } else {
                        if (function_exists('flus_license_validate_payload')) {
                            $validation = flus_license_validate_payload($decoded);
                            if (!$validation['ok']) {
                                $errors[] = function_exists('flus_license_human_error')
                                    ? flus_license_human_error((string)($validation['error'] ?? ''))
                                    : ('Licencia inválida: ' . (string)($validation['error'] ?? 'N/D'));
                            } else {
                                $decoded = $validation['license'];
                            }
                        }

                        if ($errors === []) {
                            if (function_exists('flus_license_save')) {
                                $saved = flus_license_save($decoded);
                                if (!$saved['ok']) {
                                    $errors[] = function_exists('flus_license_human_error')
                                        ? flus_license_human_error((string)($saved['error'] ?? 'WRITE_FAILED'))
                                        : 'No se pudo guardar la licencia.';
                                } else {
                                    if (function_exists('flus_license_cloud_save_state')) {
                                        flus_license_cloud_save_state([]);
                                    }
                                    header('Location: licencia.php?saved=1');
                                    exit;
                                }
                            } else {
                                $saved = @file_put_contents(
                                    FLUS_ROOT . '/storage/license.json',
                                    json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                );
                                if ($saved === false) {
                                    $errors[] = 'No se pudo guardar la licencia en storage/license.json.';
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
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $successMessage = 'Licencia guardada correctamente.';
}
if (isset($_GET['revalidated']) && $_GET['revalidated'] === '1') {
    $successMessage = 'Validacion en la nube actualizada.';
}

$licenseMeta = function_exists('flus_license_meta') ? flus_license_meta() : $licenseMeta;
$lockReason = (string)($_GET['reason'] ?? ($licenseMeta['reason'] ?? ''));
$lockMessage = (isset($_GET['locked']) && $_GET['locked'] === '1' && $lockReason !== '' && function_exists('flus_license_reason_label'))
    ? flus_license_reason_label($lockReason)
    : '';

$pageTitle = 'Licencia';
$currentSection = 'configuracion';
$bodyClass = trim(($bodyClass ?? '') . ' licencia-page');
$extraCss = array_merge($extraCss ?? [], ['assets/css/licencia.css']);

require __DIR__ . '/partials/header.php';
?>

<div class="panel licencia-shell">
  <header class="page-header module-header licencia-header">
    <div class="page-header-main module-header-main">
      <div class="module-header-hero">
        <span class="module-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <path d="M15 7V4a3 3 0 0 0-6 0v3"></path>
            <rect x="4" y="7" width="16" height="13" rx="2"></rect>
            <path d="M9 12h6"></path>
            <path d="M12 15v.01"></path>
          </svg>
        </span>
        <div class="module-header-copy">
          <span class="module-eyebrow">Control de licencia</span>
          <h1 class="page-title">Licencia</h1>
          <p class="page-sub">Administrá la licencia activa, revisá el estado actual del sistema y cargá renovaciones desde el panel.</p>
        </div>
      </div>
    </div>
  </header>

  <?php if ($lockMessage !== ''): ?>
    <div class="alert alert-error licencia-alert"><?= h($lockMessage) ?></div>
  <?php endif; ?>

  <?php if ($successMessage !== ''): ?>
    <div class="alert alert-success licencia-alert"><?= h($successMessage) ?></div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error licencia-alert"><?= h(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <?php if (!empty($licenseMeta['clock_warning_label'])): ?>
    <div class="alert alert-warning licencia-alert"><?= h((string)$licenseMeta['clock_warning_label']) ?></div>
  <?php endif; ?>

  <?php if (!empty($licenseMeta['limited'])): ?>
    <section class="licencia-lock-panel" aria-label="Sistema limitado por licencia">
      <div>
        <span class="licencia-card-kicker">Sistema limitado</span>
        <h2><?= h((string)($licenseMeta['reason_label'] ?? 'La licencia requiere revision.')) ?></h2>
        <p>FLUS mantiene disponible esta pantalla para revisar el estado, cargar una renovacion o volver a consultar la nube.</p>
      </div>
      <?php if (!empty($licenseMeta['cloud_enabled'])): ?>
        <form method="post" class="licencia-inline-form">
          <?= csrf_field('csrf_token') ?>
          <input type="hidden" name="action" value="revalidate_cloud">
          <button type="submit" class="btn btn-primary">Revalidar ahora</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="licencia-kpis" aria-label="Resumen de licencia">
    <article class="licencia-kpi licencia-kpi--<?= h((string)($licenseMeta['status_tone'] ?? 'muted')) ?>">
      <span class="licencia-kpi-label">Estado</span>
      <strong class="licencia-kpi-value"><?= h((string)($licenseMeta['status'] ?? 'N/D')) ?></strong>
      <span class="licencia-kpi-help"><?= h((string)($licenseMeta['reason_label'] ?? 'Sin observaciones')) ?></span>
    </article>
    <article class="licencia-kpi">
      <span class="licencia-kpi-label">Plan</span>
      <strong class="licencia-kpi-value"><?= h((string)($licenseMeta['plan'] ?? 'N/D')) ?></strong>
      <span class="licencia-kpi-help">Tipo de licencia cargada</span>
    </article>
    <article class="licencia-kpi">
      <span class="licencia-kpi-label">Vence</span>
      <strong class="licencia-kpi-value"><?= h($formatDate((string)($licenseMeta['valid_until'] ?? 'N/D'))) ?></strong>
      <span class="licencia-kpi-help">Fecha efectiva de expiración</span>
    </article>
    <article class="licencia-kpi licencia-kpi--<?= ((int)($licenseMeta['days_left'] ?? 0) <= 7 && (string)($licenseMeta['days_left'] ?? 'N/D') !== 'N/D') ? 'warning' : 'info' ?>">
      <span class="licencia-kpi-label">Días restantes</span>
      <strong class="licencia-kpi-value"><?= h((string)($licenseMeta['days_left'] ?? 'N/D')) ?></strong>
      <span class="licencia-kpi-help"><?= !empty($licenseMeta['limited']) ? 'El sistema está en modo limitado.' : 'Sistema operativo sin limitación.' ?></span>
    </article>
  </section>

  <div class="licencia-grid">
    <section class="panel licencia-card">
      <div class="licencia-card-head">
        <div>
          <span class="licencia-card-kicker">Diagnóstico actual</span>
          <h2>Estado efectivo</h2>
          <p>Esto refleja lo que realmente está usando FLUS hoy, no solo lo que hay escrito en el JSON.</p>
        </div>
      </div>

      <dl class="licencia-detail-list">
        <div><dt>Estado</dt><dd><?= h((string)($licenseMeta['status'] ?? 'N/D')) ?></dd></div>
        <div><dt>Plan</dt><dd><?= h((string)($licenseMeta['plan'] ?? 'N/D')) ?></dd></div>
        <div><dt>Cliente</dt><dd><?= h((string)($licenseMeta['customer'] !== '' ? $licenseMeta['customer'] : 'N/D')) ?></dd></div>
        <div><dt>Clave</dt><dd class="mono"><?= h((string)($licenseMeta['license_key'] !== '' ? $licenseMeta['license_key'] : 'N/D')) ?></dd></div>
        <div><dt>Emitida</dt><dd><?= h($formatDate((string)($licenseMeta['issued_at'] ?? ''), true)) ?></dd></div>
        <?php if (!empty($licenseMeta['cloud_enabled'])): ?>
          <div><dt>Nube</dt><dd><?= h((string)($licenseMeta['cloud_status_label'] !== '' ? $licenseMeta['cloud_status_label'] : 'pendiente')) ?></dd></div>
          <div><dt>Ultima validacion</dt><dd><?= h($formatDate((string)($licenseMeta['cloud_last_success_at'] ?: $licenseMeta['cloud_checked_at'] ?: ''), true)) ?></dd></div>
          <div><dt>Proxima validacion</dt><dd><?= h($formatDate((string)($licenseMeta['cloud_next_check_at'] ?? ''), true)) ?></dd></div>
        <?php endif; ?>
        <div><dt>Modo limitado</dt><dd><?= !empty($licenseMeta['limited']) ? 'Sí' : 'No' ?></dd></div>
      </dl>

      <?php if (!empty($licenseMeta['reason'])): ?>
        <div class="licencia-note licencia-note--warning">
          <strong>Motivo actual:</strong>
          <span><?= h((string)($licenseMeta['reason_label'] ?? $licenseMeta['reason'])) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($licenseMeta['cloud_enabled']) && (!empty($licenseMeta['cloud_message']) || !empty($licenseMeta['cloud_last_error']))): ?>
        <div class="licencia-note">
          <strong>Nube:</strong>
          <span><?= h((string)($licenseMeta['cloud_message'] !== '' ? $licenseMeta['cloud_message'] : $licenseMeta['cloud_last_error'])) ?></span>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel licencia-card">
      <div class="licencia-card-head">
        <div>
          <span class="licencia-card-kicker">Resumen administrativo</span>
          <h2>Qué revisar acá</h2>
          <p>Usá esta pantalla para confirmar estado, vencimiento y datos visibles de la licencia activa antes de renovarla.</p>
        </div>
      </div>

      <div class="licencia-policy-grid">
        <article class="licencia-policy">
          <strong>Estado actual</strong>
          <span>Confirmá si la licencia está operativa, vencida o con observaciones.</span>
        </article>
        <article class="licencia-policy">
          <strong>Vencimiento</strong>
          <span>Revisá la fecha vigente y los días restantes antes de una renovación.</span>
        </article>
        <article class="licencia-policy">
          <strong>Datos visibles</strong>
          <span>Cliente, plan y clave ayudan a validar que estás cargando la licencia correcta.</span>
        </article>
        <article class="licencia-policy">
          <strong>Renovación</strong>
          <span>Subí el archivo provisto para este cliente y el sistema hará la validación antes de aplicarlo.</span>
        </article>
      </div>

      <div class="licencia-note">
        <strong>Sugerencia</strong>
        <span>Si una renovación no se aplica, verificá que el archivo corresponda a este cliente y que no esté vencido antes de volver a intentar.</span>
      </div>
    </section>

    <section class="panel licencia-card licencia-card--full">
      <div class="licencia-card-head licencia-card-head--split">
        <div>
          <span class="licencia-card-kicker">Renovación</span>
          <h2>Cargar o reemplazar licencia</h2>
          <p>La licencia nueva se valida antes de guardarse y se intenta respaldar el archivo anterior antes del reemplazo.</p>
        </div>
        <div class="licencia-upload-actions">
          <?php if (defined('FLUS_LICENSE_CLOUD_MOCK_ENABLED') && FLUS_LICENSE_CLOUD_MOCK_ENABLED): ?>
            <a class="btn btn-secondary" href="license_cloud_mock.php">Mock nube</a>
          <?php endif; ?>
          <a class="btn btn-secondary" href="configuracion.php">Volver</a>
        </div>
      </div>

      <form method="post" enctype="multipart/form-data" class="licencia-upload-form">
        <?= csrf_field('csrf_token') ?>
        <input type="hidden" name="action" value="upload">

        <label class="licencia-upload-drop">
          <span class="licencia-upload-title">Seleccionar archivo JSON</span>
          <span class="licencia-upload-copy">Subí el archivo de licencia provisto para este cliente.</span>
          <input type="file" name="license_file" accept="application/json,.json" required>
        </label>

        <div class="licencia-upload-actions">
          <button class="btn btn-primary" type="submit">Subir licencia</button>
          <span class="licencia-upload-hint">Usá un archivo válido en formato JSON entregado para esta instalación.</span>
        </div>
      </form>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
