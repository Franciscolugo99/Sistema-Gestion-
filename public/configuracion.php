<?php
// public/configuracion.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/csrf.php';
require_once FLUS_ROOT . '/src/ticket_config_lib.php';
require_login();
require_permission('administrar_config');
$csrfToken = csrf_token();

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
    'label' => 'Direccion',
    'type'  => 'textarea',
    'hint'  => 'Ej: Av. Siempre Viva 123',
    'max'   => 200,
  ],
  'business_phone' => [
    'label' => 'Telefono',
    'type'  => 'text',
    'hint'  => 'Ej: 261-0000000',
    'max'   => 40,
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
    'label'   => 'Modulo de Facturacion',
    'desc'    => 'Habilitar facturacion electronica AFIP/ARCA',
    'hint'    => 'Activa esta opcion si tu negocio emite comprobantes fiscales. Si no facturas, dejalo desactivado.',
    'default' => '0',
  ],
];

$printFields = [
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
$ticketConfig = flus_ticket_global_config($pdo);
$ticketModeLabel = match ($ticketConfig['mode']) {
  'preview' => 'Vista previa en FLUS',
  'none' => 'No abrir automaticamente',
  default => 'Abrir dialogo de impresion',
};

// Guardar (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['csrf_token'] ?? '');
  if (!csrf_verify($token)) {
    $errors[] = 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.';
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
        // dejar digitos y guiones
        $val = preg_replace('/[^0-9\-]/', '', $val) ?? $val;
      }
      if ($k === 'qr_base_url' && $val !== '') {
        // si no es url valida, avisamos pero no bloqueamos duro (podes usar una interna)
        if (!filter_var($val, FILTER_VALIDATE_URL)) {
          $errors[] = 'La Base URL QR no parece una URL valida (igual podes usar una interna si queres).';
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

    // Si no hay errores duros, guardamos
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
        
        // Limpiar cache de config para que se relea
        if (isset($GLOBALS['__app_config_cache'])) {
          $GLOBALS['__app_config_cache'] = [];
        }

        // Redirect para evitar re-POST y para que se refresque el cache de config_get()
        header('Location: configuracion.php?saved=1');
        exit;

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Error guardando configuracion: ' . $e->getMessage();
      }
    }

    // Si hubo errores, re-mostramos lo que escribio
    foreach ($newValues as $k => $v) {
      $values[$k] = $v;
    }
    foreach ($newPrintValues as $k => $v) {
      $printValues[$k] = $v;
    }
  }
}

/* HEADER */
$pageTitle      = 'Configuracion';
$currentSection = 'configuracion';
$extraCss       = ['assets/css/configuracion.css'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel config-panel">
  <header class="cfg-header">
    <div class="cfg-header-inner">
      <div class="cfg-header-title">
        <span class="cfg-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82L4.21 7.2a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01A1.65 1.65 0 0 0 10 3.25V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>
          </svg>
        </span>
        <h1 class="cfg-title">Configuracion</h1>
      </div>
    </div>
  </header>

  <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
    <div class="alert alert-success">Cambios guardados.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-error"><?= h(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <form method="post" class="config-form" id="configForm">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

    <div class="cfg-tabs" role="tablist" aria-label="Secciones de configuracion">
      <button
        type="button"
        class="cfg-tab is-active"
        id="tab-cfg-negocio"
        data-target="cfg-negocio"
        role="tab"
        aria-selected="true"
        aria-controls="cfg-negocio"
      >
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        Negocio
      </button>
      <button
        type="button"
        class="cfg-tab"
        id="tab-cfg-impresion"
        data-target="cfg-impresion"
        role="tab"
        aria-selected="false"
        aria-controls="cfg-impresion"
      >
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <polyline points="6 9 6 2 18 2 18 9"/>
          <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
          <rect x="6" y="14" width="12" height="8"/>
        </svg>
        Impresion
      </button>
      <button
        type="button"
        class="cfg-tab"
        id="tab-cfg-modulos"
        data-target="cfg-modulos"
        role="tab"
        aria-selected="false"
        aria-controls="cfg-modulos"
      >
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <rect x="3" y="3" width="7" height="7"/>
          <rect x="14" y="3" width="7" height="7"/>
          <rect x="3" y="14" width="7" height="7"/>
          <rect x="14" y="14" width="7" height="7"/>
        </svg>
        Modulos
      </button>
    </div>

    <div class="cfg-panel is-active" id="cfg-negocio" role="tabpanel" aria-labelledby="tab-cfg-negocio">
      <div class="config-grid">
        <div class="config-field">
          <label for="business_name">Nombre del negocio</label>
          <input
            type="text"
            id="business_name"
            name="business_name"
            value="<?= h($values['business_name'] ?? '') ?>"
            placeholder="Ej: KIOSCO XYZ"
            maxlength="80"
          >
        </div>

        <div class="config-field">
          <label for="business_cuit">CUIT</label>
          <input
            type="text"
            id="business_cuit"
            name="business_cuit"
            value="<?= h($values['business_cuit'] ?? '') ?>"
            placeholder="Ej: 20-12345678-9"
            maxlength="20"
          >
        </div>

        <div class="config-field">
          <label for="business_phone">Telefono</label>
          <input
            type="text"
            id="business_phone"
            name="business_phone"
            value="<?= h($values['business_phone'] ?? '') ?>"
            placeholder="Ej: 261-0000000"
            maxlength="40"
          >
        </div>

        <div class="config-field">
          <label for="qr_base_url">Base URL QR <span class="cfg-label-badge">AFIP/ARCA</span></label>
          <input
            type="text"
            id="qr_base_url"
            name="qr_base_url"
            value="<?= h($values['qr_base_url'] ?? '') ?>"
            placeholder="https://www.arca.gob.ar/fe/qr/"
            maxlength="255"
          >
        </div>

        <div class="config-field config-field--wide">
          <label for="business_address">Direccion</label>
          <textarea
            id="business_address"
            name="business_address"
            rows="2"
            placeholder="Ej: Av. Siempre Viva 123"
          ><?= h($values['business_address'] ?? '') ?></textarea>
        </div>

      </div>
    </div>

    <div class="cfg-panel" id="cfg-impresion" role="tabpanel" aria-labelledby="tab-cfg-impresion">
      <div class="cfg-print-groups">
        <section class="cfg-ticket-handoff" aria-labelledby="cfg-ticket-handoff-title">
          <div class="cfg-ticket-handoff__main">
            <span class="cfg-ticket-handoff__icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect x="6" y="14" width="12" height="8"/>
              </svg>
            </span>
            <div>
              <span class="cfg-ticket-handoff__eyebrow">Perfil unificado</span>
              <h3 id="cfg-ticket-handoff-title">Ticket de venta</h3>
              <p>El papel, la salida, el logo y los datos visibles se administran en un solo lugar.</p>
            </div>
          </div>
          <dl class="cfg-ticket-handoff__status">
            <div>
              <dt>Papel</dt>
              <dd><?= h($ticketConfig['paper']) ?> mm</dd>
            </div>
            <div>
              <dt>Al cobrar</dt>
              <dd><?= h($ticketModeLabel) ?></dd>
            </div>
          </dl>
          <a class="v-btn v-btn--outline" href="ticket_config.php">Configurar tickets</a>
        </section>

        <div class="cfg-print-group">
          <h4 class="cfg-print-group-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
            </svg>
            Comanda
          </h4>
          <div class="cfg-print-row">
            <div class="config-field">
              <label for="print_comanda_mode">Modo</label>
              <select id="print_comanda_mode" name="print_comanda_mode">
                <?php foreach ($printFields['print_comanda_mode']['options'] as $v => $l): ?>
                  <option value="<?= h((string)$v) ?>" <?= ($printValues['print_comanda_mode'] === (string)$v) ? 'selected' : '' ?>><?= h((string)$l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="config-field">
              <label for="print_comanda_paper">Papel</label>
              <select id="print_comanda_paper" name="print_comanda_paper">
                <?php foreach ($printFields['print_comanda_paper']['options'] as $v => $l): ?>
                  <option value="<?= h((string)$v) ?>" <?= ($printValues['print_comanda_paper'] === (string)$v) ? 'selected' : '' ?>><?= h((string)$l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="cfg-print-hint"><?= h($printFields['print_comanda_mode']['hint']) ?></p>
        </div>

        <div class="cfg-print-group">
          <h4 class="cfg-print-group-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <rect x="1" y="4" width="22" height="16" rx="2"/>
              <line x1="1" y1="10" x2="23" y2="10"/>
            </svg>
            Factura / comprobante
          </h4>
          <div class="cfg-print-row cfg-print-row--single">
            <div class="config-field">
              <label for="print_factura_mode">Modo</label>
              <select id="print_factura_mode" name="print_factura_mode">
                <?php foreach ($printFields['print_factura_mode']['options'] as $v => $l): ?>
                  <option value="<?= h((string)$v) ?>" <?= ($printValues['print_factura_mode'] === (string)$v) ? 'selected' : '' ?>><?= h((string)$l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="cfg-print-hint"><?= h($printFields['print_factura_mode']['hint']) ?></p>
        </div>
      </div>

      <div class="config-print-note">
        La impresion silenciosa depende del navegador y politicas del equipo. Este perfil define el flujo de FLUS, pero no fuerza una impresora fisica concreta.
      </div>
    </div>

    <div class="cfg-panel" id="cfg-modulos" role="tabpanel" aria-labelledby="tab-cfg-modulos">
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
              <input
                type="checkbox"
                id="<?= h($k) ?>"
                name="<?= h($k) ?>"
                value="1"
                <?= $isChecked ? 'checked' : '' ?>
              >
              <span class="config-switch-slider"></span>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="config-actions">
      <button class="v-btn v-btn--primary" type="submit">Guardar</button>
      <a class="v-btn v-btn--ghost" href="configuracion.php">Cancelar</a>
    </div>
  </form>
</div>

<script>
(function () {
  'use strict';

  var tabs = document.querySelectorAll('.cfg-tab');
  var panels = document.querySelectorAll('.cfg-panel');

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (currentTab) {
        currentTab.classList.remove('is-active');
        currentTab.setAttribute('aria-selected', 'false');
      });

      panels.forEach(function (currentPanel) {
        currentPanel.classList.remove('is-active');
      });

      tab.classList.add('is-active');
      tab.setAttribute('aria-selected', 'true');

      var target = document.getElementById(tab.dataset.target);
      if (target) {
        target.classList.add('is-active');
      }
    });
  });

  var firstError = document.querySelector('.config-field input:invalid, .config-field textarea:invalid');
  if (firstError) {
    var panel = firstError.closest('.cfg-panel');
    if (panel) {
      panels.forEach(function (currentPanel) {
        currentPanel.classList.remove('is-active');
      });

      tabs.forEach(function (currentTab) {
        currentTab.classList.remove('is-active');
        currentTab.setAttribute('aria-selected', 'false');
      });

      panel.classList.add('is-active');

      var matchTab = document.querySelector('[data-target="' + panel.id + '"]');
      if (matchTab) {
        matchTab.classList.add('is-active');
        matchTab.setAttribute('aria-selected', 'true');
      }
    }
  }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  
