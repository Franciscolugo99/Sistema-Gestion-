<?php
// public/configuracion.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_config');


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

// Campos tipo toggle/switch (separados para renderizar distinto)
$toggleFields = [
  'facturacion_habilitada' => [
    'label'   => 'Módulo de Facturación',
    'desc'    => 'Habilitar facturación electrónica AFIP/ARCA',
    'hint'    => 'Activá esta opción si tu negocio emite comprobantes fiscales. Si no facturás, dejalo desactivado.',
    'default' => '0',
  ],
];

$printFields = [
  'print_ticket_mode' => [
    'label' => 'Ticket de venta',
    'default' => 'autoprint',
    'hint' => 'Define como sale el ticket despues de cobrar en Caja.',
    'options' => [
      'autoprint' => 'Auto imprimir',
      'preview' => 'Vista previa',
      'none' => 'No abrir ticket',
    ],
  ],
  'print_ticket_paper' => [
    'label' => 'Papel ticket',
    'default' => '80',
    'hint' => 'Ancho esperado para ticket termico.',
    'options' => [
      '80' => '80 mm',
      '58' => '58 mm',
    ],
  ],
  'print_comanda_mode' => [
    'label' => 'Comanda',
    'default' => 'none',
    'hint' => 'Perfil base para futuras comandas de cocina o preparacion.',
    'options' => [
      'none' => 'Desactivada',
      'preview' => 'Vista previa',
      'autoprint' => 'Auto imprimir',
    ],
  ],
  'print_comanda_paper' => [
    'label' => 'Papel comanda',
    'default' => '80',
    'hint' => 'Se usa cuando FLUS tenga salida de comanda separada.',
    'options' => [
      '80' => '80 mm',
      '58' => '58 mm',
    ],
  ],
  'print_factura_mode' => [
    'label' => 'Factura / comprobante',
    'default' => 'preview',
    'hint' => 'Para factura o fiscal suele convenir vista previa por control.',
    'options' => [
      'preview' => 'Vista previa',
      'autoprint' => 'Auto imprimir',
      'none' => 'No abrir',
    ],
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

// Cargar valores de toggles
$toggleValues = [];
foreach ($toggleFields as $k => $meta) {
  $default = $meta['default'] ?? '0';
  $toggleValues[$k] = (string)(config_get($pdo, $k, $default) ?? $default);
}

$printValues = [];
foreach ($printFields as $k => $meta) {
  $default = (string)($meta['default'] ?? '');
  $printValues[$k] = (string)(config_get($pdo, $k, $default) ?? $default);
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

    $newPrintValues = [];
    foreach ($printFields as $k => $meta) {
      $raw = trim((string)($_POST[$k] ?? ($meta['default'] ?? '')));
      $options = array_keys((array)($meta['options'] ?? []));
      $default = (string)($meta['default'] ?? '');
      $newPrintValues[$k] = in_array($raw, $options, true) ? $raw : $default;
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

        foreach ($newPrintValues as $k => $v) {
          $st->execute([
            ':k'  => $k,
            ':v'  => $v,
            ':v2' => $v,
          ]);
          $printValues[$k] = $v;
        }

        // Guardar toggles
        foreach ($toggleFields as $k => $meta) {
          $val = isset($_POST[$k]) ? '1' : '0';
          $st->execute([
            ':k'  => $k,
            ':v'  => $val,
            ':v2' => $val,
          ]);
          $toggleValues[$k] = $val;
        }

        $pdo->commit();
        
        // Limpiar caché de config para que se relea
        if (isset($GLOBALS['__app_config_cache'])) {
          $GLOBALS['__app_config_cache'] = [];
        }

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
    foreach ($newPrintValues as $k => $v) {
      $printValues[$k] = $v;
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

  <header class="page-header module-header">
    <div class="page-header-main module-header-main">
      <div class="module-header-hero">
        <span class="module-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82L4.21 7.2a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01A1.65 1.65 0 0 0 10 3.25V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>
          </svg>
        </span>
        <div class="module-header-copy">
          <span class="page-eyebrow module-eyebrow">Ajustes del sistema</span>
          <h1 class="page-title">Configuración</h1>
          <p class="page-sub">Ajustes generales del sistema (ticket, negocio, etc.).</p>
        </div>
      </div>
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

    <div class="config-section">
      <h3 class="config-section-title">Perfiles de impresion</h3>
      <p class="config-section-desc">Define el flujo base para ticket, comanda y factura. Las terminales pueden sobreescribir ticket y papel.</p>

      <div class="config-print-grid">
        <?php foreach ($printFields as $k => $meta): ?>
          <div class="config-field config-field--print">
            <label for="<?= h($k) ?>"><?= h($meta['label']) ?></label>
            <select id="<?= h($k) ?>" name="<?= h($k) ?>">
              <?php foreach (($meta['options'] ?? []) as $optValue => $optLabel): ?>
                <option value="<?= h((string)$optValue) ?>" <?= (($printValues[$k] ?? '') === (string)$optValue) ? 'selected' : '' ?>>
                  <?= h((string)$optLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($meta['hint'])): ?>
              <div class="config-hint"><?= h($meta['hint']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="config-print-note">
        La impresion realmente silenciosa depende del navegador, politicas del equipo o un agente local. Este perfil define el flujo de FLUS, pero no fuerza por si solo una impresora fisica concreta.
      </div>
    </div>

    <!-- Módulos opcionales -->
    <div class="config-section">
      <h3 class="config-section-title">Módulos opcionales</h3>
      <p class="config-section-desc">Activá o desactivá funcionalidades según las necesidades de tu negocio.</p>
      
      <div class="config-toggles">
        <?php foreach ($toggleFields as $k => $meta): 
          $isChecked = ($toggleValues[$k] ?? '0') === '1';
        ?>
          <div class="config-toggle-item">
            <div class="config-toggle-info">
              <label for="<?= h($k) ?>" class="config-toggle-label"><?= h($meta['label']) ?></label>
              <span class="config-toggle-desc"><?= h($meta['desc']) ?></span>
              <?php if (!empty($meta['hint'])): ?>
                <span class="config-toggle-hint"><?= h($meta['hint']) ?></span>
              <?php endif; ?>
            </div>
            <label class="config-switch">
              <input type="checkbox" 
                     id="<?= h($k) ?>" 
                     name="<?= h($k) ?>" 
                     value="1"
                     <?= $isChecked ? 'checked' : '' ?>>
              <span class="config-switch-slider"></span>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
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
