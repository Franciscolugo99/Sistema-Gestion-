<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once FLUS_ROOT . '/src/cloud_sync_lib.php';

require_login();

$licenseLockedForPage = defined('FLUS_LICENSE_LOCKED') && FLUS_LICENSE_LOCKED;
$canManageLicense = function_exists('user_has_permission') && user_has_permission('administrar_config');
if (!$canManageLicense && !$licenseLockedForPage) {
    require_permission('administrar_config');
}

csrf_init();

$errors = [];
$successMessage = '';
$warningMessage = '';
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
    if (!$canManageLicense) {
        require_permission('administrar_config');
    }

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
                                    $savedPlan = (string)($decoded['plan'] ?? '');
                                    $cloudPending = flus_cloud_sync_plan_is_cloud($savedPlan)
                                        && empty(flus_cloud_sync_config_readiness()['ready']);
                                    header('Location: licencia.php?saved=' . ($cloudPending ? 'cloud_pending' : '1'));
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
                                    $savedPlan = (string)($decoded['plan'] ?? '');
                                    $cloudPending = flus_cloud_sync_plan_is_cloud($savedPlan)
                                        && empty(flus_cloud_sync_config_readiness()['ready']);
                                    header('Location: licencia.php?saved=' . ($cloudPending ? 'cloud_pending' : '1'));
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
if (isset($_GET['saved']) && $_GET['saved'] === 'cloud_pending') {
    $warningMessage = 'La licencia cloud se guardo, pero la activacion no esta completa: falta configurar URL y token cloud.';
}
if (isset($_GET['revalidated']) && $_GET['revalidated'] === '1') {
    $successMessage = 'Validacion en la nube actualizada.';
}

$licenseMeta = function_exists('flus_license_meta') ? flus_license_meta() : $licenseMeta;
$lockReason = (string)($_GET['reason'] ?? ($licenseMeta['reason'] ?? ''));
$lockMessage = (isset($_GET['locked']) && $_GET['locked'] === '1' && $lockReason !== '' && function_exists('flus_license_reason_label'))
    ? flus_license_reason_label($lockReason)
    : '';
$licenseLimited = !empty($licenseMeta['limited']);
$licenseStatus = (string)($licenseMeta['status'] ?? 'N/D');
$licensePlan = (string)($licenseMeta['plan'] ?? 'N/D');
$cloudSyncReadiness = flus_cloud_sync_config_readiness();
$cloudSetupPending = flus_cloud_sync_plan_is_cloud($licensePlan) && empty($cloudSyncReadiness['ready']);
if ($cloudSetupPending && $warningMessage === '') {
    $warningMessage = 'Este plan requiere Cloud, pero la sincronizacion esta inactiva. Ejecuta Configurar Cloud FLUS antes de dar la instalacion por terminada.';
}
$licenseCustomerRaw = trim((string)($licenseMeta['customer'] ?? ''));
$licenseCustomer = $licenseCustomerRaw !== '' ? $licenseCustomerRaw : 'Cliente no informado';
$licenseReasonLabel = (string)($licenseMeta['reason_label'] ?? 'Sin observaciones');
$daysRaw = $licenseMeta['days_left'] ?? null;
$daysLabel = 'N/D';
$daysHelp = $licenseLimited ? 'El sistema está limitado.' : 'Sistema operativo.';
if (is_numeric($daysRaw)) {
    $daysInt = (int)$daysRaw;
    if ($daysInt < 0) {
        $daysLabel = 'Vencida hace ' . abs($daysInt) . ' día' . (abs($daysInt) === 1 ? '' : 's');
        $daysHelp = 'Renová la licencia para recuperar la operación completa.';
    } elseif ($daysInt === 0) {
        $daysLabel = 'Vence hoy';
        $daysHelp = 'Conviene renovarla durante el día.';
    } else {
        $daysLabel = (string)$daysInt . ' día' . ($daysInt === 1 ? '' : 's');
        $daysHelp = $daysInt <= 7 ? 'La renovación está cerca.' : 'Licencia dentro del plazo.';
    }
}
$primaryActionCopy = $licenseLimited
    ? 'Renová la licencia o volvé a validar si ya fue regularizada.'
    : 'La licencia está disponible para operar. Si recibiste una renovación, podés cargarla acá.';

$pageTitle = 'Licencia';
$currentSection = 'configuracion';
$bodyClass = trim(($bodyClass ?? '') . ' licencia-page');
$extraCss = array_merge($extraCss ?? [], ['assets/css/licencia.css']);

require __DIR__ . '/partials/header.php';
?>

<div class="panel licencia-shell">
  <header class="licencia-titlebar">
    <span class="module-header-icon licencia-title-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
        <path d="M15 7V4a3 3 0 0 0-6 0v3"></path>
        <rect x="4" y="7" width="16" height="13" rx="2"></rect>
        <path d="M9 12h6"></path>
        <path d="M12 15v.01"></path>
      </svg>
    </span>
    <div>
      <span class="licencia-card-kicker">Cuenta del comercio</span>
      <h1>Licencia FLUS</h1>
      <p>Estado de uso, vencimiento y renovación de esta instalación.</p>
    </div>
  </header>

  <?php if ($lockMessage !== '' && !$licenseLimited): ?>
    <div class="alert alert-error licencia-alert"><?= h($lockMessage) ?></div>
  <?php endif; ?>

  <?php if ($successMessage !== ''): ?>
    <div class="alert alert-success licencia-alert"><?= h($successMessage) ?></div>
  <?php endif; ?>

  <?php if ($warningMessage !== ''): ?>
    <div class="alert alert-warning licencia-alert">
      <?= h($warningMessage) ?>
      <?php if ($canManageLicense): ?>
        <a class="btn btn-secondary" href="tecnico.php">Ver diagnostico</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error licencia-alert"><?= h(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <section class="licencia-account licencia-account--<?= $licenseLimited ? 'limited' : 'ok' ?>" aria-label="Estado de licencia">
    <div class="licencia-account-main">
      <span class="licencia-state-chip"><?= $licenseLimited ? 'Requiere atención' : 'Operativa' ?></span>
      <h2><?= h($licenseCustomer) ?></h2>
      <p><?= h($licenseLimited ? $licenseReasonLabel : 'La instalación está habilitada para operar.') ?></p>

      <dl class="licencia-account-facts">
        <div>
          <dt>Plan</dt>
          <dd><?= h($licensePlan) ?></dd>
        </div>
        <div>
          <dt>Clave</dt>
          <dd class="mono"><?= h((string)(((string)($licenseMeta['license_key'] ?? '')) !== '' ? $licenseMeta['license_key'] : 'N/D')) ?></dd>
        </div>
        <?php if (!empty($licenseMeta['cloud_enabled'])): ?>
          <div>
            <dt>Online</dt>
            <dd><?= h((string)(((string)($licenseMeta['cloud_status_label'] ?? '')) !== '' ? $licenseMeta['cloud_status_label'] : 'pendiente')) ?></dd>
          </div>
        <?php endif; ?>
      </dl>
    </div>

    <aside class="licencia-expiry" aria-label="Vencimiento">
      <span>Vencimiento</span>
      <strong><?= h($formatDate((string)($licenseMeta['valid_until'] ?? 'N/D'))) ?></strong>
      <em><?= h($daysLabel) ?></em>
      <p><?= h($daysHelp) ?></p>
      <div class="licencia-status-actions">
        <?php if ($canManageLicense && !empty($licenseMeta['cloud_enabled'])): ?>
          <form method="post" class="licencia-inline-form">
            <?= csrf_field('csrf_token') ?>
            <input type="hidden" name="action" value="revalidate_cloud">
            <button type="submit" class="btn <?= $licenseLimited ? 'btn-primary' : 'btn-secondary' ?>">Revalidar ahora</button>
          </form>
        <?php endif; ?>
        <?php if ($canManageLicense): ?>
          <a class="btn btn-secondary" href="configuracion.php">Volver</a>
        <?php else: ?>
          <a class="btn btn-secondary" href="logout.php">Salir</a>
        <?php endif; ?>
      </div>
    </aside>
  </section>

  <div class="licencia-main-grid">
    <section class="licencia-section">
      <div class="licencia-section-head">
        <span class="licencia-card-kicker">Información</span>
        <h2>Datos de la licencia</h2>
      </div>

      <dl class="licencia-detail-list">
        <div><dt>Estado</dt><dd><?= h($licenseStatus) ?></dd></div>
        <div><dt>Cliente</dt><dd><?= h($licenseCustomer) ?></dd></div>
        <div><dt>Plan</dt><dd><?= h($licensePlan) ?></dd></div>
        <div><dt>Clave</dt><dd class="mono"><?= h((string)(((string)($licenseMeta['license_key'] ?? '')) !== '' ? $licenseMeta['license_key'] : 'N/D')) ?></dd></div>
        <div><dt>Emitida</dt><dd><?= h($formatDate((string)($licenseMeta['issued_at'] ?? ''), true)) ?></dd></div>
        <?php if (!empty($licenseMeta['cloud_enabled'])): ?>
          <div><dt>Última consulta online</dt><dd><?= h($formatDate((string)(($licenseMeta['cloud_last_success_at'] ?? '') ?: ($licenseMeta['cloud_checked_at'] ?? '') ?: ''), true)) ?></dd></div>
          <div><dt>Próxima consulta</dt><dd><?= h($formatDate((string)($licenseMeta['cloud_next_check_at'] ?? ''), true)) ?></dd></div>
        <?php endif; ?>
      </dl>

      <details class="licencia-technical">
        <summary>Detalle técnico</summary>
        <dl class="licencia-detail-list licencia-detail-list--compact">
          <div><dt>Modo limitado</dt><dd><?= $licenseLimited ? 'Sí' : 'No' ?></dd></div>
          <?php if (!empty($licenseMeta['reason'])): ?>
            <div><dt>Motivo</dt><dd><?= h($licenseReasonLabel) ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($licenseMeta['clock_warning_label'])): ?>
            <div><dt>Reloj del sistema</dt><dd><?= h((string)$licenseMeta['clock_warning_label']) ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($licenseMeta['cloud_enabled']) && (!empty($licenseMeta['cloud_message']) || !empty($licenseMeta['cloud_last_error']))): ?>
            <div><dt>Respuesta online</dt><dd><?= h((string)(((string)($licenseMeta['cloud_message'] ?? '')) !== '' ? $licenseMeta['cloud_message'] : ($licenseMeta['cloud_last_error'] ?? ''))) ?></dd></div>
          <?php endif; ?>
        </dl>
      </details>
    </section>

    <section class="licencia-section licencia-renew">
      <div class="licencia-section-head">
        <span class="licencia-card-kicker">Renovación</span>
        <h2>Cargar licencia</h2>
        <p>Usá el archivo que recibiste para esta instalación.</p>
      </div>

      <?php if ($canManageLicense): ?>
      <form method="post" enctype="multipart/form-data" class="licencia-upload-form">
        <?= csrf_field('csrf_token') ?>
        <input type="hidden" name="action" value="upload">

        <label class="licencia-upload-drop">
          <span class="licencia-upload-title">Seleccionar archivo de licencia</span>
          <span class="licencia-upload-copy">Subí el archivo de licencia provisto para este cliente.</span>
          <input type="file" name="license_file" accept="application/json,.json" required>
        </label>

        <div class="licencia-upload-actions">
          <button class="btn btn-primary" type="submit">Subir licencia</button>
          <span class="licencia-upload-hint">Usá el archivo entregado para esta instalación.</span>
          <?php if (defined('FLUS_LICENSE_CLOUD_MOCK_ENABLED') && FLUS_LICENSE_CLOUD_MOCK_ENABLED): ?>
            <a class="btn btn-secondary" href="license_cloud_mock.php">Mock nube</a>
          <?php endif; ?>
        </div>
      </form>
      <?php else: ?>
        <div class="licencia-readonly-note">
          <strong>Accion administrativa requerida</strong>
          <span>Pedile a un administrador que cargue la renovacion o valide la licencia desde esta instalacion.</span>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
