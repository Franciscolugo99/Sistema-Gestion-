<?php
// public/lib/audit_log.php
declare(strict_types=1);

if (!function_exists('audit_request_id')) {
  function audit_request_id(): string {
    static $rid = null;
    if ($rid) return $rid;

    $rid = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    if ($rid === '') {
      try { $rid = bin2hex(random_bytes(16)); }
      catch (Throwable $e) { $rid = uniqid('rid_', true); }
    }
    return $rid;
  }
}

if (!function_exists('audit_log')) {
  /**
   * Registra un evento de auditoría. NUNCA debe romper el flujo del sistema.
   *
   * Ej:
   * audit_log('CAJA','venta_creada','ventas',$ventaId,['total'=>123,'medio_pago'=>'EFECTIVO']);
   */
  function audit_log(
    string $module,
    string $action,
    ?string $entity = null,
    ?int $entityId = null,
    array $meta = [],
    $before = null,
    $after = null
  ): void {
    try {
      if (!function_exists('getPDO')) return;
      $pdo = getPDO();

      // user id (si existe current_user())
      $userId = null;
      if (function_exists('current_user')) {
        $u = current_user();
        if (is_array($u) && isset($u['id'])) $userId = (int)$u['id'];
      }

      $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
      $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

      $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

      $beforeJson = null;
      if ($before !== null) {
        $beforeJson = is_string($before) ? $before : json_encode($before, JSON_UNESCAPED_UNICODE);
      }

      $afterJson = null;
      if ($after !== null) {
        $afterJson = is_string($after) ? $after : json_encode($after, JSON_UNESCAPED_UNICODE);
      }

      if ($ua !== '') $ua = mb_substr($ua, 0, 255);

      $st = $pdo->prepare("
        INSERT INTO audit_log
          (created_at, user_id, module, action, entity, entity_id, meta, before_json, after_json, request_id, ip, user_agent)
        VALUES
          (NOW(), :user_id, :module, :action, :entity, :entity_id, :meta, :before_json, :after_json, :request_id, :ip, :ua)
      ");

      $st->execute([
        ':user_id'     => $userId ?: null,
        ':module'      => $module,
        ':action'      => $action,
        ':entity'      => $entity,
        ':entity_id'   => ($entityId && $entityId > 0) ? $entityId : null,
        ':meta'        => $metaJson,
        ':before_json' => $beforeJson,
        ':after_json'  => $afterJson,
        ':request_id'  => audit_request_id(),
        ':ip'          => $ip !== '' ? $ip : null,
        ':ua'          => $ua !== '' ? $ua : null,
      ]);
    } catch (Throwable $e) {
      // Nunca romper el flujo
      error_log('[audit_log] ' . $e->getMessage());
    }
  }
}
