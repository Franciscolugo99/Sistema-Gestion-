<?php
// src/audit_lib.php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';

/**
 * Inserta un evento de auditoría.
 *
 * Tabla esperada: audit_log
 * - user_id (nullable)
 * - action (varchar)
 * - entity (varchar)
 * - entity_id (int nullable)
 * - meta_json (text/json nullable)  <-- si tu tabla se llama "meta" JSON, cambiá el SQL abajo.
 * - created_at (datetime/timestamp default current_timestamp)
 */
if (!function_exists('audit_log_event')) {
  function audit_log_event(PDO $pdo, ?int $userId, string $action, string $entity, ?int $entityId = null, array $meta = []): void {
    try {
      // Compatibilidad: si existe columna "meta" JSON, la usamos; si no, usamos "meta_json".
      $cols = $pdo->query("SHOW COLUMNS FROM audit_log")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $hasMeta = false;
      foreach ($cols as $c) {
        if (($c['Field'] ?? '') === 'meta') { $hasMeta = true; break; }
      }

      $metaJson = empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      if ($hasMeta) {
        $stmt = $pdo->prepare("
          INSERT INTO audit_log (user_id, action, entity, entity_id, meta)
          VALUES (:user_id, :action, :entity, :entity_id, :meta)
        ");
        $stmt->execute([
          ':user_id'   => $userId,
          ':action'    => $action,
          ':entity'    => $entity,
          ':entity_id' => $entityId,
          ':meta'      => $metaJson,
        ]);
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO audit_log (user_id, action, entity, entity_id, meta_json)
          VALUES (:user_id, :action, :entity, :entity_id, :meta_json)
        ");
        $stmt->execute([
          ':user_id'   => $userId,
          ':action'    => $action,
          ':entity'    => $entity,
          ':entity_id' => $entityId,
          ':meta_json' => $metaJson,
        ]);
      }
    } catch (Throwable $e) {
      // No romper el flujo por auditoría.
      flus_log_error('audit_log_event failed', [
        'err' => $e->getMessage(),
        'action' => $action,
        'entity' => $entity,
        'entity_id' => $entityId,
      ]);
    }
  }
}
