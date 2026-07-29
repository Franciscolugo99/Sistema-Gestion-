<?php
declare(strict_types=1);

require_once __DIR__ . '/cloud_http_lib.php';

/**
 * Validacion nube de licencias FLUS.
 *
 * Es offline-first: si FLUS_LICENSE_CLOUD_URL no esta definido, no hace nada.
 * Cuando esta activo, consulta una API remota cada cierto intervalo, valida una
 * respuesta firmada y guarda un cache local en storage/license_cloud_state.json.
 */

if (!function_exists('flus_license_cloud_state_file_path')) {
  function flus_license_cloud_state_file_path(): string {
    return FLUS_ROOT . '/storage/license_cloud_state.json';
  }
}

if (!function_exists('flus_license_cloud_installation_file_path')) {
  function flus_license_cloud_installation_file_path(): string {
    return FLUS_ROOT . '/storage/license_installation_id';
  }
}

if (!function_exists('flus_license_cloud_config')) {
  function flus_license_cloud_config(): array {
    $url = defined('FLUS_LICENSE_CLOUD_URL')
      ? (string)FLUS_LICENSE_CLOUD_URL
      : (string)(getenv('FLUS_LICENSE_CLOUD_URL') ?: '');

    return [
      'enabled' => trim($url) !== '',
      'url' => trim($url),
      'required' => defined('FLUS_LICENSE_CLOUD_REQUIRED')
        ? (bool)FLUS_LICENSE_CLOUD_REQUIRED
        : false,
      'interval_sec' => defined('FLUS_LICENSE_CLOUD_INTERVAL_SEC')
        ? max(30, (int)FLUS_LICENSE_CLOUD_INTERVAL_SEC)
        : 21600,
      'timeout_sec' => defined('FLUS_LICENSE_CLOUD_TIMEOUT_SEC')
        ? min(15, max(1, (int)FLUS_LICENSE_CLOUD_TIMEOUT_SEC))
        : 4,
      'token' => defined('FLUS_LICENSE_CLOUD_TOKEN')
        ? trim((string)FLUS_LICENSE_CLOUD_TOKEN)
        : trim((string)(getenv('FLUS_LICENSE_CLOUD_TOKEN') ?: '')),
      'offline_grace_days' => defined('FLUS_LICENSE_CLOUD_OFFLINE_GRACE_DAYS')
        ? max(0, (int)FLUS_LICENSE_CLOUD_OFFLINE_GRACE_DAYS)
        : 7,
      'enforce_offline_grace' => defined('FLUS_LICENSE_CLOUD_ENFORCE_OFFLINE_GRACE')
        ? (bool)FLUS_LICENSE_CLOUD_ENFORCE_OFFLINE_GRACE
        : true,
      'check_in_cli' => defined('FLUS_LICENSE_CLOUD_CHECK_IN_CLI')
        ? (bool)FLUS_LICENSE_CLOUD_CHECK_IN_CLI
        : false,
      'check_every_request' => defined('FLUS_LICENSE_CLOUD_CHECK_EVERY_REQUEST')
        ? (bool)FLUS_LICENSE_CLOUD_CHECK_EVERY_REQUEST
        : (bool)(getenv('FLUS_LICENSE_CLOUD_CHECK_EVERY_REQUEST') ?: false),
    ];
  }
}

if (!function_exists('flus_license_cloud_load_state')) {
  function flus_license_cloud_load_state(): array {
    $path = flus_license_cloud_state_file_path();
    if (!is_file($path)) return [];

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];

    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
  }
}

if (!function_exists('flus_license_cloud_save_state')) {
  function flus_license_cloud_save_state(array $state): void {
    $path = flus_license_cloud_state_file_path();
    $dir = dirname($path);
    if (!is_dir($dir)) return;

    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;

    $tmp = $path . '.tmp';
    @file_put_contents($tmp, $json, LOCK_EX);
    if (@rename($tmp, $path)) return;

    @unlink($path);
    if (@rename($tmp, $path)) return;

    @file_put_contents($path, $json, LOCK_EX);
    @unlink($tmp);
  }
}

if (!function_exists('flus_license_cloud_installation_id')) {
  function flus_license_cloud_installation_id(): string {
    $path = flus_license_cloud_installation_file_path();
    if (is_file($path)) {
      $existing = strtoupper(trim((string)@file_get_contents($path)));
      if (preg_match('/^[A-F0-9]{32}$/', $existing) === 1) {
        return $existing;
      }
    }

    try {
      $id = strtoupper(bin2hex(random_bytes(16)));
    } catch (Throwable $e) {
      $id = strtoupper(hash('sha256', (string)microtime(true) . '|' . (string)getmypid()));
      $id = substr($id, 0, 32);
    }

    $dir = dirname($path);
    if (is_dir($dir)) {
      @file_put_contents($path, $id, LOCK_EX);
    }

    return $id;
  }
}

if (!function_exists('flus_license_cloud_public_key_pem')) {
  function flus_license_cloud_public_key_pem(): string {
    if (defined('FLUS_LICENSE_CLOUD_PUBKEY_PEM')) {
      return (string)FLUS_LICENSE_CLOUD_PUBKEY_PEM;
    }
    if (function_exists('flus_license_public_key_pem')) {
      return flus_license_public_key_pem();
    }
    return '';
  }
}

if (!function_exists('flus_license_cloud_parse_time')) {
  function flus_license_cloud_parse_time(?string $value): int {
    $raw = trim((string)$value);
    if ($raw === '') return 0;
    $ts = strtotime($raw);
    return $ts === false ? 0 : (int)$ts;
  }
}

if (!function_exists('flus_license_cloud_validate_document')) {
  function flus_license_cloud_validate_document(array $document, string $expectedLicenseKey = '', string $expectedInstallationId = ''): array {
    $payloadB64 = (string)($document['payload_b64'] ?? '');
    $sigB64 = (string)($document['sig_b64'] ?? '');
    if ($payloadB64 === '' || $sigB64 === '') {
      return ['ok' => false, 'error' => 'BAD_CLOUD_FIELDS'];
    }

    if (!function_exists('openssl_verify')) {
      return ['ok' => false, 'error' => 'OPENSSL_MISSING'];
    }

    $payloadJson = base64_decode($payloadB64, true);
    $sigBin = base64_decode($sigB64, true);
    if ($payloadJson === false || $sigBin === false) {
      return ['ok' => false, 'error' => 'B64_DECODE_FAIL'];
    }

    $pubPem = flus_license_cloud_public_key_pem();
    if (trim($pubPem) === '') {
      return ['ok' => false, 'error' => 'PUBKEY_PEM_MISSING'];
    }

    $pubKey = openssl_pkey_get_public($pubPem);
    if ($pubKey === false) {
      return ['ok' => false, 'error' => 'PUBKEY_INVALID_PEM'];
    }

    $verify = openssl_verify($payloadJson, $sigBin, $pubKey, OPENSSL_ALGO_SHA256);
    if ($verify !== 1) {
      return ['ok' => false, 'error' => 'SIGNATURE_INVALID'];
    }

    $payload = json_decode((string)$payloadJson, true);
    if (!is_array($payload)) {
      return ['ok' => false, 'error' => 'PAYLOAD_JSON_INVALID'];
    }

    $status = strtolower(trim((string)($payload['status'] ?? '')));
    $allowed = ['active', 'suspended', 'expired', 'revoked'];
    if (!in_array($status, $allowed, true)) {
      return ['ok' => false, 'error' => 'BAD_CLOUD_STATUS'];
    }
    $payload['status'] = $status;

    $licenseKey = trim((string)($payload['license_key'] ?? ''));
    if ($expectedLicenseKey !== '' && !hash_equals($expectedLicenseKey, $licenseKey)) {
      return ['ok' => false, 'error' => 'LICENSE_KEY_MISMATCH'];
    }

    $installationId = trim((string)($payload['installation_id'] ?? ''));
    if ($expectedInstallationId !== '' && !hash_equals($expectedInstallationId, $installationId)) {
      return ['ok' => false, 'error' => 'INSTALLATION_ID_MISMATCH'];
    }

    return ['ok' => true, 'error' => null, 'payload' => $payload, 'document' => $document];
  }
}

if (!function_exists('flus_license_cloud_payload_days_left')) {
  function flus_license_cloud_payload_days_left(array $payload, int $nowTs): ?int {
    $exp = trim((string)($payload['expires_at'] ?? $payload['valid_until'] ?? ''));
    if ($exp === '') return null;

    $expTs = strtotime($exp . ' 23:59:59');
    if ($expTs === false) return null;

    return (int)floor(($expTs - $nowTs) / 86400);
  }
}

if (!function_exists('flus_license_cloud_status_label')) {
  function flus_license_cloud_status_label(string $status): string {
    return match ($status) {
      'active' => 'activa',
      'suspended' => 'suspendida',
      'expired' => 'vencida',
      'revoked' => 'revocada',
      default => $status,
    };
  }
}

if (!function_exists('flus_license_cloud_apply_payload')) {
  function flus_license_cloud_apply_payload(array $baseStatus, array $payload): array {
    $nowTs = time();
    $status = strtolower(trim((string)($payload['status'] ?? '')));
    $daysLeft = flus_license_cloud_payload_days_left($payload, $nowTs);
    $validUntil = trim((string)($payload['expires_at'] ?? $payload['valid_until'] ?? ($baseStatus['valid_until'] ?? '')));
    $plan = trim((string)($payload['plan'] ?? ($baseStatus['plan'] ?? '')));
    $message = trim((string)($payload['message'] ?? ''));

    $baseStatus['cloud_status'] = $status;
    $baseStatus['cloud_status_label'] = flus_license_cloud_status_label($status);
    $baseStatus['cloud_message'] = $message;
    $baseStatus['cloud_checked_at'] = (string)($payload['checked_at'] ?? $payload['server_time'] ?? '');
    $baseStatus['cloud_next_check_at'] = (string)($payload['next_check_at'] ?? '');

    if ($plan !== '') {
      $baseStatus['plan'] = $plan;
      $baseStatus['plan_label'] = $plan;
    }
    if ($validUntil !== '') {
      $baseStatus['valid_until'] = $validUntil;
    }
    if ($daysLeft !== null) {
      $baseStatus['days_left'] = $daysLeft;
    }

    if ($status === 'active') {
      $baseStatus['status'] = 'active';
      $baseStatus['status_label'] = 'activa';
      $baseStatus['limited'] = false;
      $baseStatus['reason'] = null;
      return $baseStatus;
    }

    if ($status === 'expired') {
      $baseStatus['status'] = 'expired';
      $baseStatus['status_label'] = 'vencida';
      $baseStatus['limited'] = true;
      $baseStatus['reason'] = 'CLOUD_EXPIRED';
      return $baseStatus;
    }

    if ($status === 'suspended') {
      $baseStatus['status'] = 'suspended';
      $baseStatus['status_label'] = 'suspendida';
      $baseStatus['limited'] = true;
      $baseStatus['reason'] = 'CLOUD_SUSPENDED';
      return $baseStatus;
    }

    if ($status === 'revoked') {
      $baseStatus['status'] = 'revoked';
      $baseStatus['status_label'] = 'revocada';
      $baseStatus['limited'] = true;
      $baseStatus['reason'] = 'CLOUD_REVOKED';
      return $baseStatus;
    }

    return $baseStatus;
  }
}

if (!function_exists('flus_license_cloud_should_attempt')) {
  function flus_license_cloud_should_attempt(array $config, array $state): bool {
    if (!$config['enabled']) return false;
    if (PHP_SAPI === 'cli' && !$config['check_in_cli']) return false;

    if (!empty($config['check_every_request']) && empty($state['last_error'])) {
      return true;
    }

    $cachedStatus = strtolower(trim((string)($state['payload']['status'] ?? '')));
    if (in_array($cachedStatus, ['suspended', 'expired', 'revoked'], true) && empty($state['last_error'])) {
      return true;
    }

    $nextTs = (int)($state['next_check_ts'] ?? 0);
    $lastSuccessTs = (int)($state['last_success_ts'] ?? 0);
    $maxNextTs = ($lastSuccessTs > 0 ? $lastSuccessTs : time()) + (int)$config['interval_sec'];
    if ($nextTs > $maxNextTs) {
      return true;
    }

    return $nextTs <= 0 || time() >= $nextTs;
  }
}

if (!function_exists('flus_license_cloud_request_payload')) {
  function flus_license_cloud_request_payload(array $status, string $licenseKey): array {
    return [
      'license_key' => $licenseKey,
      'installation_id' => flus_license_cloud_installation_id(),
      'flus_version' => defined('FLUS_VERSION') ? (string)FLUS_VERSION : (defined('APP_VERSION') ? (string)APP_VERSION : ''),
      'flus_build' => defined('FLUS_BUILD') ? (string)FLUS_BUILD : (defined('APP_BUILD') ? (string)APP_BUILD : ''),
      'local_status' => (string)($status['status'] ?? ''),
      'local_valid_until' => (string)($status['valid_until'] ?? ''),
      'sent_at' => date(DATE_ATOM),
    ];
  }
}

if (!function_exists('flus_license_cloud_http_post')) {
  function flus_license_cloud_http_post(string $url, array $payload, int $timeoutSec, string $token = ''): array {
    if (!function_exists('curl_init')) {
      return ['ok' => false, 'error' => 'CURL_MISSING'];
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
      return ['ok' => false, 'error' => 'JSON_ENCODE_FAILED'];
    }

    $ch = curl_init($url);
    $headers = [
      'Content-Type: application/json',
      'Accept: application/json',
      'User-Agent: FLUS-License-Client/1',
    ];
    if (trim($token) !== '') {
      $headers[] = 'Authorization: Bearer ' . trim($token);
      $headers[] = 'X-Flus-Cloud-Token: ' . trim($token);
    }

    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $timeoutSec,
      CURLOPT_CONNECTTIMEOUT => $timeoutSec,
      CURLOPT_HTTPHEADER => $headers,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
      $decodedError = is_string($raw) ? json_decode($raw, true) : null;
      return [
        'ok' => false,
        'error' => flus_cloud_http_contract_error(
          is_array($decodedError) ? $decodedError : null,
          $curlError !== '' ? 'HTTP_TRANSPORT_ERROR' : 'HTTP_STATUS_' . $status
        ),
      ];
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
      return ['ok' => false, 'error' => 'HTTP_JSON_INVALID'];
    }

    if (trim((string)($decoded['payload_b64'] ?? '')) === '' || trim((string)($decoded['sig_b64'] ?? '')) === '') {
      return ['ok' => false, 'error' => flus_cloud_http_contract_error($decoded)];
    }

    return ['ok' => true, 'document' => $decoded];
  }
}

if (!function_exists('flus_license_cloud_apply_status')) {
  function flus_license_cloud_apply_status(array $baseStatus, ?array $license = null): array {
    $config = flus_license_cloud_config();
    $baseStatus['cloud_enabled'] = (bool)$config['enabled'];
    if (!$config['enabled']) {
      if (!empty($config['required'])) {
        $baseStatus['status'] = 'cloud_required_missing';
        $baseStatus['status_label'] = 'validacion pendiente';
        $baseStatus['limited'] = true;
        $baseStatus['reason'] = 'CLOUD_REQUIRED_MISSING';
        $baseStatus['cloud_last_error'] = 'CLOUD_URL_MISSING';
      }
      return $baseStatus;
    }

    $licenseKey = trim((string)($license['license_key'] ?? $baseStatus['license_key'] ?? ''));
    if ($licenseKey === '') {
      $baseStatus['cloud_last_error'] = 'LICENSE_KEY_MISSING';
      return $baseStatus;
    }

    $installationId = flus_license_cloud_installation_id();
    $state = flus_license_cloud_load_state();
    if (flus_license_cloud_should_attempt($config, $state)) {
      $attemptAt = time();
      $state['last_attempt_ts'] = $attemptAt;
      $state['last_attempt_at'] = date(DATE_ATOM, $attemptAt);

      $request = flus_license_cloud_request_payload($baseStatus, $licenseKey);
      $response = flus_license_cloud_http_post(
        (string)$config['url'],
        $request,
        (int)$config['timeout_sec'],
        (string)($config['token'] ?? '')
      );
      if (!empty($response['ok']) && is_array($response['document'] ?? null)) {
        $validation = flus_license_cloud_validate_document($response['document'], $licenseKey, $installationId);
        if (!empty($validation['ok'])) {
          $payload = $validation['payload'];
          $nextTs = flus_license_cloud_parse_time((string)($payload['next_check_at'] ?? ''));
          if ($nextTs <= 0) {
            $nextTs = $attemptAt + (int)$config['interval_sec'];
          }
          $maxNextTs = $attemptAt + (int)$config['interval_sec'];
          if ($nextTs > $maxNextTs) {
            $nextTs = $maxNextTs;
          }

          $state['last_success_ts'] = $attemptAt;
          $state['last_success_at'] = date(DATE_ATOM, $attemptAt);
          $state['next_check_ts'] = $nextTs;
          $state['next_check_at'] = date(DATE_ATOM, $nextTs);
          $state['document'] = $validation['document'];
          $state['payload'] = $payload;
          unset($state['last_error']);
        } else {
          $state['last_error'] = (string)($validation['error'] ?? 'CLOUD_VALIDATION_FAILED');
          $state['next_check_ts'] = $attemptAt + min(900, (int)$config['interval_sec']);
          $state['next_check_at'] = date(DATE_ATOM, (int)$state['next_check_ts']);
        }
      } else {
        $state['last_error'] = (string)($response['error'] ?? 'CLOUD_UNREACHABLE');
        $state['next_check_ts'] = $attemptAt + min(900, (int)$config['interval_sec']);
        $state['next_check_at'] = date(DATE_ATOM, (int)$state['next_check_ts']);
      }

      flus_license_cloud_save_state($state);
    }

    $baseStatus['cloud_last_error'] = (string)($state['last_error'] ?? '');
    $baseStatus['cloud_last_attempt_at'] = (string)($state['last_attempt_at'] ?? '');
    $baseStatus['cloud_last_success_at'] = (string)($state['last_success_at'] ?? '');
    $baseStatus['cloud_next_check_at'] = (string)($state['next_check_at'] ?? '');

    $cachedPayload = null;
    if (is_array($state['document'] ?? null)) {
      $cached = flus_license_cloud_validate_document($state['document'], $licenseKey, $installationId);
      if (!empty($cached['ok']) && is_array($cached['payload'] ?? null)) {
        $cachedPayload = $cached['payload'];
      } else {
        $baseStatus['cloud_last_error'] = (string)($cached['error'] ?? 'CLOUD_CACHE_INVALID');
      }
    }

    if (is_array($cachedPayload)) {
      $baseStatus = flus_license_cloud_apply_payload($baseStatus, $cachedPayload);
    }

    $lastSuccessTs = (int)($state['last_success_ts'] ?? 0);
    if (
      $config['enforce_offline_grace']
      && $lastSuccessTs > 0
      && ((time() - $lastSuccessTs) > ((int)$config['offline_grace_days'] * 86400))
      && (string)($baseStatus['status'] ?? '') === 'active'
    ) {
      $baseStatus['status'] = 'cloud_grace_exceeded';
      $baseStatus['status_label'] = 'validacion pendiente';
      $baseStatus['limited'] = true;
      $baseStatus['reason'] = 'CLOUD_GRACE_EXCEEDED';
    }

    return $baseStatus;
  }
}
