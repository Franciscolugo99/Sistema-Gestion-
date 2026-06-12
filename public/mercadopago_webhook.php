<?php
declare(strict_types=1);

define('FLUS_API_CONTEXT', true);
define('FLUS_ROOT', dirname(__DIR__));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function mp_webhook_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$configPath = FLUS_ROOT . '/src/config.php';
if (!is_file($configPath)) {
    mp_webhook_response(503, ['ok' => false, 'error' => 'not_configured']);
}
require_once $configPath;
require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';
require_once FLUS_ROOT . '/src/mercadopago_integration_lib.php';

function mp_webhook_query_value(string $name): string
{
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    foreach (explode('&', $query) as $part) {
        $pair = explode('=', $part, 2);
        if (rawurldecode($pair[0] ?? '') === $name) {
            return trim(rawurldecode($pair[1] ?? ''));
        }
    }
    return '';
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    mp_webhook_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    mp_webhook_response(400, ['ok' => false, 'error' => 'invalid_json']);
}

$resourceId = mp_webhook_query_value('data.id');
if ($resourceId === '' && is_scalar($payload['data']['id'] ?? null)) {
    $resourceId = trim((string)$payload['data']['id']);
}
$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
$signature = trim((string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
$secret = flus_mp_qr_config_value('FLUS_MP_WEBHOOK_SECRET', 'FLUS_MP_WEBHOOK_SECRET');
$environment = flus_mp_environment(flus_mp_qr_config_value('FLUS_MP_ENVIRONMENT', 'FLUS_MP_ENVIRONMENT', 'test'));
$signatureValid = flus_mp_webhook_signature_valid($resourceId, $requestId, $signature, $secret);

if (!$signatureValid) {
    mp_webhook_response(401, ['ok' => false, 'error' => 'invalid_signature']);
}
if ((string)($payload['type'] ?? '') !== 'order' || $resourceId === '') {
    mp_webhook_response(200, ['ok' => true, 'ignored' => true]);
}

$eventKey = flus_mp_webhook_event_key($payload, $resourceId, $requestId);
$event = [
    'event_key' => $eventKey,
    'environment' => $environment,
    'event_id' => (string)($payload['id'] ?? ''),
    'event_type' => (string)($payload['type'] ?? ''),
    'action_name' => (string)($payload['action'] ?? ''),
    'resource_id' => $resourceId,
    'request_id' => $requestId,
    'signature_valid' => true,
    'live_mode' => $payload['live_mode'] ?? null,
    'status' => 'received',
    'payload_json' => $raw,
];

try {
    $pdo = getPDO();
    $inserted = flus_mp_webhook_record($pdo, $event);
    if (!$inserted && flus_mp_webhook_status($pdo, $eventKey) === 'processed') {
        mp_webhook_response(200, ['ok' => true, 'duplicate' => true]);
    }

    $orderResult = flus_mp_qr_get_order($resourceId);
    if (!($orderResult['ok'] ?? false)) {
        $error = (string)($orderResult['error'] ?? 'No se pudo consultar la order');
        flus_mp_webhook_mark_error($pdo, $eventKey, $error);
        mp_webhook_response(503, ['ok' => false, 'error' => 'order_lookup_failed']);
    }

    flus_mp_webhook_mark_processed($pdo, $eventKey, (array)($orderResult['response'] ?? []));
    mp_webhook_response(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('Mercado Pago webhook: ' . $e->getMessage());
    mp_webhook_response(500, ['ok' => false, 'error' => 'internal_error']);
}
