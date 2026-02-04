<?php
// src/license.php
declare(strict_types=1);

/**
 * Licencias FLUS (offline) - enforcement V2 (RSA/OpenSSL + Sodium opcional)
 *
 * Lee: storage/license.json
 * Guarda estado anti-rollback: storage/license_state.json
 *
 * Formatos soportados:
 *
 * A) SIMPLE (solo si FLUS_LICENSE_REQUIRE_SIGNATURE = false)
 * {
 *   "plan": "Mensual",
 *   "expires_at": "2026-03-31"
 * }
 *
 * B) FIRMADA RSA (recomendada, no requiere ext-sodium)
 * {
 *   "alg": "RSA-SHA256",
 *   "payload_b64": "BASE64(JSON)",
 *   "sig_b64": "BASE64(FIRMA)"
 * }
 *
 * El JSON dentro de payload_b64 debe incluir al menos:
 *   - plan
 *   - expires_at (o valid_until)
 *
 * C) FIRMADA SODIUM (si ext-sodium está disponible)
 * {
 *   "payload": "JSON",
 *   "sig": "BASE64(FIRMA)"
 * }
 * y define('FLUS_LICENSE_PUBKEY_B64','...') en src/config.php
 *
 * Para RSA:
 *   define('FLUS_LICENSE_PUBKEY_PEM', "-----BEGIN PUBLIC KEY----- ...");
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
    @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

if (!function_exists('flus_license_try_rsa_signed_payload')) {
  /**
   * Si existe payload_b64+sig_b64, verifica RSA y devuelve lic con plan/expires extraídos del payload.
   */
  function flus_license_try_rsa_signed_payload(array $lic): array {
    if (empty($lic['payload_b64']) && empty($lic['sig_b64'])) {
      return ['ok' => false, 'error' => 'RSA_NOT_PRESENT', 'license' => $lic];
    }

    $payloadB64 = (string)($lic['payload_b64'] ?? '');
    $sigB64     = (string)($lic['sig_b64'] ?? '');
    if ($payloadB64 === '' || $sigB64 === '') {
      return ['ok' => false, 'error' => 'BAD_RSA_FIELDS', 'license' => $lic];
    }

    if (!function_exists('openssl_verify')) {
      return ['ok' => false, 'error' => 'OPENSSL_MISSING', 'license' => $lic];
    }

    // 1) Decodificar payload + firma
    $payloadJson = base64_decode($payloadB64, true);
    $sigBin      = base64_decode($sigB64, true);
    if ($payloadJson === false || $sigBin === false) {
      return ['ok' => false, 'error' => 'B64_DECODE_FAIL', 'license' => $lic];
    }

    // 2) Leer clave pública desde archivo (preferido) o constante PEM
    $pubPem = '';
    if (defined('FLUS_LICENSE_PUBKEY_PATH') && is_file(FLUS_LICENSE_PUBKEY_PATH)) {
      $pubPem = (string)file_get_contents(FLUS_LICENSE_PUBKEY_PATH);
    } elseif (defined('FLUS_LICENSE_PUBKEY_PEM')) {
      $pubPem = (string)FLUS_LICENSE_PUBKEY_PEM;
    }

    if (trim($pubPem) === '') {
      return ['ok' => false, 'error' => 'PUBKEY_PEM_MISSING', 'license' => $lic];
    }

    $pubKey = openssl_pkey_get_public($pubPem);
    if ($pubKey === false) {
      return ['ok' => false, 'error' => 'PUBKEY_INVALID_PEM', 'license' => $lic];
    }

    // 3) Verificar firma
    $verify = openssl_verify($payloadJson, $sigBin, $pubKey, OPENSSL_ALGO_SHA256);
    if ($verify !== 1) {
      return ['ok' => false, 'error' => 'SIGNATURE_INVALID', 'license' => $lic];
    }

    // 4) Parsear payload y usar SOLO eso como verdad
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
      return ['ok' => false, 'error' => 'PAYLOAD_JSON_INVALID', 'license' => $lic];
    }

    $payload = flus_license_normalize($payload);

    // IMPORTANTE: los datos "reales" salen del payload firmado
    $lic['_signed'] = true;
    $lic['_alg'] = (string)($lic['alg'] ?? 'RSA-SHA256');
    $lic['plan'] = (string)($payload['plan'] ?? '');
    $lic['expires_at'] = (string)($payload['expires_at'] ?? '');
    $lic['customer'] = $payload['customer'] ?? ($lic['customer'] ?? null);
    $lic['license_key'] = $payload['license_key'] ?? ($lic['license_key'] ?? null);
    $lic['issued_at'] = $payload['issued_at'] ?? ($lic['issued_at'] ?? null);

    return ['ok' => true, 'error' => null, 'license' => $lic];
  }
}

if (!function_exists('flus_license_try_sodium_signed_payload')) {
  function flus_license_try_sodium_signed_payload(array $lic): array {
    if (empty($lic['payload']) && empty($lic['sig'])) {
      return ['ok' => false, 'error' => 'SODIUM_NOT_PRESENT', 'license' => $lic];
    }

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

    $payloadArr = json_decode($payload, true);
    if (!is_array($payloadArr)) {
      return ['ok' => false, 'error' => 'PAYLOAD_JSON_INVALID', 'license' => $lic];
    }

    $payloadArr = flus_license_normalize($payloadArr);

    $lic['_signed'] = true;
    $lic['_alg'] = 'SODIUM';
    $lic['plan'] = (string)($payloadArr['plan'] ?? '');
    $lic['expires_at'] = (string)($payloadArr['expires_at'] ?? '');
    $lic['customer'] = $payloadArr['customer'] ?? ($lic['customer'] ?? null);
    $lic['license_key'] = $payloadArr['license_key'] ?? ($lic['license_key'] ?? null);
    $lic['issued_at'] = $payloadArr['issued_at'] ?? ($lic['issued_at'] ?? null);

    return ['ok' => true, 'error' => null, 'license' => $lic];
  }
}

if (!function_exists('flus_license_validate_payload')) {
  function flus_license_validate_payload(array $lic): array {
    $lic = flus_license_normalize($lic);

    $requireSig = defined('FLUS_LICENSE_REQUIRE_SIGNATURE') ? (bool)FLUS_LICENSE_REQUIRE_SIGNATURE : false;

    // 1) Si viene RSA firmado, valida y extrae plan/exp del payload
    $rsa = flus_license_try_rsa_signed_payload($lic);
    if ($rsa['ok']) {
      $lic = $rsa['license'];
    } else {
      // 2) Si viene Sodium firmado, valida y extrae
      $sod = flus_license_try_sodium_signed_payload($lic);
      if ($sod['ok']) {
        $lic = $sod['license'];
      } else {
        // 3) No hay firma válida/presente
        if ($requireSig) {
          // Si hay intento de firma pero falló, propagamos error relevante
          $err = 'SIGNATURE_REQUIRED';
          if (($rsa['error'] ?? '') !== 'RSA_NOT_PRESENT' && ($rsa['error'] ?? '') !== 'BAD_RSA_FIELDS') $err = (string)$rsa['error'];
          if (($sod['error'] ?? '') !== 'SODIUM_NOT_PRESENT' && ($sod['error'] ?? '') !== 'BAD_SIGNATURE_FIELDS') $err = (string)$sod['error'];
          return ['ok' => false, 'error' => $err, 'license' => $lic];
        }
      }
    }

    // Ahora plan/exp deben existir (ya sea del simple o extraídos del payload)
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

    // Anti-rollback básico (tolerancia configurable)
    $state = flus_license_state_load();
    $last  = (int)($state['last_seen_ts'] ?? 0);

    $skew = defined('FLUS_LICENSE_CLOCK_SKEW_SEC') ? (int)FLUS_LICENSE_CLOCK_SKEW_SEC : 300; // 5 min
    $maxForward = defined('FLUS_LICENSE_MAX_FORWARD_SEC') ? (int)FLUS_LICENSE_MAX_FORWARD_SEC : 86400; // 24h

    $clockRollback = ($last > 0 && ($nowTs + $skew) < $last);
    $clockForwardJump = ($last > 0 && ($nowTs - $last) > $maxForward);

    // Solo actualiza el estado si el reloj parece coherente
    if (!$clockRollback && !$clockForwardJump) {
      if ($nowTs > $last) {
        $state['last_seen_ts'] = $nowTs;
        $state['last_seen_at'] = date('c', $nowTs);
        flus_license_state_save($state);
      }
    }

    $licRaw = flus_license_load();
    if (!$licRaw) {
      return [
        'status' => ($clockRollback || $clockForwardJump) ? 'clock_rollback' : 'missing',
        'status_label' => ($clockRollback || $clockForwardJump) ? 'reloj modificado' : 'sin licencia',
        'plan' => 'NONE',
        'plan_label' => 'N/D',
        'valid_until' => null,
        'days_left' => null,
        'limited' => true,
        'reason' => $clockRollback ? 'CLOCK_ROLLBACK' : ($clockForwardJump ? 'CLOCK_FORWARD_JUMP' : 'LICENSE_MISSING'),
      ];
    }

    $val = flus_license_validate_payload($licRaw);
    $lic = $val['license'];

    // ^ mantengo $nowTs para no "brickear" por un salto forward; el lock lo decide clockRollback/clockForwardJump igualmente.

    if (!$val['ok']) {
      return [
        'status' => ($clockRollback || $clockForwardJump) ? 'clock_rollback' : 'invalid',
        'status_label' => ($clockRollback || $clockForwardJump) ? 'reloj modificado' : 'inválida',
        'plan' => (string)($lic['plan'] ?? 'NONE'),
        'plan_label' => (string)($lic['plan'] ?? 'N/D'),
        'valid_until' => (string)($lic['expires_at'] ?? null),
        'days_left' => null,
        'limited' => true,
        'reason' => $clockRollback ? 'CLOCK_ROLLBACK' : ($clockForwardJump ? 'CLOCK_FORWARD_JUMP' : ('INVALID_' . (string)$val['error'])),
      ];
    }

    $plan = (string)($lic['plan'] ?? 'BASIC');
    $exp  = (string)($lic['expires_at'] ?? '');
    $expTs = strtotime($exp . ' 23:59:59');
    if ($expTs === false) $expTs = 0;

    // Para evitar “ganar días” moviendo el reloj hacia atrás, usamos como referencia el mayor entre ahora y el último instante visto.
    $effectiveNowTs = ($last > 0) ? max($nowTs, $last) : $nowTs;

    $daysLeft = (int)floor(($expTs - $effectiveNowTs) / 86400);

    if ($expTs > 0 && $effectiveNowTs > $expTs) {
      return [
        'status' => ($clockRollback || $clockForwardJump) ? 'clock_rollback' : 'expired',
        'status_label' => ($clockRollback || $clockForwardJump) ? 'reloj modificado' : 'vencida',
        'plan' => $plan,
        'plan_label' => $plan,
        'valid_until' => $exp,
        'days_left' => ($clockRollback || $clockForwardJump) ? null : $daysLeft,
        'limited' => true,
        'reason' => $clockRollback ? 'CLOCK_ROLLBACK' : ($clockForwardJump ? 'CLOCK_FORWARD_JUMP' : 'LICENSE_EXPIRED'),
      ];
    }

    return [
      'status' => ($clockRollback || $clockForwardJump) ? 'clock_rollback' : 'active',
      'status_label' => ($clockRollback || $clockForwardJump) ? 'reloj modificado' : 'activa',
      'plan' => $plan,
      'plan_label' => $plan,
      'valid_until' => $exp,
      'days_left' => ($clockRollback || $clockForwardJump) ? null : $daysLeft,
      'limited' => ($clockRollback || $clockForwardJump) ? true : false,
      'reason' => $clockRollback ? 'CLOCK_ROLLBACK' : ($clockForwardJump ? 'CLOCK_FORWARD_JUMP' : null),
    ];
  }
}
