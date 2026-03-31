<?php
// public/lib/terminal.php
declare(strict_types=1);

/**
 * IMPORTANTE
 * - Este archivo NO debe incluir auth.php ni bootstrap.php (evita bucles).
 * - Este archivo SOLO define funciones terminal_*.
 * - NO definir acá current_terminal_id() ni require_terminal_lock_json().
 */

defined('FLUS_TERMINAL_COOKIE') || define('FLUS_TERMINAL_COOKIE', 'flus_terminal_id');
defined('FLUS_TERMINAL_COOKIE_DAYS') || define('FLUS_TERMINAL_COOKIE_DAYS', 30);

$terminalSchemaHelpers = dirname(__DIR__, 2) . '/src/db_schema.php';
if (is_file($terminalSchemaHelpers)) {
  require_once $terminalSchemaHelpers;
}

/* =========================================================
   Helpers internos (schema detection + cache)
========================================================= */
if (!function_exists('terminal__table_exists')) {
  function terminal__table_exists(PDO $pdo, string $table): bool {
    try {
      if (function_exists('flus_table_exists')) {
        return (bool)flus_table_exists($pdo, $table);
      }
      if (function_exists('has_table')) {
        return (bool)has_table($pdo, $table);
      }
      return false;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('terminal__columns')) {
  function terminal__columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    if (!terminal__table_exists($pdo, $table)) {
      $cache[$table] = [];
      return [];
    }

    try {
      $cols = function_exists('flus_table_columns')
        ? array_map('strval', flus_table_columns($pdo, $table) ?: [])
        : [];
      $cache[$table] = $cols;
      return $cols;
    } catch (Throwable $e) {
      $cache[$table] = [];
      return [];
    }
  }
}

if (!function_exists('terminal__first_col')) {
  function terminal__first_col(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) {
      if (in_array($c, $cols, true)) return (string)$c;
    }
    return null;
  }
}

if (!function_exists('terminal__schema_terminales')) {
  function terminal__schema_terminales(PDO $pdo): array {
    static $schema = null;
    if (is_array($schema)) return $schema;

    $cols = terminal__columns($pdo, 'terminales');

    $schema = [
      'id'     => terminal__first_col($cols, ['id', 'terminal_id']),
      'nombre' => terminal__first_col($cols, ['nombre', 'name', 'descripcion', 'label']),
      'activo' => terminal__first_col($cols, ['activo', 'is_active', 'habilitado']),
    ];

    if (!$schema['id'])     $schema['id'] = 'id';
    if (!$schema['nombre']) $schema['nombre'] = 'nombre';
    if (!$schema['activo']) $schema['activo'] = 'activo';

    return $schema;
  }
}

if (!function_exists('terminal__schema_locks')) {
  function terminal__schema_locks(PDO $pdo): array {
    static $schema = null;
    if (is_array($schema)) return $schema;

    $cols = terminal__columns($pdo, 'terminal_locks');

    $schema = [
      'terminal_id' => terminal__first_col($cols, ['terminal_id', 'terminal', 'caja_id']),
      'user_id'     => terminal__first_col($cols, ['user_id', 'usuario_id', 'uid']),
      'session_id'  => terminal__first_col($cols, ['session_id', 'sid', 'session']),
      'expires_at'  => terminal__first_col($cols, ['expires_at', 'locked_until', 'expira_en', 'hasta']),
      'updated_at'  => terminal__first_col($cols, ['updated_at', 'last_seen', 'heartbeat_at', 'touched_at']),
      'created_at'  => terminal__first_col($cols, ['created_at', 'locked_at', 'created']),
    ];

    // mínimos requeridos
    foreach (['terminal_id','user_id','session_id','expires_at'] as $k) {
      if (!$schema[$k]) {
        throw new RuntimeException("terminal_locks: falta columna requerida '{$k}' (revisá schema)");
      }
    }

    return $schema;
  }
}

/* =========================================================
   API pública: terminales
========================================================= */
if (!function_exists('terminal_list')) {
  function terminal_list(PDO $pdo): array {
    if (!terminal__table_exists($pdo, 'terminales')) return [];

    $s = terminal__schema_terminales($pdo);
    $sql = "
      SELECT
        `{$s['id']}`     AS id,
        `{$s['nombre']}` AS nombre,
        `{$s['activo']}` AS activo
      FROM terminales
      ORDER BY `{$s['nombre']}` ASC
    ";
    try {
      $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as &$r) {
        $r['id'] = (int)($r['id'] ?? 0);
        $r['activo'] = (int)($r['activo'] ?? 0);
        $r['nombre'] = (string)($r['nombre'] ?? ('Caja #' . $r['id']));
      }
      unset($r);
      return $rows;
    } catch (Throwable $e) {
      error_log('terminal_list: ' . $e->getMessage());
      return [];
    }
  }
}

if (!function_exists('terminal_get')) {
  function terminal_get(PDO $pdo, int $terminalId): ?array {
    if ($terminalId <= 0) return null;
    if (!terminal__table_exists($pdo, 'terminales')) return null;

    $s = terminal__schema_terminales($pdo);
    $sql = "
      SELECT
        `{$s['id']}`     AS id,
        `{$s['nombre']}` AS nombre,
        `{$s['activo']}` AS activo
      FROM terminales
      WHERE `{$s['id']}` = :id
      LIMIT 1
    ";
    try {
      $st = $pdo->prepare($sql);
      $st->execute([':id' => $terminalId]);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      if (!$r) return null;
      $r['id'] = (int)($r['id'] ?? 0);
      $r['activo'] = (int)($r['activo'] ?? 0);
      $r['nombre'] = (string)($r['nombre'] ?? ('Caja #' . $r['id']));
      return $r;
    } catch (Throwable $e) {
      error_log('terminal_get: ' . $e->getMessage());
      return null;
    }
  }
}

if (!function_exists('terminal_set_cookie')) {
  function terminal_set_cookie(int $terminalId): void {
    $days = (int)FLUS_TERMINAL_COOKIE_DAYS;
    if ($days <= 0) $days = 30;

    $opts = [
      'expires'  => time() + ($days * 86400),
      'path'     => '/',
      'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ];
    @setcookie((string)FLUS_TERMINAL_COOKIE, (string)$terminalId, $opts);
  }
}

if (!function_exists('terminal_clear_cookie')) {
  function terminal_clear_cookie(): void {
    $opts = [
      'expires'  => time() - 3600,
      'path'     => '/',
      'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ];
    @setcookie((string)FLUS_TERMINAL_COOKIE, '', $opts);
    unset($_COOKIE[FLUS_TERMINAL_COOKIE]);
  }
}

if (!function_exists('terminal_current_id')) {
  /**
   * Devuelve terminal actual usando:
   * 1) $_SESSION['terminal_id']
   * 2) cookie FLUS_TERMINAL_COOKIE
   * Valida que exista y esté activo.
   */
  function terminal_current_id(PDO $pdo): int {
    $tid = (int)($_SESSION['terminal_id'] ?? 0);
    if ($tid > 0) {
      $t = terminal_get($pdo, $tid);
      if ($t && (int)($t['activo'] ?? 0) === 1) return $tid;
    }

    $tid = (int)($_COOKIE[FLUS_TERMINAL_COOKIE] ?? 0);
    if ($tid > 0) {
      $t = terminal_get($pdo, $tid);
      if ($t && (int)($t['activo'] ?? 0) === 1) return $tid;
    }

    return 0;
  }
}

/* =========================================================
   API pública: locks
========================================================= */
if (!function_exists('terminal_locks_gc')) {
  function terminal_locks_gc(PDO $pdo, int $ttlSeconds = 90): void {
    if (!terminal__table_exists($pdo, 'terminal_locks')) return;

    try {
      $s = terminal__schema_locks($pdo);
      $sql = "DELETE FROM terminal_locks WHERE `{$s['expires_at']}` < NOW()";
      $pdo->exec($sql);
    } catch (Throwable $e) {
      error_log('terminal_locks_gc: ' . $e->getMessage());
    }
  }
}

  if (!function_exists('terminal_lock_acquire')) {
    function terminal_lock_acquire(PDO $pdo, int $terminalId, int $userId, string $sessionId, int $ttlSeconds = 90): array {
      if ($terminalId <= 0 || $userId <= 0 || $sessionId === '') {
        return ['ok' => false, 'error' => 'BAD_ARGS'];
      }
      if (!terminal__table_exists($pdo, 'terminal_locks')) {
        return ['ok' => false, 'error' => 'NO_LOCK_TABLE'];
      }

      $ttlSeconds = max(15, min(600, (int)$ttlSeconds));
      $expires = (new DateTimeImmutable('now'))->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

      try {
        $s = terminal__schema_locks($pdo);

        terminal_locks_gc($pdo, $ttlSeconds);

        $sql = "
          INSERT INTO terminal_locks
            (`{$s['terminal_id']}`, `{$s['user_id']}`, `{$s['session_id']}`, `{$s['expires_at']}`" .
            ($s['updated_at'] ? ", `{$s['updated_at']}`" : "") .
            ($s['created_at'] ? ", `{$s['created_at']}`" : "") .
          ")
          VALUES
            (:tid, :uid, :sid, :exp" .
            ($s['updated_at'] ? ", NOW()" : "") .
            ($s['created_at'] ? ", NOW()" : "") .
          ")
          ON DUPLICATE KEY UPDATE
            `{$s['user_id']}` = IF(`{$s['expires_at']}` < NOW() OR (`{$s['user_id']}` = :uid2 AND `{$s['session_id']}` = :sid2), VALUES(`{$s['user_id']}`), `{$s['user_id']}`),
            `{$s['session_id']}` = IF(`{$s['expires_at']}` < NOW() OR (`{$s['user_id']}` = :uid3 AND `{$s['session_id']}` = :sid3), VALUES(`{$s['session_id']}`), `{$s['session_id']}`),
            `{$s['expires_at']}` = IF(`{$s['expires_at']}` < NOW() OR (`{$s['user_id']}` = :uid4 AND `{$s['session_id']}` = :sid4), VALUES(`{$s['expires_at']}`), `{$s['expires_at']}`) " .
            ($s['updated_at'] ? ", `{$s['updated_at']}` = IF(`{$s['expires_at']}` < NOW() OR (`{$s['user_id']}` = :uid5 AND `{$s['session_id']}` = :sid5), NOW(), `{$s['updated_at']}`)" : "") . "
        ";

        $params = [
          ':tid' => $terminalId,
          ':uid' => $userId,
          ':sid' => $sessionId,
          ':exp' => $expires,

          ':uid2'=> $userId, ':sid2'=> $sessionId,
          ':uid3'=> $userId, ':sid3'=> $sessionId,
          ':uid4'=> $userId, ':sid4'=> $sessionId,
        ];

        // ✅ SOLO agregar uid5/sid5 si el SQL los usa
        if ($s['updated_at']) {
          $params[':uid5'] = $userId;
          $params[':sid5'] = $sessionId;
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);

        // Verificamos quién quedó dueño
        $st2 = $pdo->prepare("
          SELECT
            `{$s['user_id']}` AS user_id,
            `{$s['session_id']}` AS session_id,
            `{$s['expires_at']}` AS expires_at
          FROM terminal_locks
          WHERE `{$s['terminal_id']}` = :tid
          LIMIT 1
        ");
        $st2->execute([':tid' => $terminalId]);
        $row = $st2->fetch(PDO::FETCH_ASSOC) ?: [];

        $rowUid = (int)($row['user_id'] ?? 0);
        $rowSid = (string)($row['session_id'] ?? '');
        $rowExp = (string)($row['expires_at'] ?? '');

        if ($rowUid === $userId && $rowSid === $sessionId) {
          return ['ok' => true, 'terminal_id' => $terminalId, 'expires_at' => $rowExp];
        }

        return [
          'ok' => false,
          'error' => 'LOCKED',
          'locked_by_user_id' => $rowUid,
          'locked_by_session' => $rowSid,
          'expires_at' => $rowExp,
        ];

      } catch (Throwable $e) {
        // ✅ devolvé detalle útil (sin romper UI)
        error_log('terminal_lock_acquire: ' . $e->getMessage());
        return [
          'ok' => false,
          'error' => 'DB_ERROR',
          'message' => $e->getMessage(),
        ];
      }
    }
  }


if (!function_exists('terminal_lock_heartbeat')) {
  function terminal_lock_heartbeat(PDO $pdo, int $terminalId, int $userId, string $sessionId, int $ttlSeconds = 90): array {
    if ($terminalId <= 0 || $userId <= 0 || $sessionId === '') {
      return ['ok' => false, 'error' => 'BAD_ARGS'];
    }
    if (!terminal__table_exists($pdo, 'terminal_locks')) {
      return ['ok' => false, 'error' => 'NO_LOCK_TABLE'];
    }

    $ttlSeconds = max(15, min(600, (int)$ttlSeconds));
    $expires = (new DateTimeImmutable('now'))->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

    try {
      $s = terminal__schema_locks($pdo);

      $sql = "
        UPDATE terminal_locks
        SET `{$s['expires_at']}` = :exp" .
        ($s['updated_at'] ? ", `{$s['updated_at']}` = NOW()" : "") . "
        WHERE `{$s['terminal_id']}` = :tid
          AND `{$s['user_id']}` = :uid
          AND `{$s['session_id']}` = :sid
          AND `{$s['expires_at']}` >= NOW()
      ";
      $st = $pdo->prepare($sql);
      $st->execute([':exp'=>$expires, ':tid'=>$terminalId, ':uid'=>$userId, ':sid'=>$sessionId]);

      if ($st->rowCount() > 0) {
        return ['ok' => true, 'expires_at' => $expires];
      }
      return ['ok' => false, 'error' => 'LOCK_NOT_OWNED'];

    } catch (Throwable $e) {
      error_log('terminal_lock_heartbeat: ' . $e->getMessage());
      return ['ok' => false, 'error' => 'DB_ERROR'];
    }
  }
}

if (!function_exists('terminal_lock_release')) {
  function terminal_lock_release(PDO $pdo, int $terminalId, int $userId): void {
    if ($terminalId <= 0 || $userId <= 0) return;
    if (!terminal__table_exists($pdo, 'terminal_locks')) return;

    try {
      $s = terminal__schema_locks($pdo);
      $st = $pdo->prepare("
        DELETE FROM terminal_locks
        WHERE `{$s['terminal_id']}` = :tid
          AND `{$s['user_id']}` = :uid
      ");
      $st->execute([':tid'=>$terminalId, ':uid'=>$userId]);
    } catch (Throwable $e) {
      error_log('terminal_lock_release: ' . $e->getMessage());
    }
  }
}

if (!function_exists('terminal_lock_release_by_session')) {
  function terminal_lock_release_by_session(PDO $pdo, string $sessionId): int {
    if ($sessionId === '') return 0;
    if (!terminal__table_exists($pdo, 'terminal_locks')) return 0;

    try {
      $s = terminal__schema_locks($pdo);
      $st = $pdo->prepare("
        DELETE FROM terminal_locks
        WHERE `{$s['session_id']}` = :sid
      ");
      $st->execute([':sid' => $sessionId]);
      return (int)$st->rowCount();
    } catch (Throwable $e) {
      error_log('terminal_lock_release_by_session: ' . $e->getMessage());
      return 0;
    }
  }
}

if (!function_exists('terminal_lock_status')) {
  function terminal_lock_status(PDO $pdo, int $terminalId): ?array {
    if ($terminalId <= 0) return null;
    if (!terminal__table_exists($pdo, 'terminal_locks')) return null;

    try {
      terminal_locks_gc($pdo);
      $s = terminal__schema_locks($pdo);

      $sql = "
        SELECT
          `{$s['terminal_id']}` AS terminal_id,
          `{$s['user_id']}`     AS user_id,
          `{$s['session_id']}`  AS session_id,
          `{$s['expires_at']}`  AS expires_at" .
          ($s['updated_at'] ? ", `{$s['updated_at']}` AS updated_at" : "") . "
        FROM terminal_locks
        WHERE `{$s['terminal_id']}` = :tid
          AND `{$s['expires_at']}` >= NOW()
        LIMIT 1
      ";

      $st = $pdo->prepare($sql);
      $st->execute([':tid' => $terminalId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) return null;

      return [
        'terminal_id' => (int)($row['terminal_id'] ?? $terminalId),
        'user_id'     => (int)($row['user_id'] ?? 0),
        'session_id'  => (string)($row['session_id'] ?? ''),
        'expires_at'  => (string)($row['expires_at'] ?? ''),
        'updated_at'  => (string)($row['updated_at'] ?? ''),
      ];
    } catch (Throwable $e) {
      error_log('terminal_lock_status: ' . $e->getMessage());
      return null;
    }
  }
}
