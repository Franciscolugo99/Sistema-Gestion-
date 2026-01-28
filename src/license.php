<?php
// src/license.php
declare(strict_types=1);

/**
 * Licencias FLUS (offline) - enforcement V1
 *
 * Lee: storage/license.json
 * Guarda estado anti-rollback: storage/license_state.json
 *
 * license.json (mínimo):
 *  - plan: "BASIC|PRO|FULL" (o tu nomenclatura)
 *  - expires_at: "YYYY-MM-DD" (también acepta valid_until)
 *
 * Firma (opcional):
 *  - payload: string
 *  - sig: base64
 *  - define('FLUS_LICENSE_PUBKEY_B64', '...') en src/config.php
 */

if (!function_exists('flus_license_file_path')) {
  function flus_license_file_path(): string {
    return FLUS_ROOT . '/storage/license.json';
  }
}

if (!function_exists('flus_license_state_file_path')) {
  function flus_license_state_file_path(): string {
    return FLUS_ROOT . '/storage/license_state.json';
  }
}

if (!function_exists('flus_license_load')) {
  function flus_license_load(): ?array {
    $path = flus_license_file_path();
    if (!is_file($path)) return null;

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;

    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : null;
  }
}

if (!function_exists('flus_license_state_load')) {
  function flus_license_state_load(): array {
    $path = flus_license_state_file_path();
    if (!is_file($path)) return [];

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];

    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
  }
}

if (!function_exists('flus_license_state_save')) {
  function flus_license_state_save(array $state): void {
    $path = flus_license_state_file_path();
    @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  }
}

if (!function_exists('flus_license_normalize')) {
  function flus_license_normalize(array $lic): array {
    // Normaliza expiración
    if (empty($lic['expires_at']) && !empty($lic['valid_until'])) {
      $lic['expires_at'] = $lic['valid_until'];
    }
    // Normaliza plan
    if (empty($lic['plan']) && !empty($lic['tier'])) {
      $lic['plan'] = $lic['tier'];
    }
    return $lic;
  }
}

if (!function_exists('flus_license_validate_payload')) {
  function flus_license_validate_payload(array $lic): array {
    $lic = flus_license_normalize($lic);

    $plan = (string)($lic['plan'] ?? '');
    $exp  = (string)($lic['expires_at'] ?? '');

    if ($plan === '') {
      return ['ok' => false, 'error' => 'MISSING_PLAN', 'license' => $lic];
    }
    if ($exp === '') {
      return ['ok' => false, 'error' => 'MISSING_EXPIRES', 'license' => $lic];
    }

    // Valida YYYY-MM-DD
    $d = \DateTime::createFromFormat('Y-m-d', $exp);
    if (!$d || $d->format('Y-m-d') !== $exp) {
      return ['ok' => false, 'error' => 'BAD_DATE', 'license' => $lic];
    }

    // Firma opcional (si viene payload+sig)
    if (!empty($lic['payload']) || !empty($lic['sig'])) {
      $payload = (string)($lic['payload'] ?? '');
      $sigB64  = (string)($lic['sig'] ?? '');

      if ($payload === '' || $sigB64 === '') {
        return ['ok' => false, 'error' => 'BAD_SIGNATURE_FIELDS', 'license' => $lic];
      }

      if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return ['ok' => false, 'error' => 'SODIUM_MISSING', 'license' => $lic];
      }

      $pubB64 = defined('FLUS_LICENSE_PUBKEY_B64') ? (string)FLUS_LICENSE_PUBKEY_B64 : '';
      if ($pubB64 === '') {
        return ['ok' => false, 'error' => 'PUBKEY_MISSING', 'license' => $lic];
      }

      $sig = base64_decode($sigB64, true);
      $pub = base64_decode($pubB64, true);
      if ($sig === false || $pub === false) {
        return ['ok' => false, 'error' => 'B64_DECODE_FAIL', 'license' => $lic];
      }

      $ok = @sodium_crypto_sign_verify_detached($sig, $payload, $pub);
      if (!$ok) {
        return ['ok' => false, 'error' => 'SIGNATURE_INVALID', 'license' => $lic];
      }
    }

    return ['ok' => true, 'error' => null, 'license' => $lic];
  }
}

if (!function_exists('flus_is_api_context')) {
  function flus_is_api_context(): bool {
    if (defined('FLUS_API_CONTEXT') && FLUS_API_CONTEXT) return true;
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $acc = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($uri, '/api/') || str_contains($acc, 'application/json');
  }
}

if (!function_exists('flus_license_status')) {
  function flus_license_status(): array {
    // Bypass opcional (para dev/soporte)
    if (defined('FLUS_LICENSE_BYPASS') && FLUS_LICENSE_BYPASS) {
      return [
        'status' => 'bypass',
        'status_label' => 'bypass',
        'plan' => 'BYPASS',
        'plan_label' => 'BYPASS',
        'valid_until' => null,
        'days_left' => null,
        'limited' => false,
        'reason' => null,
      ];
    }

    $nowTs = time();

    // Anti-rollback básico (tolerancia 6h)
    $state = flus_license_state_load();
    $last  = (int)($state['last_seen_ts'] ?? 0);
    $skew  = 6 * 3600;
    $clockRollback = ($last > 0 && ($nowTs + $skew) < $last);

    if (!$clockRollback) {
      if ($nowTs > $last) {
        $state['last_seen_ts'] = $nowTs;
        $state['last_seen_at'] = date('c', $nowTs);
        flus_license_state_save($state);
      }
    }

    $licRaw = flus_license_load();
    if (!$licRaw) {
      return [
        'status' => $clockRollback ? 'clock_rollback' : 'missing',
        'status_label' => $clockRollback ? 'reloj modificado' : 'sin licencia',
        'plan' => 'NONE',
        'plan_label' => 'N/D',
        'valid_until' => null,
        'days_left' => null,
        'limited' => true,
        'reason' => $clockRollback ? 'CLOCK_ROLLBACK' : 'LICENSE_MISSING',
      ];
    }

    $val = flus_license_validate_payload($licRaw);
    if (!$val['ok']) {
      return [
        'status' => $clockRollback ? 'clock_rollback' : 'invalid',
        'status_label' => $clockRollback ? 'reloj modificado' : 'inválida',
        'plan' => (string)($licRaw['plan'] ?? 'NONE'),
        'plan_label' => (string)($licRaw['plan'] ?? 'N/D'),
        'valid_until' => (string)($licRaw['expires_at'] ?? $licRaw['valid_until'] ?? null),
        'days_left' => null,
        'limited' => true,
        'reason' => $clockRollback ? 'CLOCK_ROLLBACK' : ('INVALID_' . (string)$val['error']),
      ];
    }

    $lic = $val['license'];
    $plan = (string)($lic['plan'] ?? 'BASIC');
    $exp  = (string)($lic['expires_at'] ?? '');

    $hoy   = new \DateTimeImmutable('today');
    $vence = new \DateTimeImmutable($exp);
    $diff  = (int)$hoy->diff($vence)->format('%r%a');

    if ($clockRollback) {
      return [
        'status' => 'clock_rollback',
        'status_label' => 'reloj modificado',
        'plan' => $plan,
        'plan_label' => $plan,
        'valid_until' => $exp,
        'days_left' => $diff,
        'limited' => true,
        'reason' => 'CLOCK_ROLLBACK',
      ];
    }

    if ($diff < 0) {
      return [
        'status' => 'expired',
        'status_label' => 'vencida',
        'plan' => $plan,
        'plan_label' => $plan,
        'valid_until' => $exp,
        'days_left' => $diff,
        'limited' => true,
        'reason' => 'EXPIRED',
      ];
    }

    if ($diff <= 7) {
      return [
        'status' => 'expiring',
        'status_label' => 'por vencer',
        'plan' => $plan,
        'plan_label' => $plan,
        'valid_until' => $exp,
        'days_left' => $diff,
        'limited' => false,
        'reason' => null,
      ];
    }

    return [
      'status' => 'active',
      'status_label' => 'activa',
      'plan' => $plan,
      'plan_label' => $plan,
      'valid_until' => $exp,
      'days_left' => $diff,
      'limited' => false,
      'reason' => null,
    ];
  }
}

if (!function_exists('flus_feature_allowed')) {
  function flus_feature_allowed(string $feature): bool {
    // V1: modo limitado bloquea TODO lo que se guarde como feature protegida.
    // Por ahora solo protegemos exports/reportes desde los endpoints.
    if (defined('FLUS_LIMITED') && FLUS_LIMITED) return false;
    return true;
  }
}

if (!function_exists('flus_require_feature')) {
  function flus_require_feature(string $feature): void {
    if (flus_feature_allowed($feature)) return;

    // API => JSON
    if (flus_is_api_context()) {
      if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
      }
      if (ob_get_length()) { @ob_clean(); }
      http_response_code(403);
      echo json_encode([
        'ok' => false,
        'error' => 'LICENSE_LIMITED',
        'feature' => $feature,
        'license' => defined('FLUS_LICENSE') ? FLUS_LICENSE : null,
      ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
      exit;
    }

    // WEB => HTML
    if (!headers_sent()) {
      header('Content-Type: text/html; charset=utf-8');
      header('Cache-Control: no-store');
    }
    http_response_code(403);
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Licencia requerida</title>';
    echo '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:32px;max-width:760px}';
    echo '.card{border:1px solid #ddd;border-radius:12px;padding:18px}';
    echo 'a{color:#2563eb}</style></head><body>';
    echo '<h1>Función no disponible</h1>';
    echo '<div class="card">';
    echo '<p>Esta función requiere una licencia activa.</p>';
    echo '<p>Estado: <b>' . htmlspecialchars((string)(defined('FLUS_LICENSE') ? (FLUS_LICENSE['status_label'] ?? 'N/D') : 'N/D'), ENT_QUOTES, 'UTF-8') . '</b></p>';
    echo '<p>Si sos admin: abrí <a href="licencia.php">Licencia</a> para cargar/renovar.</p>';
    echo '</div></body></html>';
    exit;
  }
}
