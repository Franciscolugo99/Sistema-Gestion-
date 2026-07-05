<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('administrar_config');
csrf_init();

require_once FLUS_ROOT . '/src/license_cloud_mock.php';

if (!flus_license_cloud_mock_enabled()) {
  http_response_code(404);
  exit('Mock de licencia nube deshabilitado.');
}

$errors = [];
$successMessage = '';
$allowedStatuses = [
  'active' => 'Activa',
  'suspended' => 'Suspendida',
  'expired' => 'Vencida',
  'revoked' => 'Revocada',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $tok = (string)($_POST['csrf_token'] ?? '');
  if (!csrf_verify($tok)) {
    $errors[] = 'Token CSRF invalido. Recarga la pagina.';
  } else {
    $status = strtolower(trim((string)($_POST['status'] ?? 'active')));
    if (!array_key_exists($status, $allowedStatuses)) {
      $errors[] = 'Estado no valido.';
    } else {
      $expiresAt = trim((string)($_POST['expires_at'] ?? '2099-12-31'));
      $date = DateTimeImmutable::createFromFormat('Y-m-d', $expiresAt);
      if (!$date || $date->format('Y-m-d') !== $expiresAt) {
        $errors[] = 'La fecha debe tener formato YYYY-MM-DD.';
      } else {
        flus_license_cloud_mock_save_state([
          'status' => $status,
          'plan' => trim((string)($_POST['plan'] ?? 'Mensual')) ?: 'Mensual',
          'expires_at' => $expiresAt,
          'message' => trim((string)($_POST['message'] ?? '')),
        ]);
        $successMessage = 'Estado mock actualizado. Se limpio el cache nube local.';
      }
    }
  }
}

$state = flus_license_cloud_mock_load_state();
$pageTitle = 'Mock licencia nube';
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
            <path d="M12 3v18"></path>
            <path d="M5 8h14"></path>
            <path d="M5 16h14"></path>
          </svg>
        </span>
        <div class="module-header-copy">
          <span class="module-eyebrow">Prueba local</span>
          <h1 class="page-title">Mock licencia nube</h1>
          <p class="page-sub">Simula el estado que luego vendra desde Wiros para probar suspension y reactivacion sin internet.</p>
        </div>
      </div>
    </div>
  </header>

  <?php if ($successMessage !== ''): ?>
    <div class="alert alert-success licencia-alert"><?= h($successMessage) ?></div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error licencia-alert"><?= h(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <div class="licencia-grid">
    <section class="panel licencia-card">
      <div class="licencia-card-head">
        <div>
          <span class="licencia-card-kicker">Estado simulado</span>
          <h2><?= h($allowedStatuses[(string)($state['status'] ?? 'active')] ?? 'Activa') ?></h2>
          <p>Este valor lo firma el mock local y FLUS lo consume como si fuera la nube.</p>
        </div>
      </div>

      <dl class="licencia-detail-list">
        <div><dt>Estado</dt><dd><?= h((string)($state['status'] ?? 'active')) ?></dd></div>
        <div><dt>Plan</dt><dd><?= h((string)($state['plan'] ?? 'Mensual')) ?></dd></div>
        <div><dt>Vence</dt><dd><?= h((string)($state['expires_at'] ?? '2099-12-31')) ?></dd></div>
        <div><dt>Mensaje</dt><dd><?= h((string)($state['message'] ?? '')) ?></dd></div>
        <div><dt>Actualizado</dt><dd><?= h((string)($state['updated_at'] ?? '')) ?></dd></div>
      </dl>

      <div class="licencia-note">
        <strong>Endpoint local:</strong>
        <span class="mono">api/license_cloud_mock.php</span>
      </div>
    </section>

    <section class="panel licencia-card">
      <div class="licencia-card-head">
        <div>
          <span class="licencia-card-kicker">Control</span>
          <h2>Cambiar estado</h2>
          <p>Usa suspendida para probar corte por falta de pago y activa para reactivar.</p>
        </div>
      </div>

      <form method="post" class="licencia-upload-form">
        <?= csrf_field('csrf_token') ?>

        <label>
          Estado
          <select name="status" required>
            <?php foreach ($allowedStatuses as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= (string)($state['status'] ?? 'active') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Plan
          <input type="text" name="plan" value="<?= h((string)($state['plan'] ?? 'Mensual')) ?>">
        </label>

        <label>
          Vencimiento
          <input type="date" name="expires_at" value="<?= h((string)($state['expires_at'] ?? '2099-12-31')) ?>">
        </label>

        <label>
          Mensaje
          <input type="text" name="message" value="<?= h((string)($state['message'] ?? '')) ?>">
        </label>

        <div class="licencia-upload-actions">
          <button class="btn btn-primary" type="submit">Guardar estado mock</button>
          <a class="btn btn-secondary" href="licencia.php">Ver licencia</a>
        </div>
      </form>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
