<?php
declare(strict_types=1);

/**
 * Mock local para probar licencias nube.
 *
 * No usar en produccion. Solo responde si FLUS_LICENSE_CLOUD_MOCK_ENABLED=true.
 */

if (!function_exists('flus_license_cloud_mock_enabled')) {
  function flus_license_cloud_mock_enabled(): bool {
    return defined('FLUS_LICENSE_CLOUD_MOCK_ENABLED') && (bool)FLUS_LICENSE_CLOUD_MOCK_ENABLED;
  }
}

if (!function_exists('flus_license_cloud_mock_state_file_path')) {
  function flus_license_cloud_mock_state_file_path(): string {
    return FLUS_ROOT . '/storage/license_cloud_mock_state.json';
  }
}

if (!function_exists('flus_license_cloud_mock_private_key_pem')) {
  function flus_license_cloud_mock_private_key_pem(): string {
    return <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDOPUsRBBrgbwIM
c5yUYW8/6Ye4nvgDL2a4EGruEd67w/4Vb0BUjUpzvzGNDHtQ5z/cFRPi+hSJo28g
Y+P8Td/oFqYhVdd/dpCSP4rewkCvjFVzvE/SDUyXUmguAexHJIuHbkyzHFQeIL6K
a8qczJzfB/c3VdR5NQJkTDBnnQUfwDrYQTl3gxUDZBL0TAYtIrtUDTHJv3AjczKt
vqYMhZ7rmdrNWFAzCBHBV1oiBfiJDnLdni0vsLy6RrWawGmotflCf+1PZdqfUzAH
p+trMB01q4oXaQepKPVEOsvsv6sIsMj7NJJ1g/l7+e176q5lQInJ1WOGg/zpQYA2
ZPSe18W5AgMBAAECggEAB5i+YRwTmVy9aJQBmn2USXhipWxFmmhuki0YozhJSgVI
IKX+ap7htS2/t4BUMoCyG3szRlML2p4Ig9rqFOsRak0bRXMSAwHtBVYN2XKyS0US
CLdRAV24CoLgj4FGoMA52302mgpbFtkB67tYtTncNWLnukQRFX3cXrFnvwlLnQW4
p+xLiIRb1/hTGxKIY6V89Z3UHXKUZFxrvGQqndILOOKRStrVW3R+d3CzUtQ9wsFO
uUt92GYOfklNvK8d4LjDw//U26NJ4NqOy29cPXvyXRlZrxLZzBpiOAurkTGAs9Q7
5u69yE61y1rShY51m28A1COQXvCG5Z+qKKmAO5vJ+QKBgQD6QPKELUd9X17uWvis
zc2tEm/lNOcrNE3t2lqt3gRNG8mjQ1byHMXaLFv2FUcnpIylf0Gqd7TzvLFvNaLq
lHwxf5FK3yx/TjBnBb2hiXk6gNGrE63YOEjaKcGc1wfC5qFOWb8DER5hcYDcieDb
HHkVwRn3nwws8SzINBg9GfTDOwKBgQDS+Z6Dksf2M2EUHo9t7TzP/rh51awrmE/j
z8uT3JDnc/9+3ViEO9DBBvdHrcXAquprvZHWgh8cTtZi0TGWAKUMnJXjLxhSLoSZ
SWAJUJ7vUkG3GHW8ztCWtb1OzsPLkij1hdGItLqiNvKcQJ86zG0Sj5Vm+PoOhX1O
I2w5apWjmwKBgQCIG2LLOMU1DvXWtWuisJw4kVqCUu+Xs+7eem/vOF0mgwJ75VgY
fkWtj4rEoHX+vaQxLrFMQacNGYd3cLiW1QNm+HbRPeg5pRD2N00X2mkwxHdEkINw
ocwdu7At2VXDTyRUNMOKq3jWjqEDUYoWIbpJdqjk4IACwXkVuh+ku8U/mQKBgH+f
yzkyqM4RpK9EEWXhNoFoSHZDQMSaffGEuVT3/5xT+oHnKm3LtWufaCUvRMpZWjfU
1I0b5+/67QuYGtPwDegELVPiIGdOhp4n2fWolIyXiPNW05pkzZ/tztgGkkDqaOal
jeyRz7jjXn4RRYGPOogY3bsN8E6qh/Ol0Aknpd/zAoGANHOFBkEZO+PTT15FxMJL
M1O+tFIOKKILt5A4mAnb/EgzWNIeCB7iwC6uD4pXBKIhZRx3u2N481dHqEZHOVJi
OY7+2ziIATZ6TzFijnbznqjibhns4Vj1HInwLQrsJOH7XW24SZ0pY27jBWkqm2ZW
OfmjP5wOf0D/q00inz4hlUk=
-----END PRIVATE KEY-----
PEM;
  }
}

if (!function_exists('flus_license_cloud_mock_public_key_pem')) {
  function flus_license_cloud_mock_public_key_pem(): string {
    return <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzj1LEQQa4G8CDHOclGFv
P+mHuJ74Ay9muBBq7hHeu8P+FW9AVI1Kc78xjQx7UOc/3BUT4voUiaNvIGPj/E3f
6BamIVXXf3aQkj+K3sJAr4xVc7xP0g1Ml1JoLgHsRySLh25MsxxUHiC+imvKnMyc
3wf3N1XUeTUCZEwwZ50FH8A62EE5d4MVA2QS9EwGLSK7VA0xyb9wI3Myrb6mDIWe
65nazVhQMwgRwVdaIgX4iQ5y3Z4tL7C8uka1msBpqLX5Qn/tT2Xan1MwB6frazAd
NauKF2kHqSj1RDrL7L+rCLDI+zSSdYP5e/nte+quZUCJydVjhoP86UGANmT0ntfF
uQIDAQAB
-----END PUBLIC KEY-----
PEM;
  }
}

if (!function_exists('flus_license_cloud_mock_load_state')) {
  function flus_license_cloud_mock_load_state(): array {
    $path = flus_license_cloud_mock_state_file_path();
    if (is_file($path)) {
      $raw = @file_get_contents($path);
      $data = is_string($raw) ? json_decode($raw, true) : null;
      if (is_array($data)) {
        return $data;
      }
    }

    return [
      'status' => 'active',
      'plan' => 'Mensual',
      'expires_at' => '2099-12-31',
      'message' => '',
      'updated_at' => date(DATE_ATOM),
    ];
  }
}

if (!function_exists('flus_license_cloud_mock_save_state')) {
  function flus_license_cloud_mock_save_state(array $state): void {
    $allowed = ['active', 'suspended', 'expired', 'revoked'];
    $status = strtolower(trim((string)($state['status'] ?? 'active')));
    if (!in_array($status, $allowed, true)) {
      $status = 'active';
    }

    $state['status'] = $status;
    $state['plan'] = trim((string)($state['plan'] ?? 'Mensual')) ?: 'Mensual';
    $state['expires_at'] = trim((string)($state['expires_at'] ?? '2099-12-31')) ?: '2099-12-31';
    $state['message'] = trim((string)($state['message'] ?? ''));
    $state['updated_at'] = date(DATE_ATOM);

    $path = flus_license_cloud_mock_state_file_path();
    $dir = dirname($path);
    if (!is_dir($dir)) return;

    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
      @file_put_contents($path, $json, LOCK_EX);
    }

    $cloudState = FLUS_ROOT . '/storage/license_cloud_state.json';
    if (is_file($cloudState)) {
      @unlink($cloudState);
    }
  }
}

if (!function_exists('flus_license_cloud_mock_signed_document')) {
  function flus_license_cloud_mock_signed_document(array $request): array {
    $state = flus_license_cloud_mock_load_state();
    $licenseKey = trim((string)($request['license_key'] ?? ''));
    if ($licenseKey === '') {
      $licenseKey = 'FLUS-LOCAL-MOCK';
    }

    $payload = [
      'license_key' => $licenseKey,
      'installation_id' => trim((string)($request['installation_id'] ?? '')),
      'status' => (string)($state['status'] ?? 'active'),
      'plan' => (string)($state['plan'] ?? 'Mensual'),
      'expires_at' => (string)($state['expires_at'] ?? '2099-12-31'),
      'checked_at' => date(DATE_ATOM),
      'next_check_at' => date(DATE_ATOM, time() + 300),
      'message' => (string)($state['message'] ?? ''),
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
      throw new RuntimeException('No se pudo serializar payload mock.');
    }

    $signature = '';
    $private = openssl_pkey_get_private(flus_license_cloud_mock_private_key_pem());
    if ($private === false || !openssl_sign($payloadJson, $signature, $private, OPENSSL_ALGO_SHA256)) {
      throw new RuntimeException('No se pudo firmar payload mock.');
    }

    return [
      'format' => 'FLUS-CLOUD-LICENSE-1',
      'alg' => 'RSA-SHA256',
      'payload_b64' => base64_encode($payloadJson),
      'sig_b64' => base64_encode($signature),
    ];
  }
}
