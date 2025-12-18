<?php
declare(strict_types=1);

function audit_request_id(): string {
  static $rid = null;
  if (is_string($rid) && $rid !== '') return $rid;

  $h = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
  if (is_string($h) && $h !== '') {
    $rid = substr($h, 0, 64);
    return $rid;
  }

  $rid = bin2hex(random_bytes(16)); // 32 chars
  return $rid;
}

function audit_cols(PDO $pdo): array {
  static $cols = null;
  if (is_array($cols)) return $cols;

  $cols = [];
  $st = $pdo->query("SHOW COLUMNS FROM audit_log");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cols[(string)$r['Field']] = true;
  }
  return $cols;
}

/**
 * Compat:
 *  - Nuevo: audit_log($pdo, $userId, $action, $module, $entity, $entityId, $meta, $before, $after, $requestId)
 *  - Viejo: audit_log($pdo, $userId, $action, $entity, $entityId, $meta)
 */
function audit_log(
  PDO $pdo,
  ?int $userId,
  string $action,
  string $a4,
  $a5 = null,
  $a6 = null,
  $a7 = null,
  $a8 = null,
  $a9 = null,
  $a10 = null
): void {
  // Detectar firma
  if (is_string($a5)) {
    // Firma nueva
    $module   = $a4;
    $entity   = $a5;
    $entityId = (is_int($a6) || $a6 === null) ? $a6 : (is_numeric($a6) ? (int)$a6 : null);
    $meta     = is_array($a7) ? $a7 : [];
    $before   = is_array($a8) ? $a8 : null;
    $after    = is_array($a9) ? $a9 : null;
    $rid      = is_string($a10) && $a10 !== '' ? substr($a10, 0, 64) : audit_request_id();
  } else {
    // Firma vieja
    $module   = '';
    $entity   = $a4;
    $entityId = (is_int($a5) || $a5 === null) ? $a5 : (is_numeric($a5) ? (int)$a5 : null);
    $meta     = is_array($a6) ? $a6 : [];
    $before   = null;
    $after    = null;
    $rid      = audit_request_id();
  }

  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
  if (is_string($ua) && strlen($ua) > 255) $ua = substr($ua, 0, 255);

  $cols = audit_cols($pdo);
  $hasPro = isset($cols['module'], $cols['ip'], $cols['user_agent'], $cols['request_id'], $cols['before_json'], $cols['after_json']);

  $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

  if ($hasPro) {
    $sql = "INSERT INTO audit_log
      (user_id, action, module, entity, entity_id, meta, before_json, after_json, ip, user_agent, request_id)
      VALUES
      (:uid, :action, :module, :entity, :eid, :meta, :before, :after, :ip, :ua, :rid)";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':uid'    => $userId,
      ':action' => $action,
      ':module' => $module,
      ':entity' => $entity,
      ':eid'    => $entityId,
      ':meta'   => $metaJson,
      ':before' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
      ':after'  => $after  ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null,
      ':ip'     => is_string($ip) ? substr($ip, 0, 45) : null,
      ':ua'     => $ua,
      ':rid'    => $rid,
    ]);
  } else {
    // Modo legacy (por si todavía no migraste)
    $sql = "INSERT INTO audit_log (user_id, action, entity, entity_id, meta)
            VALUES (:uid, :action, :entity, :eid, :meta)";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':uid'    => $userId,
      ':action' => $action,
      ':entity' => $entity,
      ':eid'    => $entityId,
      ':meta'   => $metaJson,
    ]);
  }
}
