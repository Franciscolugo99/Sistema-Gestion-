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

$flusLicensePublicKeyFile = __DIR__ . '/license_public_key.php';
if (is_file($flusLicensePublicKeyFile)) {
  require_once $flusLicensePublicKeyFile;
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
    // Escritura atómica (evita estados corruptos si el proceso se corta a mitad de escritura)
    $tmp = $path . '.tmp';
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;

    @file_put_contents($tmp, $json, LOCK_EX);
    if (@rename($tmp, $path)) {
      return;
    }

    // Windows puede fallar si el destino existe
    @unlink($path);
    if (@rename($tmp, $path)) {
      return;
    }

    // Fallback final (no atómico, pero evita perder estado)
    @file_put_contents($path, $json, LOCK_EX);
    @unlink($tmp);
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

if (!function_exists('flus_license_human_error')) {
  function flus_license_human_error(?string $code): string {
    $code = strtoupper(trim((string)$code));

    $map = [
      'SIGNATURE_REQUIRED' => 'La licencia debe venir firmada para poder instalarse en este sistema.',
      'RSA_NOT_PRESENT' => 'Faltan los datos de firma RSA esperados.',
      'BAD_RSA_FIELDS' => 'La licencia firmada por RSA llegó incompleta.',
      'OPENSSL_MISSING' => 'El servidor no tiene OpenSSL disponible para verificar la firma.',
      'PUBKEY_PEM_MISSING' => 'No se encontró la clave pública configurada para validar licencias.',
      'PUBKEY_INVALID_PEM' => 'La clave pública configurada para licencias no es válida.',
      'PUBKEY_MISSING' => 'No se encontró la clave pública configurada para validar licencias.',
      'SIGNATURE_INVALID' => 'La firma digital de la licencia no es válida.',
      'PAYLOAD_JSON_INVALID' => 'El contenido firmado de la licencia no tiene un JSON válido.',
      'SODIUM_MISSING' => 'El servidor no tiene Sodium disponible para verificar esta firma.',
      'B64_DECODE_FAIL' => 'No se pudo decodificar el contenido firmado de la licencia.',
      'MISSING_PLAN' => 'La licencia no incluye el plan.',
      'MISSING_EXPIRES' => 'La licencia no incluye fecha de vencimiento.',
      'BAD_DATE' => 'La fecha de vencimiento debe tener formato YYYY-MM-DD.',
      'JSON_ENCODE_FAILED' => 'No se pudo serializar la licencia para guardarla.',
      'WRITE_TMP_FAILED' => 'No se pudo escribir el archivo temporal de licencia.',
      'WRITE_FAILED' => 'No se pudo guardar el archivo de licencia.',
      'DIR_MISSING' => 'La carpeta de storage no existe o no está disponible.',
    ];

    return $map[$code] ?? ($code !== '' ? $code : 'Error desconocido de licencia.');
  }
}

if (!function_exists('flus_license_reason_label')) {
  function flus_license_reason_label(?string $reason): string {
    $reason = strtoupper(trim((string)$reason));

    if ($reason === '') {
      return 'Sin observaciones';
    }

    if (str_starts_with($reason, 'INVALID_')) {
      return 'Inválida: ' . flus_license_human_error(substr($reason, 8));
    }

    $map = [
      'LICENSE_MISSING' => 'No hay una licencia cargada.',
      'LICENSE_EXPIRED' => 'La licencia está vencida.',
      'TRIAL_EXPIRED' => 'Se agotó el período de prueba.',
      'GRACE_EXCEEDED' => 'Se superó el período de gracia posterior al vencimiento.',
      'CLOCK_ROLLBACK' => 'Se detectó que el reloj del sistema fue atrasado.',
      'CLOCK_FORWARD_JUMP' => 'Se detectó un salto grande hacia adelante en el reloj del sistema.',
      'BYPASS' => 'Modo bypass activo.',
      'ACTIVE' => 'Licencia operativa.',
      'MISSING' => 'No hay una licencia cargada.',
      'INVALID' => 'La licencia cargada no es válida.',
      'EXPIRED' => 'La licencia está vencida.',
    ];

    return $map[$reason] ?? $reason;
  }
}

if (!function_exists('flus_license_clock_warning_label')) {
  function flus_license_clock_warning_label(?string $warning): string {
    $warning = strtoupper(trim((string)$warning));

    $map = [
      'CLOCK_ROLLBACK' => 'Se detectó que el reloj del sistema fue atrasado. FLUS mantiene el tiempo efectivo para evitar ganar días de licencia.',
      'CLOCK_FORWARD_JUMP' => 'Se detectó un salto grande hacia adelante en el reloj del sistema. FLUS limita ese avance para no consumir la licencia de golpe.',
    ];

    return $map[$warning] ?? '';
  }
}

if (!function_exists('flus_license_meta')) {
  function flus_license_meta(?array $license = null): array {
    $license = is_array($license) ? $license : null;

    if (!$license && defined('FLUS_LICENSE') && is_array(FLUS_LICENSE)) {
      $license = FLUS_LICENSE;
    }

    if (!$license && function_exists('flus_license_status')) {
      $license = flus_license_status();
    }

    if (!$license) {
      $license = flus_license_load() ?? [];
    }

    $plan = trim((string)($license['plan_label'] ?? $license['plan'] ?? 'N/D'));
    $status = trim((string)($license['status_label'] ?? $license['status'] ?? 'N/D'));
    $validUntil = trim((string)($license['valid_until'] ?? $license['expires_at'] ?? ''));
    $daysLeftRaw = $license['days_left'] ?? null;
    $daysLeft = $daysLeftRaw === null || $daysLeftRaw === '' ? 'N/D' : (string)$daysLeftRaw;
    $reason = trim((string)($license['reason'] ?? ''));
    $clockWarning = trim((string)($license['clock_warning'] ?? ''));
    $isSigned = !empty($license['_signed']) || !empty($license['sig']) || !empty($license['sig_b64']);
    $algorithm = trim((string)($license['_alg'] ?? $license['alg'] ?? ($isSigned ? 'Firmada' : 'Simple')));
    $statusKey = strtolower(trim((string)($license['status'] ?? '')));

    if ($statusKey === '') {
      $statusKey = match (strtolower($status)) {
        'activa', 'active' => 'active',
        'vencida', 'expired' => 'expired',
        'inválida', 'invalida', 'invalid' => 'invalid',
        'sin licencia', 'missing' => 'missing',
        'bypass' => 'bypass',
        default => 'unknown',
      };
    }

    $tone = match ($statusKey) {
      'active', 'bypass' => 'success',
      'expired' => 'warning',
      'missing', 'invalid' => 'danger',
      default => 'muted',
    };

    return [
      'status' => $status !== '' ? $status : 'N/D',
      'status_key' => $statusKey,
      'status_tone' => $tone,
      'plan' => $plan !== '' ? $plan : 'N/D',
      'valid_until' => $validUntil !== '' ? $validUntil : 'N/D',
      'days_left' => $daysLeft,
      'limited' => (bool)($license['limited'] ?? false),
      'reason' => $reason,
      'reason_label' => flus_license_reason_label($reason),
      'clock_warning' => $clockWarning,
      'clock_warning_label' => flus_license_clock_warning_label($clockWarning),
      'customer' => trim((string)($license['customer'] ?? '')),
      'license_key' => trim((string)($license['license_key'] ?? '')),
      'issued_at' => trim((string)($license['issued_at'] ?? '')),
      'is_signed' => $isSigned,
      'algorithm' => $algorithm !== '' ? $algorithm : 'Simple',
    ];
  }
}

if (!function_exists('flus_license_save')) {
  function flus_license_save(array $license): array {
    $path = flus_license_file_path();
    $dir = dirname($path);

    if (!is_dir($dir)) {
      return ['ok' => false, 'error' => 'DIR_MISSING'];
    }

    $json = json_encode($license, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
      return ['ok' => false, 'error' => 'JSON_ENCODE_FAILED'];
    }

    $backupPath = null;
    if (is_file($path)) {
      $backupPath = $dir . '/license.json.bak_' . date('Ymd_His');
      @copy($path, $backupPath);
    }

    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
      @unlink($tmp);
      return ['ok' => false, 'error' => 'WRITE_TMP_FAILED'];
    }

    if (@rename($tmp, $path)) {
      return ['ok' => true, 'backup' => $backupPath];
    }

    @unlink($path);
    if (@rename($tmp, $path)) {
      return ['ok' => true, 'backup' => $backupPath];
    }

    $saved = @file_put_contents($path, $json, LOCK_EX);
    @unlink($tmp);

    return [
      'ok' => $saved !== false,
      'error' => $saved === false ? 'WRITE_FAILED' : null,
      'backup' => $backupPath,
    ];
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
    } elseif (function_exists('flus_license_public_key_pem')) {
      $pubPem = flus_license_public_key_pem();
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
        if (!empty($lic['payload_b64']) || !empty($lic['sig_b64']) || !empty($lic['payload']) || !empty($lic['sig'])) {
          $err = 'SIGNATURE_INVALID';
          if (($rsa['error'] ?? '') !== 'RSA_NOT_PRESENT' && ($rsa['error'] ?? '') !== 'BAD_RSA_FIELDS') $err = (string)$rsa['error'];
          if (($sod['error'] ?? '') !== 'SODIUM_NOT_PRESENT' && ($sod['error'] ?? '') !== 'BAD_SIGNATURE_FIELDS') $err = (string)$sod['error'];
          return ['ok' => false, 'error' => $err, 'license' => $lic];
        }

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
        'clock_warning' => null,
      ];
    }

    $nowTs = time();

    // Anti-rollback básico (tolerancia configurable)
    $state = flus_license_state_load();
    $last  = (int)($state['last_seen_ts'] ?? 0);

    $skew = defined('FLUS_LICENSE_CLOCK_SKEW_SEC') ? (int)FLUS_LICENSE_CLOCK_SKEW_SEC : 300; // 5 min
    $maxForward = defined('FLUS_LICENSE_MAX_FORWARD_SEC') ? (int)FLUS_LICENSE_MAX_FORWARD_SEC : 86400; // 24h

    // --- Reloj: WARNING (no bloqueo) + tiempo efectivo (anti-trampa sin downtime) ---
    $clockWarning   = null;
    $effectiveNowTs = $nowTs;

    if ($last > 0) {
      if (($nowTs + $skew) < $last) {
        $clockWarning   = 'CLOCK_ROLLBACK';
        $effectiveNowTs = $last; // no “ganar días” atrasando reloj
      } elseif (($nowTs - $last) > $maxForward) {
        $clockWarning   = 'CLOCK_FORWARD_JUMP';
        $effectiveNowTs = $last + $maxForward; // clamp para no “quemar” licencias
      }
    }

    // --- Persistencia del estado (sin pegajosidad) ---
    $changed = false;

    // 1) actualizar last_seen (coherente) o clamp forward-jump
    if ($last === 0) {
      // primer arranque: inicializa
      $state['last_seen_ts'] = $nowTs;
      $state['last_seen_at'] = date('c', $nowTs);
      $changed = true;
    } else {
      if ($clockWarning === null) {
        if ($nowTs > $last) {
          $state['last_seen_ts'] = $nowTs;
          $state['last_seen_at'] = date('c', $nowTs);
          $changed = true;
        }
      } elseif ($clockWarning === 'CLOCK_FORWARD_JUMP') {
        if ($effectiveNowTs > $last) {
          $state['last_seen_ts'] = $effectiveNowTs;
          $state['last_seen_at'] = date('c', $effectiveNowTs);
          $changed = true;
        }
      }
      // CLOCK_ROLLBACK: NO movemos last_seen_ts
    }

    // 2) guardar warning para diagnóstico / auto-limpiar
    $prevWarn = (string)($state['clock_warning'] ?? '');
    if ($clockWarning === null) {
      if (isset($state['clock_warning']) || isset($state['clock_warning_at']) || isset($state['clock_warning_ts'])) {
        unset($state['clock_warning'], $state['clock_warning_at'], $state['clock_warning_ts']);
        $changed = true;
      }
    } else {
      if ($prevWarn !== $clockWarning) {
        $state['clock_warning'] = $clockWarning;
        $state['clock_warning_ts'] = $nowTs;
        $state['clock_warning_at'] = date('c', $nowTs);
        $changed = true;
      }
    }

    if ($changed) {
      flus_license_state_save($state);
    }

    // --- Licencia ---
    $licRaw = flus_license_load();
    if (!$licRaw) {
      return [
        'status' => 'missing',
        'status_label' => 'sin licencia',
        'plan' => 'NONE',
        'plan_label' => 'N/D',
        'valid_until' => null,
        'days_left' => null,
        'limited' => true,
        'reason' => 'LICENSE_MISSING',
        'clock_warning' => $clockWarning,
      ];
    }

    $val = flus_license_validate_payload($licRaw);
    $lic = $val['license'];

    if (!$val['ok']) {
      return [
        'status' => 'invalid',
        'status_label' => 'inválida',
        'plan' => (string)($lic['plan'] ?? 'NONE'),
        'plan_label' => (string)($lic['plan'] ?? 'N/D'),
        'valid_until' => (string)($lic['expires_at'] ?? null),
        'days_left' => null,
        'limited' => true,
        'reason' => ('INVALID_' . (string)$val['error']),
        'clock_warning' => $clockWarning,
      ];
    }

    $plan  = (string)($lic['plan'] ?? 'BASIC');
    $exp   = (string)($lic['expires_at'] ?? '');
    $expTs = strtotime($exp . ' 23:59:59');
    if ($expTs === false) $expTs = 0;

    $daysLeft = (int)floor(($expTs - $effectiveNowTs) / 86400);
    $licenseMeta = [
      'customer' => (string)($lic['customer'] ?? ''),
      'license_key' => (string)($lic['license_key'] ?? ''),
      'issued_at' => (string)($lic['issued_at'] ?? ''),
      '_signed' => (bool)($lic['_signed'] ?? false),
      '_alg' => (string)($lic['_alg'] ?? ($lic['alg'] ?? '')),
    ];

    if ($expTs > 0 && $effectiveNowTs > $expTs) {
      return array_merge([
        'status' => 'expired',
        'status_label' => 'vencida',
        'plan' => $plan,
        'plan_label' => $plan,
        'valid_until' => $exp,
        'days_left' => $daysLeft,
        'limited' => true,
        'reason' => 'LICENSE_EXPIRED',
        'clock_warning' => $clockWarning,
      ], $licenseMeta);
    }

    return array_merge([
      'status' => 'active',
      'status_label' => 'activa',
      'plan' => $plan,
      'plan_label' => $plan,
      'valid_until' => $exp,
      'days_left' => $daysLeft,
      'limited' => false,
      'reason' => null,
      'clock_warning' => $clockWarning,
    ], $licenseMeta);
  }
}
