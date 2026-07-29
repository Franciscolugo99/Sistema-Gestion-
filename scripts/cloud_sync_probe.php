<?php
declare(strict_types=1);

$root = dirname(__DIR__);
defined('FLUS_ROOT') || define('FLUS_ROOT', $root);

$configPath = $root . '/src/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "CONFIG_MISSING\n");
    exit(2);
}

require_once $configPath;
require_once $root . '/src/license_cloud.php';
require_once $root . '/src/cloud_sync_lib.php';

$statePath = $root . '/storage/cloud_sync_probe_state.json';
$licenseKey = flus_cloud_sync_license_key();
$installationId = flus_cloud_sync_installation_id();
$licenseConfig = flus_license_cloud_config();
$syncConfig = flus_cloud_sync_config();

$licenseResult = ['ok' => false, 'error' => 'LICENSE_KEY_MISSING'];
if ($licenseKey !== '' && $installationId !== '') {
    $licenseResponse = flus_license_cloud_http_post(
        (string)$licenseConfig['url'],
        flus_license_cloud_request_payload([], $licenseKey),
        (int)$licenseConfig['timeout_sec'],
        (string)$licenseConfig['token']
    );
    if (!empty($licenseResponse['ok']) && is_array($licenseResponse['document'] ?? null)) {
        $validation = flus_license_cloud_validate_document(
            $licenseResponse['document'],
            $licenseKey,
            $installationId
        );
        $licenseResult = !empty($validation['ok'])
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => (string)($validation['error'] ?? 'CLOUD_VALIDATION_FAILED')];
    } else {
        $licenseResult = [
            'ok' => false,
            'error' => (string)($licenseResponse['error'] ?? 'CLOUD_UNREACHABLE'),
        ];
    }
}

$syncResult = flus_cloud_sync_preflight($syncConfig);
$ok = !empty($licenseResult['ok']) && !empty($syncResult['ok']);
$state = [
    'ok' => $ok,
    'checked_at' => date(DATE_ATOM),
    'license' => $licenseResult,
    'sync' => $syncResult,
];

$json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (is_string($json) && is_dir(dirname($statePath))) {
    $temporary = $statePath . '.tmp';
    if (@file_put_contents($temporary, $json, LOCK_EX) !== false) {
        @rename($temporary, $statePath);
    }
}

echo json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
