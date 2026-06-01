<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/csrf.php';
require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';

require_login();
require_permission('administrar_config');

function mpcfg_path(): string
{
    return FLUS_ROOT . '/src/config_mp.php';
}

function mpcfg_token_kind(string $token): string
{
    if ($token === '') return 'Sin token';
    if (str_starts_with($token, 'TEST-')) return 'Prueba';
    if (str_starts_with($token, 'APP_USR-')) return 'Produccion';
    return 'Token no reconocido';
}

function mpcfg_mask_token(string $token): string
{
    if ($token === '') return 'Sin configurar';
    $prefix = str_starts_with($token, 'TEST-') ? 'TEST' : (str_starts_with($token, 'APP_USR-') ? 'APP_USR' : 'TOKEN');
    return $prefix . '-...' . substr($token, -6);
}

function mpcfg_current_values(): array
{
    $assets = flus_mp_qr_static_assets();
    return [
        'access_token' => flus_mp_qr_access_token(),
        'cashier_mode' => flus_mp_cashier_mode(),
        'manual_fallback' => flus_mp_manual_fallback_enabled(),
        'qr_external_pos_id' => flus_mp_qr_external_pos_id(),
        'qr_mode' => flus_mp_qr_mode(),
        'qr_description' => flus_mp_qr_description(),
        'qr_image_url' => (string)($assets['image'] ?? ''),
        'qr_template_document_url' => (string)($assets['template_document'] ?? ''),
        'qr_template_image_url' => (string)($assets['template_image'] ?? ''),
        'point_terminal_id' => flus_mp_point_terminal_id(),
    ];
}

function mpcfg_normalize_url(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

function mpcfg_write_config(array $values): void
{
    $path = mpcfg_path();
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('No se puede escribir src/config_mp.php. Revisa permisos de la carpeta src.');
    }

    $constants = [
        'FLUS_MP_ACCESS_TOKEN' => (string)($values['access_token'] ?? ''),
        'FLUS_MP_CASHIER_MODE' => (string)($values['cashier_mode'] ?? 'automatic'),
        'FLUS_MP_MANUAL_FALLBACK' => !empty($values['manual_fallback']),
        'FLUS_MP_QR_EXTERNAL_POS_ID' => (string)($values['qr_external_pos_id'] ?? ''),
        'FLUS_MP_QR_MODE' => (string)($values['qr_mode'] ?? 'hybrid'),
        'FLUS_MP_QR_DESCRIPTION' => (string)($values['qr_description'] ?? 'Venta FLUS QR'),
        'FLUS_MP_QR_IMAGE_URL' => (string)($values['qr_image_url'] ?? ''),
        'FLUS_MP_QR_TEMPLATE_DOCUMENT_URL' => (string)($values['qr_template_document_url'] ?? ''),
        'FLUS_MP_QR_TEMPLATE_IMAGE_URL' => (string)($values['qr_template_image_url'] ?? ''),
        'FLUS_MP_POINT_TERMINAL_ID' => (string)($values['point_terminal_id'] ?? ''),
    ];

    $php = "<?php\n";
    $php .= "// src/config_mp.php\n";
    $php .= "// Configuracion local de Mercado Pago. No versionar este archivo.\n";
    $php .= "declare(strict_types=1);\n\n";
    foreach ($constants as $name => $value) {
        $php .= "define('" . $name . "', " . var_export($value, true) . ");\n";
    }

    if (is_file($path)) {
        @copy($path, $path . '.bak');
    }
    if (file_put_contents($path, $php, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar src/config_mp.php.');
    }
}

function mpcfg_status_class(bool $ok, bool $warning = false): string
{
    return $ok ? 'ok' : ($warning ? 'warning' : 'error');
}

function mpcfg_apply_pos_to_values(array $values, array $pos): array
{
    $qr = is_array($pos['qr'] ?? null) ? $pos['qr'] : [];
    $values['qr_external_pos_id'] = (string)($pos['external_id'] ?? $values['qr_external_pos_id']);
    $values['qr_image_url'] = (string)($qr['image'] ?? $values['qr_image_url']);
    $values['qr_template_document_url'] = (string)($qr['template_document'] ?? $values['qr_template_document_url']);
    $values['qr_template_image_url'] = (string)($qr['template_image'] ?? $values['qr_template_image_url']);
    return $values;
}

$csrfToken = csrf_token();
$message = '';
$error = '';
$errorDetail = '';
$values = mpcfg_current_values();
$configExists = is_file(mpcfg_path());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_verify($token)) {
        $error = 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.';
    } else {
        $action = (string)($_POST['accion'] ?? '');
        try {
            if ($action === 'guardar_config') {
                $newToken = trim((string)($_POST['access_token'] ?? ''));
                $mode = strtolower(trim((string)($_POST['qr_mode'] ?? 'hybrid')));
                if (!in_array($mode, ['dynamic', 'static', 'hybrid'], true)) {
                    $mode = 'hybrid';
                }
                $cashierMode = strtolower(trim((string)($_POST['cashier_mode'] ?? 'automatic')));
                if (!in_array($cashierMode, ['automatic', 'manual'], true)) {
                    $cashierMode = 'automatic';
                }
                $values = [
                    'access_token' => $newToken !== '' ? $newToken : $values['access_token'],
                    'cashier_mode' => $cashierMode,
                    'manual_fallback' => (string)($_POST['manual_fallback'] ?? '0') === '1',
                    'qr_external_pos_id' => preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['qr_external_pos_id'] ?? '')) ?: '',
                    'qr_mode' => $mode,
                    'qr_description' => mb_substr(trim((string)($_POST['qr_description'] ?? 'Venta FLUS QR')), 0, 150, 'UTF-8'),
                    'qr_image_url' => mpcfg_normalize_url((string)($_POST['qr_image_url'] ?? '')),
                    'qr_template_document_url' => mpcfg_normalize_url((string)($_POST['qr_template_document_url'] ?? '')),
                    'qr_template_image_url' => mpcfg_normalize_url((string)($_POST['qr_template_image_url'] ?? '')),
                    'point_terminal_id' => preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['point_terminal_id'] ?? '')) ?: '',
                ];
                mpcfg_write_config($values);
                $message = 'Configuracion Mercado Pago guardada.';
            }

            if ($action === 'refresh_qr') {
                $result = flus_mp_qr_get_configured_pos();
                if (!($result['ok'] ?? false)) {
                    throw new RuntimeException((string)($result['error'] ?? 'No se pudo consultar la caja QR.'));
                }
                $values = mpcfg_apply_pos_to_values($values, (array)($result['pos'] ?? []));
                mpcfg_write_config($values);
                $message = 'QR estatico actualizado desde Mercado Pago.';
            }

            if ($action === 'test_connection') {
                $result = flus_mp_qr_get_configured_pos();
                if (!($result['ok'] ?? false)) {
                    throw new RuntimeException((string)($result['error'] ?? 'No se pudo conectar con Mercado Pago.'));
                }
                $values = mpcfg_apply_pos_to_values($values, (array)($result['pos'] ?? []));
                $message = 'Mercado Pago respondio correctamente.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $errorDetail = $e instanceof RuntimeException ? '' : (string)$e;
        }
    }
}

$tokenPresent = $values['access_token'] !== '';
$tokenKind = mpcfg_token_kind($values['access_token']);
$curlOk = function_exists('curl_init');
$qrConfigured = $values['access_token'] !== '' && $values['qr_external_pos_id'] !== '';
$automaticMode = $values['cashier_mode'] === 'automatic';
$staticReady = $values['qr_image_url'] !== '' && $values['qr_template_document_url'] !== '' && $values['qr_template_image_url'] !== '';
$pointReady = $values['access_token'] !== '' && $values['point_terminal_id'] !== '';
$posResult = null;
$terminalsResult = null;

if ($qrConfigured && $curlOk) {
    $posResult = flus_mp_qr_get_configured_pos();
    if (($posResult['ok'] ?? false) && is_array($posResult['pos'] ?? null)) {
        $values = mpcfg_apply_pos_to_values($values, (array)$posResult['pos']);
    }
}
if ($tokenPresent && $curlOk) {
    $pos = is_array($posResult['pos'] ?? null) ? $posResult['pos'] : [];
    $terminalsResult = flus_mp_point_list_terminals(
        (string)($pos['store_id'] ?? ''),
        (string)($pos['id'] ?? '')
    );
}

$posOk = (bool)($posResult['ok'] ?? false);
$pos = is_array($posResult['pos'] ?? null) ? $posResult['pos'] : [];
$terminals = is_array($terminalsResult['terminals'] ?? null) ? $terminalsResult['terminals'] : [];
$selectedTerminal = $values['point_terminal_id'];

$pageTitle = 'Mercado Pago';
$currentSection = 'configuracion';
$breadcrumbs = [
    ['label' => 'Configuracion', 'url' => 'configuracion.php'],
    ['label' => 'Mercado Pago', 'url' => null],
];
$extraCss = ['assets/css/mercadopago_config.css'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel mpcfg-panel">
  <header class="mpcfg-header">
    <div>
      <span class="mpcfg-eyebrow">Integraciones</span>
      <h1>Mercado Pago</h1>
      <p>Controla QR, Point y credenciales desde un solo lugar.</p>
    </div>
    <div class="mpcfg-header-actions">
      <a class="v-btn v-btn--outline" href="mp_qr_test.php">Probar QR</a>
      <a class="v-btn v-btn--ghost" href="configuracion.php">Volver</a>
    </div>
  </header>

  <?php if ($message !== ''): ?>
    <div class="mpcfg-alert ok"><?= h($message) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="mpcfg-alert error">
      <strong><?= h($error) ?></strong>
      <?php if ($errorDetail !== ''): ?><pre><?= h($errorDetail) ?></pre><?php endif; ?>
    </div>
  <?php endif; ?>

  <section class="mpcfg-section">
    <h2>Estado actual</h2>
    <div class="mpcfg-status-grid">
      <div class="mpcfg-status <?= mpcfg_status_class($configExists, true) ?>">
        <span>Archivo config</span>
        <strong><?= $configExists ? 'src/config_mp.php' : 'Pendiente' ?></strong>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class($tokenPresent) ?>">
        <span>Access Token</span>
        <strong><?= h(mpcfg_mask_token($values['access_token'])) ?></strong>
        <small><?= h($tokenKind) ?></small>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class($curlOk) ?>">
        <span>PHP cURL</span>
        <strong><?= $curlOk ? 'Habilitado' : 'No disponible' ?></strong>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class($automaticMode, true) ?>">
        <span>Modo caja</span>
        <strong><?= $automaticMode ? 'Automatico' : 'Manual rapido' ?></strong>
        <small><?= $automaticMode ? 'Intenta confirmar con API' : 'Registra MP sin API' ?></small>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class((bool)$values['manual_fallback'], true) ?>">
        <span>Sin conexion</span>
        <strong><?= !empty($values['manual_fallback']) ? 'Fallback manual' : 'Bloquea automatico' ?></strong>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class($qrConfigured) ?>">
        <span>Caja QR</span>
        <strong><?= h($values['qr_external_pos_id'] !== '' ? $values['qr_external_pos_id'] : 'Sin POS externo') ?></strong>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class($values['qr_mode'] === 'hybrid', $values['qr_mode'] !== '') ?>">
        <span>Modo QR</span>
        <strong><?= h($values['qr_mode']) ?></strong>
        <small>Hybrid permite QR impreso y QR en pantalla</small>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class($posOk, $qrConfigured) ?>">
        <span>Consulta Mercado Pago</span>
        <strong><?= $posOk ? 'Caja encontrada' : 'Sin verificar' ?></strong>
        <?php if (!$posOk && !empty($posResult['error'])): ?><small><?= h((string)$posResult['error']) ?></small><?php endif; ?>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class($staticReady) ?>">
        <span>QR estatico</span>
        <strong><?= $staticReady ? 'Listo para imprimir' : 'Faltan links' ?></strong>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class($pointReady, true) ?>">
        <span>Point</span>
        <strong><?= $pointReady ? 'Terminal configurada' : 'Pendiente' ?></strong>
      </div>
    </div>
  </section>

  <div class="mpcfg-layout">
    <section class="mpcfg-section">
      <h2>Configuracion</h2>
      <form method="post" class="mpcfg-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="accion" value="guardar_config">

        <label class="mpcfg-field mpcfg-field--wide">
          <span>Access Token</span>
          <input type="password" name="access_token" autocomplete="off" placeholder="<?= h(mpcfg_mask_token($values['access_token'])) ?>">
          <small>Dejalo vacio para conservar el token actual.</small>
        </label>

        <label class="mpcfg-field">
          <span>Modo en caja</span>
          <select name="cashier_mode">
            <option value="manual" <?= $values['cashier_mode'] === 'manual' ? 'selected' : '' ?>>Manual rapido</option>
            <option value="automatic" <?= $values['cashier_mode'] === 'automatic' ? 'selected' : '' ?>>Automatico QR/Point</option>
          </select>
          <small>Manual registra el cobro sin consultar Mercado Pago.</small>
        </label>

        <label class="mpcfg-check">
          <input type="checkbox" name="manual_fallback" value="1" <?= !empty($values['manual_fallback']) ? 'checked' : '' ?>>
          <span>
            <strong>Permitir manual si falla la conexion</strong>
            <small>Si la PC queda sin internet, el cajero puede registrar lo aprobado en app o posnet.</small>
          </span>
        </label>

        <label class="mpcfg-field">
          <span>POS externo QR</span>
          <input name="qr_external_pos_id" value="<?= h($values['qr_external_pos_id']) ?>" maxlength="80">
        </label>

        <label class="mpcfg-field">
          <span>Modo QR</span>
          <select name="qr_mode">
            <?php foreach (['hybrid' => 'Hybrid', 'static' => 'Static', 'dynamic' => 'Dynamic'] as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $values['qr_mode'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="mpcfg-field mpcfg-field--wide">
          <span>Descripcion QR</span>
          <input name="qr_description" value="<?= h($values['qr_description']) ?>" maxlength="150">
        </label>

        <label class="mpcfg-field mpcfg-field--wide">
          <span>Terminal Point</span>
          <input name="point_terminal_id" value="<?= h($values['point_terminal_id']) ?>" maxlength="120" list="mpcfgTerminals">
          <small>Se obtiene desde las terminals asociadas a la cuenta.</small>
        </label>

        <datalist id="mpcfgTerminals">
          <?php foreach ($terminals as $terminal): ?>
            <?php $terminalId = (string)($terminal['id'] ?? ''); if ($terminalId === '') continue; ?>
            <option value="<?= h($terminalId) ?>"><?= h((string)($terminal['operating_mode'] ?? '')) ?></option>
          <?php endforeach; ?>
        </datalist>

        <input type="hidden" name="qr_image_url" value="<?= h($values['qr_image_url']) ?>">
        <input type="hidden" name="qr_template_document_url" value="<?= h($values['qr_template_document_url']) ?>">
        <input type="hidden" name="qr_template_image_url" value="<?= h($values['qr_template_image_url']) ?>">

        <div class="mpcfg-actions">
          <button class="v-btn v-btn--primary" type="submit">Guardar configuracion</button>
        </div>
      </form>
    </section>

    <section class="mpcfg-section">
      <h2>QR estatico</h2>
      <?php if ($staticReady): ?>
        <div class="mpcfg-qr-preview">
          <img src="<?= h($values['qr_image_url']) ?>" alt="QR estatico Mercado Pago">
        </div>
        <div class="mpcfg-link-actions">
          <a class="v-btn v-btn--primary" target="_blank" href="<?= h($values['qr_template_document_url']) ?>">Abrir PDF</a>
          <a class="v-btn v-btn--outline" target="_blank" href="<?= h($values['qr_template_image_url']) ?>">Abrir cartel PNG</a>
          <a class="v-btn v-btn--ghost" target="_blank" href="<?= h($values['qr_image_url']) ?>">Abrir QR solo</a>
        </div>
      <?php else: ?>
        <p class="mpcfg-muted">Todavia no hay links de QR estatico guardados para esta caja.</p>
      <?php endif; ?>

      <form method="post" class="mpcfg-inline-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="accion" value="refresh_qr">
        <button class="v-btn v-btn--outline" type="submit" <?= $qrConfigured ? '' : 'disabled' ?>>Generar / actualizar QR estatico</button>
      </form>

      <?php if ($posOk): ?>
        <dl class="mpcfg-details">
          <div><dt>POS ID</dt><dd><?= h((string)($pos['id'] ?? '-')) ?></dd></div>
          <div><dt>Nombre</dt><dd><?= h((string)($pos['name'] ?? '-')) ?></dd></div>
          <div><dt>Sucursal</dt><dd><?= h((string)($pos['external_store_id'] ?? '-')) ?></dd></div>
        </dl>
      <?php endif; ?>
    </section>
  </div>

  <section class="mpcfg-section">
    <div class="mpcfg-section-head">
      <div>
        <h2>Point</h2>
        <p>Para cobrar con tarjeta, la terminal debe aparecer aca y operar en modo PDV.</p>
      </div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="accion" value="test_connection">
        <button class="v-btn v-btn--outline" type="submit" <?= $tokenPresent ? '' : 'disabled' ?>>Probar conexion</button>
      </form>
    </div>

    <?php if ($terminals === []): ?>
      <p class="mpcfg-muted">No se encontraron terminals Point asociadas a esta caja. Si tenes Point fisico, vinculalo desde Mercado Pago y volve a probar.</p>
    <?php else: ?>
      <div class="mpcfg-terminal-list">
        <?php foreach ($terminals as $terminal): ?>
          <?php $terminalId = (string)($terminal['id'] ?? ''); ?>
          <div class="mpcfg-terminal <?= $terminalId !== '' && $terminalId === $selectedTerminal ? 'is-selected' : '' ?>">
            <strong><?= h($terminalId !== '' ? $terminalId : 'Terminal sin id') ?></strong>
            <span><?= h((string)($terminal['operating_mode'] ?? 'Modo desconocido')) ?></span>
            <small>POS <?= h((string)($terminal['pos_id'] ?? '-')) ?> · Store <?= h((string)($terminal['store_id'] ?? '-')) ?></small>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="mpcfg-section mpcfg-help">
    <h2>Uso operativo</h2>
    <ol>
      <li>Usa <strong>Manual rapido</strong> si el negocio solo quiere registrar lo cobrado por Mercado Pago sin integrar credenciales.</li>
      <li>Usa <strong>Automatico QR/Point</strong> cuando haya token, QR o terminal Point configurada y la PC tenga internet.</li>
      <li>Deja el QR en <strong>Hybrid</strong> para que el mismo cobro funcione con el QR impreso y con el QR en pantalla.</li>
      <li>Para Point, completa la terminal y cobra con <strong>Debito</strong> o <strong>Credito</strong> como unico medio de pago.</li>
    </ol>
  </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
