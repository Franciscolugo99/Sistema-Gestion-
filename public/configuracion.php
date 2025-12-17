<?php
// public/configuracion.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('administrar_config');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();
$user = current_user();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Campos que vamos a manejar (todo sale/entra en app_config)
$fields = [
  'business_name' => [
    'label' => 'Nombre del negocio',
    'type'  => 'text',
    'hint'  => 'Ej: KIOSCO XYZ',
    'max'   => 80,
  ],
  'business_cuit' => [
    'label' => 'CUIT',
    'type'  => 'text',
    'hint'  => 'Ej: 20-12345678-9',
    'max'   => 20,
  ],
  'business_address' => [
    'label' => 'Dirección',
    'type'  => 'textarea',
    'hint'  => 'Ej: Av. Siempre Viva 123',
    'max'   => 200,
  ],
  'business_phone' => [
    'label' => 'Teléfono',
    'type'  => 'text',
    'hint'  => 'Ej: 261-0000000',
    'max'   => 40,
  ],
  'ticket_footer' => [
    'label' => 'Pie del ticket',
    'type'  => 'textarea',
    'hint'  => 'Ej: Gracias por su compra',
    'max'   => 200,
  ],
  'qr_base_url' => [
    'label' => 'Base URL QR (futuro AFIP/ARCA)',
    'type'  => 'text',
    'hint'  => 'Ej: https://www.afip.gob.ar/fe/qr/ o tu endpoint',
    'max'   => 255,
  ],
];

$errors = [];
// Cargar valores actuales
$values = [];
foreach ($fields as $k => $meta) {
  $default = match ($k) {
    'business_name' => 'KIOSCO',
    'ticket_footer' => 'Gracias por su compra',
    'qr_base_url'   => 'https://www.arca.gob.ar/fe/qr/',
    default         => '',
  };
  $values[$k] = (string)(config_get($pdo, $k, $default) ?? $default);
}

// Guardar (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $errors[] = 'Token CSRF inválido. Recargá la página e intentá de nuevo.';
  } else {
    // Normalizar valores
    $newValues = [];
    foreach ($fields as $k => $meta) {
      $raw = (string)($_POST[$k] ?? '');
      $val = trim($raw);

      // recortar longitud
      $max = (int)($meta['max'] ?? 1000);
      if ($max > 0 && mb_strlen($val, 'UTF-8') > $max) {
        $val = mb_substr($val, 0, $max, 'UTF-8');
      }

      // Validaciones suaves
      if ($k === 'business_cuit' && $val !== '') {
        // dejar dígitos y guiones
        $val = preg_replace('/[^0-9\-]/', '', $val) ?? $val;
      }
      if ($k === 'qr_base_url' && $val !== '') {
        // si no es url válida, avisamos pero no bloqueamos duro (podés usar una interna)
        if (!filter_var($val, FILTER_VALIDATE_URL)) {
          $errors[] = 'La Base URL QR no parece una URL válida (igual podés usar una interna si querés).';
        }
      }

      $newValues[$k] = $val;
    }

    // Si no hay errores “duros”, guardamos
    if (!$errors) {
      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare("
          INSERT INTO app_config (k, v)
          VALUES (:k, :v)
          ON DUPLICATE KEY UPDATE v = :v2
        ");

        foreach ($newValues as $k => $v) {
          $st->execute([
            ':k'  => $k,
            ':v'  => $v,
            ':v2' => $v,
          ]);
        }

        $pdo->commit();

        // Redirect para evitar re-POST y para que se refresque el cache de config_get()
        header('Location: configuracion.php?saved=1');
        exit;

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Error guardando configuración: ' . $e->getMessage();
      }
    }

    // Si hubo errores, re-mostramos lo que escribió
    foreach ($newValues as $k => $v) {
      $values[$k] = $v;
    }
  }
}

/* HEADER */
$pageTitle      = 'Configuración';
$currentSection = 'configuracion';
$extraCss       = ['assets/css/configuracion.css?v=1'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel config-panel">

  <header class="page-header">
    <div>
      <h1 class="page-title">Configuración</h1>
      <p class="page-sub">Ajustes generales del sistema (ticket, negocio, etc.).</p>
    </div>
  </header>

  <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
    <div class="alert alert-success">✅ Guardado.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?= h(implode(' ', $errors)) ?>
    </div>
  <?php endif; ?>

  <form method="post" class="config-form">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

    <div class="config-grid">
      <?php foreach ($fields as $k => $meta): ?>
        <div class="config-field">
          <label for="<?= h($k) ?>"><?= h($meta['label']) ?></label>

          <?php if (($meta['type'] ?? 'text') === 'textarea'): ?>
            <textarea
              id="<?= h($k) ?>"
              name="<?= h($k) ?>"
              rows="3"
              placeholder="<?= h($meta['hint'] ?? '') ?>"
            ><?= h($values[$k] ?? '') ?></textarea>
          <?php else: ?>
            <input
              type="text"
              id="<?= h($k) ?>"
              name="<?= h($k) ?>"
              value="<?= h($values[$k] ?? '') ?>"
              placeholder="<?= h($meta['hint'] ?? '') ?>"
            >
          <?php endif; ?>

          <?php if (!empty($meta['hint'])): ?>
            <div class="config-hint"><?= h($meta['hint']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="config-actions">
      <button class="v-btn v-btn--primary" type="submit">Guardar</button>
      <a class="v-btn v-btn--ghost" href="configuracion.php">Cancelar</a>
      <a class="v-btn v-btn--outline" target="_blank" href="ticket.php?id=<?= (int)($_GET['ticket_test'] ?? 41) ?>&paper=80">
        Probar ticket
      </a>
    </div>

    <div class="config-note">
      Tip: “Probar ticket” abre un ticket de ejemplo. Podés cambiar el id con <span class="mono">?ticket_test=40</span>.
    </div>
  </form>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
