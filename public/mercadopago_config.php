<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/csrf.php';
require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';
require_once FLUS_ROOT . '/src/mercadopago_integration_lib.php';

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
    if (str_starts_with($token, 'APP_USR-')) return 'Credencial APP_USR';
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
        'environment' => flus_mp_environment(flus_mp_qr_config_value('FLUS_MP_ENVIRONMENT', 'FLUS_MP_ENVIRONMENT', 'test')),
        'access_token' => flus_mp_qr_access_token(),
        'webhook_secret' => flus_mp_qr_config_value('FLUS_MP_WEBHOOK_SECRET', 'FLUS_MP_WEBHOOK_SECRET'),
        'webhook_url' => flus_mp_qr_config_value('FLUS_MP_WEBHOOK_URL', 'FLUS_MP_WEBHOOK_URL'),
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
        'FLUS_MP_ENVIRONMENT' => flus_mp_environment((string)($values['environment'] ?? 'test')),
        'FLUS_MP_ACCESS_TOKEN' => (string)($values['access_token'] ?? ''),
        'FLUS_MP_WEBHOOK_SECRET' => (string)($values['webhook_secret'] ?? ''),
        'FLUS_MP_WEBHOOK_URL' => (string)($values['webhook_url'] ?? ''),
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

function mpcfg_badge(bool $ok, string $okLabel, string $badLabel, string $badClass = 'warning'): array
{
    return [
        'class' => $ok ? 'ok' : $badClass,
        'label' => $ok ? $okLabel : $badLabel,
    ];
}

$csrfToken = csrf_token();
$message = '';
$error = '';
$errorDetail = '';
$values = mpcfg_current_values();
$configExists = is_file(mpcfg_path());
$pdo = getPDO();
$setupValues = [
    'user_id' => '',
    'store_name' => '',
    'street_name' => '',
    'street_number' => '',
    'city_name' => '',
    'state_name' => '',
    'coordinates' => '',
    'pos_name' => 'Caja 1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_verify($token)) {
        $error = 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.';
    } else {
        $action = (string)($_POST['accion'] ?? '');
        try {
            if ($action === 'guardar_config') {
                $newToken = trim((string)($_POST['access_token'] ?? ''));
                $newWebhookSecret = trim((string)($_POST['webhook_secret'] ?? ''));
                $rawWebhookUrl = trim((string)($_POST['webhook_url'] ?? ''));
                $newWebhookUrl = mpcfg_normalize_url($rawWebhookUrl);
                if ($rawWebhookUrl !== '' && ($newWebhookUrl === '' || !str_starts_with(strtolower($newWebhookUrl), 'https://'))) {
                    throw new RuntimeException('La URL publica del webhook debe ser valida y usar HTTPS.');
                }
                $environment = flus_mp_environment((string)($_POST['environment'] ?? 'test'));
                $mode = strtolower(trim((string)($_POST['qr_mode'] ?? 'hybrid')));
                if (!in_array($mode, ['dynamic', 'static', 'hybrid'], true)) {
                    $mode = 'hybrid';
                }
                $cashierMode = strtolower(trim((string)($_POST['cashier_mode'] ?? 'automatic')));
                if (!in_array($cashierMode, ['automatic', 'manual'], true)) {
                    $cashierMode = 'automatic';
                }
                $values = [
                    'environment' => $environment,
                    'access_token' => $newToken !== '' ? $newToken : $values['access_token'],
                    'webhook_secret' => $newWebhookSecret !== '' ? $newWebhookSecret : $values['webhook_secret'],
                    'webhook_url' => $newWebhookUrl !== '' ? $newWebhookUrl : $values['webhook_url'],
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

            if ($action === 'create_qr_setup') {
                foreach (array_keys($setupValues) as $key) {
                    $setupValues[$key] = trim((string)($_POST[$key] ?? ''));
                }
                $setupToken = trim((string)($_POST['setup_access_token'] ?? ''));
                $setupEnvironment = flus_mp_environment((string)($_POST['setup_environment'] ?? 'test'));
                if ($setupEnvironment === 'production') {
                    $identityResult = flus_mp_qr_current_user($setupToken);
                    if (!($identityResult['ok'] ?? false)) {
                        throw new RuntimeException(
                            'No se pudo validar la cuenta productiva: '
                            . (string)($identityResult['error'] ?? 'Credencial rechazada por Mercado Pago.')
                        );
                    }
                    $setupValues['user_id'] = (string)$identityResult['user_id'];
                } elseif ($setupValues['user_id'] === '') {
                    throw new RuntimeException('Completa el User ID de las credenciales de prueba.');
                }
                $coordinateParts = preg_split('/\s*,\s*/', $setupValues['coordinates'], 2) ?: [];
                $setupInput = [
                    'user_id' => $setupValues['user_id'],
                    'store_name' => $setupValues['store_name'],
                    'street_name' => $setupValues['street_name'],
                    'street_number' => $setupValues['street_number'],
                    'city_name' => $setupValues['city_name'],
                    'state_name' => $setupValues['state_name'],
                    'latitude' => $coordinateParts[0] ?? null,
                    'longitude' => $coordinateParts[1] ?? null,
                    'pos_name' => $setupValues['pos_name'],
                ];
                $integrationState = flus_mp_integration_prepare($pdo, $setupEnvironment, $setupToken, $setupInput);
                $setupInput['store_id'] = (string)($integrationState['store_id'] ?? '');
                $setupInput['store_external_id'] = (string)($integrationState['store_external_id'] ?? '');
                $setupInput['pos_external_id'] = (string)($integrationState['pos_external_id'] ?? '');
                $result = flus_mp_qr_create_store_and_pos($setupToken, $setupInput);
                flus_mp_integration_record_result($pdo, $setupEnvironment, $result);
                if (!($result['ok'] ?? false)) {
                    $detail = is_array($result['response'] ?? null)
                        ? json_encode($result['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                        : '';
                    throw new RuntimeException(
                        (string)($result['error'] ?? 'No se pudo crear la sucursal y caja.')
                        . ($detail !== '' && $detail !== '[]' ? "\n" . $detail : '')
                    );
                }

                $values['environment'] = $setupEnvironment;
                $values['access_token'] = $setupToken;
                $values['cashier_mode'] = 'automatic';
                $values['manual_fallback'] = true;
                $values['qr_mode'] = 'hybrid';
                $values = mpcfg_apply_pos_to_values($values, (array)($result['pos'] ?? []));
                mpcfg_write_config($values);
                $setupValues = array_fill_keys(array_keys($setupValues), '');
                $setupValues['pos_name'] = 'Caja 1';
                $message = 'Sucursal y caja QR creadas. FLUS guardo el POS externo y los enlaces del QR.';
            }
        } catch (Throwable $e) {
            $parts = explode("\n", $e->getMessage(), 2);
            $error = $parts[0];
            $errorDetail = $parts[1] ?? ($e instanceof RuntimeException ? '' : (string)$e);
        }
    }
}

$tokenPresent = $values['access_token'] !== '';
$activeEnvironment = flus_mp_environment((string)$values['environment']);
$setupSelectedEnvironment = flus_mp_environment((string)($_POST['setup_environment'] ?? $activeEnvironment));
$webhookSecretConfigured = trim((string)$values['webhook_secret']) !== '';
$integrationState = flus_mp_integration_state($pdo, $activeEnvironment);
$webhookScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$webhookHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$webhookBase = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
$localWebhookUrl = $webhookHost !== ''
    ? $webhookScheme . '://' . $webhookHost . rtrim($webhookBase, '/') . '/mercadopago_webhook.php'
    : '';
$webhookUrl = trim((string)$values['webhook_url']);
$webhookConfigured = $webhookSecretConfigured && str_starts_with(strtolower($webhookUrl), 'https://');
$recentWebhookStmt = $pdo->prepare(
    'SELECT event_type, action_name, resource_id, status, order_status, error_message, received_at
     FROM mercadopago_webhook_eventos
     WHERE environment = ?
     ORDER BY id DESC
     LIMIT 8'
);
$recentWebhookStmt->execute([$activeEnvironment]);
$recentWebhookEvents = $recentWebhookStmt->fetchAll(PDO::FETCH_ASSOC);
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
$qrReady = $tokenPresent && $curlOk && $qrConfigured;
$automaticReady = $automaticMode && $curlOk && $tokenPresent && ($qrConfigured || $pointReady);
$manualAvailable = $values['cashier_mode'] === 'manual' || !empty($values['manual_fallback']);
$mpOperational = $automaticReady || $manualAvailable;
$mpReadinessClass = $automaticReady ? 'ok' : ($manualAvailable ? 'warning' : 'error');
$mpReadinessTitle = $automaticReady ? 'Listo para caja' : ($manualAvailable ? 'Listo con control manual' : 'Requiere ajuste');
$mpReadinessText = $automaticReady
    ? 'La caja puede intentar confirmar Mercado Pago por API. Si falla la conexion, el fallback manual depende de la opcion configurada.'
    : ($manualAvailable
        ? 'La caja puede registrar Mercado Pago manualmente. No confirma impacto con la API hasta completar QR o Point.'
        : 'La caja no deberia usar Mercado Pago todavia. Completa los puntos pendientes antes de operar.');
$mpNextSteps = [];
if (!$configExists) {
    $mpNextSteps[] = 'Guardar la configuracion para crear src/config_mp.php.';
}
if (!$tokenPresent) {
    $mpNextSteps[] = 'Pegar el Access Token de Mercado Pago.';
}
if (!$curlOk) {
    $mpNextSteps[] = 'Habilitar PHP cURL en esta instalacion.';
}
if ($automaticMode && !$qrConfigured && !$pointReady) {
    $mpNextSteps[] = 'Cargar POS externo QR o Terminal Point para usar modo automatico.';
}
if ($qrConfigured && $curlOk && !$posOk) {
    $mpNextSteps[] = 'Probar conexion para confirmar que el POS externo existe en Mercado Pago.';
}
if ($qrConfigured && !$staticReady) {
    $mpNextSteps[] = 'Generar el QR estatico para tener respaldo impreso.';
}
if (!$manualAvailable) {
    $mpNextSteps[] = 'Habilitar fallback manual si el comercio necesita vender cuando se corta internet.';
}
if (!$webhookConfigured) {
    $mpNextSteps[] = 'Configurar una URL publica HTTPS y la clave secreta para recibir Webhooks Order.';
}
if ($mpNextSteps === []) {
    $mpNextSteps[] = 'Configuracion operativa. Conviene hacer una prueba QR de $' . number_format(flus_mp_min_amount(), 2, ',', '.') . ' antes de abrir caja.';
}
$mpBadges = [
    'automatic' => mpcfg_badge($automaticReady, 'Automatico listo', 'Automatico pendiente'),
    'manual' => mpcfg_badge($manualAvailable, 'Manual disponible', 'Manual bloqueado'),
    'qr' => mpcfg_badge($qrReady, 'QR conectado', 'QR incompleto'),
    'point' => mpcfg_badge($pointReady, 'Point configurado', 'Point opcional', 'neutral'),
];

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
      <p>Cobros QR, dinero neto y configuracion de la cuenta.</p>
    </div>
    <div class="mpcfg-header-actions">
      <a class="v-btn v-btn--primary" href="mercadopago_liquidaciones.php">Ver liquidaciones</a>
      <a class="v-btn v-btn--outline" href="mp_qr_test.php">Diagnosticar QR</a>
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

  <section class="mpcfg-readiness <?= h($mpReadinessClass) ?>" aria-labelledby="mpcfgReadinessTitle">
    <div class="mpcfg-readiness-main">
      <span class="mpcfg-eyebrow">Estado operativo</span>
      <h2 id="mpcfgReadinessTitle"><?= h($mpReadinessTitle) ?></h2>
      <p><?= h($mpReadinessText) ?></p>
      <div class="mpcfg-readiness-badges" aria-label="Resumen de Mercado Pago">
        <?php foreach ($mpBadges as $badge): ?>
          <span class="mpcfg-readiness-badge <?= h($badge['class']) ?>"><?= h($badge['label']) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="mpcfg-next-steps">
      <strong>Proximo paso</strong>
      <ul>
        <?php foreach ($mpNextSteps as $step): ?>
          <li><?= h($step) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <nav class="mpcfg-workflow" aria-label="Acciones principales de Mercado Pago">
    <a href="caja.php"><span>1</span><strong>Cobrar</strong><small>Usar Mercado Pago desde Caja</small></a>
    <a href="mercadopago_liquidaciones.php"><span>2</span><strong>Conciliar</strong><small>Ver comisiones, impuestos y neto</small></a>
    <a href="#mpcfgAdvanced"><span>3</span><strong>Configurar</strong><small>Ajustes tecnicos y contingencia</small></a>
  </nav>

  <details class="mpcfg-disclosure">
    <summary>
      <span><strong>Diagnostico tecnico</strong><small>Token, POS, webhook, QR y Point</small></span>
      <b>Ver detalles</b>
    </summary>
  <section class="mpcfg-section">
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
      <div class="mpcfg-status <?= mpcfg_status_class($activeEnvironment === 'production', true) ?>">
        <span>Ambiente</span>
        <strong><?= $activeEnvironment === 'production' ? 'Produccion' : 'Prueba' ?></strong>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class($webhookConfigured, true) ?>">
          <span>Webhook Orders</span>
        <strong><?= $webhookConfigured ? 'Listo' : 'Pendiente' ?></strong>
        <small><?= $webhookSecretConfigured ? 'Firma cargada' : 'Falta firma' ?> · <?= $webhookUrl !== '' ? 'URL cargada' : 'Falta URL HTTPS' ?></small>
      </div>
      <div class="mpcfg-status <?= mpcfg_status_class((string)($integrationState['status'] ?? '') === 'ready', $integrationState !== null) ?>">
        <span>Alta QR</span>
        <strong><?= h((string)($integrationState['status'] ?? 'Sin iniciar')) ?></strong>
        <?php if (!empty($integrationState['last_error'])): ?><small><?= h((string)$integrationState['last_error']) ?></small><?php endif; ?>
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
  </details>

  <div class="mpcfg-layout">
    <?php if ((string)($integrationState['status'] ?? '') !== 'ready'): ?>
    <section class="mpcfg-section mpcfg-setup">
      <div class="mpcfg-section-head">
        <div>
          <span class="mpcfg-eyebrow">Configuracion guiada</span>
          <h2>Crear sucursal y caja QR</h2>
          <p>FLUS genera los identificadores tecnicos y guarda el QR automaticamente.</p>
        </div>
        <span class="mpcfg-step-badge">Un solo paso</span>
      </div>

      <form method="post" class="mpcfg-form mpcfg-setup-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="accion" value="create_qr_setup">

        <label class="mpcfg-field">
          <span>Ambiente</span>
          <select name="setup_environment">
            <option value="test" <?= $setupSelectedEnvironment === 'test' ? 'selected' : '' ?>>Prueba</option>
            <option value="production" <?= $setupSelectedEnvironment === 'production' ? 'selected' : '' ?>>Produccion</option>
          </select>
          <small>Las sucursales y cajas se mantienen separadas por ambiente.</small>
        </label>

        <label class="mpcfg-field mpcfg-field--wide">
          <span>Access Token del ambiente elegido</span>
          <input type="password" name="setup_access_token" autocomplete="off" placeholder="Pega el Access Token privado" required>
          <small>FLUS no deduce el ambiente desde APP_USR. No uses la Public Key.</small>
        </label>

        <label class="mpcfg-field">
          <span>User ID del ambiente</span>
          <input name="user_id" inputmode="numeric" pattern="[0-9]+" value="<?= h($setupValues['user_id']) ?>" placeholder="Automatico en produccion">
          <small>En prueba, copia el User ID de las credenciales de test. En produccion FLUS lo obtiene del Access Token. No uses el numero de aplicacion.</small>
        </label>

        <label class="mpcfg-field">
          <span>Nombre de la sucursal</span>
          <input name="store_name" value="<?= h($setupValues['store_name']) ?>" placeholder="Ej. Canaan" required>
        </label>

        <label class="mpcfg-field">
          <span>Calle</span>
          <input name="street_name" value="<?= h($setupValues['street_name']) ?>" placeholder="Ej. Buenos Aires" required>
        </label>

        <label class="mpcfg-field">
          <span>Numero</span>
          <input name="street_number" value="<?= h($setupValues['street_number']) ?>" inputmode="numeric" placeholder="5893" required>
        </label>

        <label class="mpcfg-field">
          <span>Localidad</span>
          <input name="city_name" value="<?= h($setupValues['city_name']) ?>" placeholder="Ej. Guaymallén" required>
          <small>Debe coincidir exactamente con la localidad aceptada por Mercado Pago, incluyendo tildes.</small>
        </label>

        <label class="mpcfg-field">
          <span>Provincia</span>
          <input name="state_name" value="<?= h($setupValues['state_name']) ?>" placeholder="Ej. Mendoza" required>
        </label>

        <label class="mpcfg-field">
          <span>Coordenadas de Google Maps</span>
          <input name="coordinates" value="<?= h($setupValues['coordinates']) ?>" placeholder="-32.849356, -68.714803" pattern="-?[0-9]+(?:\.[0-9]+)?\s*,\s*-?[0-9]+(?:\.[0-9]+)?" required>
          <small>Pega latitud y longitud juntas, separadas por coma.</small>
        </label>

        <label class="mpcfg-field">
          <span>Nombre de la caja</span>
          <input name="pos_name" value="<?= h($setupValues['pos_name']) ?>" placeholder="Caja 1" required>
        </label>

        <div class="mpcfg-setup-note mpcfg-field--wide">
          Mercado Pago valida localidad, provincia y coordenadas. Si la sucursal ya fue creada y fallo la caja, FLUS retomara ese mismo intento.
        </div>

        <div class="mpcfg-actions">
          <button class="v-btn v-btn--primary" type="submit">Crear y configurar QR</button>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <section class="mpcfg-section" id="mpcfgAdvanced">
      <details class="mpcfg-disclosure mpcfg-disclosure--inner">
      <summary>
        <span><strong>Ajustes avanzados</strong><small>Credencial, modo de cobro, webhook, POS y Point</small></span>
        <b>Abrir</b>
      </summary>
      <form method="post" class="mpcfg-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="accion" value="guardar_config">

        <label class="mpcfg-field">
          <span>Ambiente activo</span>
          <select name="environment">
            <option value="test" <?= $activeEnvironment === 'test' ? 'selected' : '' ?>>Prueba</option>
            <option value="production" <?= $activeEnvironment === 'production' ? 'selected' : '' ?>>Produccion</option>
          </select>
        </label>

        <label class="mpcfg-field mpcfg-field--wide">
          <span>Access Token</span>
          <input type="password" name="access_token" autocomplete="off" placeholder="<?= h(mpcfg_mask_token($values['access_token'])) ?>">
          <small>Dejalo vacio para conservar el token actual.</small>
        </label>

        <label class="mpcfg-field mpcfg-field--wide">
          <span>Clave secreta Webhook</span>
          <input type="password" name="webhook_secret" autocomplete="off" placeholder="<?= $webhookSecretConfigured ? 'Configurada. Dejala vacia para conservarla.' : 'Pega la firma secreta de Webhooks' ?>">
          <small>Mercado Pago genera esta clave al guardar la notificacion del evento Order.</small>
        </label>

        <label class="mpcfg-field mpcfg-field--wide">
          <span>URL publica HTTPS del Webhook</span>
          <input type="url" name="webhook_url" value="<?= h($webhookUrl) ?>" placeholder="https://tu-dominio.example/mercadopago_webhook.php">
          <small>Debe dirigir a este endpoint local: <code><?= h($localWebhookUrl) ?></code>. Mercado Pago no puede acceder a localhost directamente.</small>
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
      </details>
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

  <section class="mpcfg-section">
    <div class="mpcfg-section-head">
      <div>
        <h2>Actividad Webhook</h2>
        <p>Ultimos eventos Order recibidos en el ambiente <?= $activeEnvironment === 'production' ? 'productivo' : 'de prueba' ?>.</p>
      </div>
      <span class="mpcfg-step-badge"><?= count($recentWebhookEvents) ?> recientes</span>
    </div>
    <?php if ($recentWebhookEvents === []): ?>
      <p class="mpcfg-muted">Todavia no se recibieron notificaciones firmadas en este ambiente.</p>
    <?php else: ?>
      <div class="mpcfg-event-table-wrap">
        <table class="mpcfg-event-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Accion</th>
              <th>Order</th>
              <th>Resultado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentWebhookEvents as $event): ?>
              <tr>
                <td><?= h((string)($event['received_at'] ?? '-')) ?></td>
                <td><?= h((string)($event['action_name'] ?? $event['event_type'] ?? '-')) ?></td>
                <td><code><?= h((string)($event['resource_id'] ?? '-')) ?></code></td>
                <td>
                  <strong><?= h((string)($event['order_status'] ?: $event['status'] ?? '-')) ?></strong>
                  <?php if (!empty($event['error_message'])): ?><small><?= h((string)$event['error_message']) ?></small><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="mpcfg-section mpcfg-help">
    <h2>Reglas de operacion</h2>
    <ol>
      <li><strong>Automatico</strong> confirma el pago antes de registrar la venta.</li>
      <li><strong>Contingencia manual</strong> se usa solo cuando la app confirma el cobro pero esta PC no puede conectarse.</li>
      <li><strong>Liquidaciones</strong> muestra bruto, comision, retenciones, devoluciones y neto.</li>
    </ol>
  </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
