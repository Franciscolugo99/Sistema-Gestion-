<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

if (!function_exists('flus_user_sessions_table_exists')) {
  function flus_user_sessions_table_exists(PDO $pdo): bool
  {
    static $cache = [];

    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) {
      return (bool)$cache[$key];
    }

    try {
      $cache[$key] = function_exists('flus_table_exists')
        ? (bool)flus_table_exists($pdo, 'user_sessions')
        : false;
    } catch (Throwable $e) {
      $cache[$key] = false;
    }

    return (bool)$cache[$key];
  }
}

if (!function_exists('flus_session_registry_ip')) {
  function flus_session_registry_ip(): string
  {
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  }
}

if (!function_exists('flus_session_registry_user_agent')) {
  function flus_session_registry_user_agent(): string
  {
    return trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  }
}

if (!function_exists('flus_session_registry_path')) {
  function flus_session_registry_path(): string
  {
    return trim((string)($_SERVER['REQUEST_URI'] ?? ''));
  }
}

if (!function_exists('flus_session_registry_truncate')) {
  function flus_session_registry_truncate(string $value, int $limit): ?string
  {
    $value = trim($value);
    if ($value === '') {
      return null;
    }

    if (function_exists('mb_substr')) {
      return mb_substr($value, 0, $limit);
    }

    return substr($value, 0, $limit);
  }
}

if (!function_exists('flus_session_fetch')) {
  function flus_session_fetch(PDO $pdo, string $sessionId): ?array
  {
    if ($sessionId === '' || !flus_user_sessions_table_exists($pdo)) {
      return null;
    }

    try {
      $st = $pdo->prepare('SELECT * FROM user_sessions WHERE session_id = :sid LIMIT 1');
      $st->execute([':sid' => $sessionId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return is_array($row) ? $row : null;
    } catch (Throwable $e) {
      error_log('flus_session_fetch: ' . $e->getMessage());
      return null;
    }
  }
}

if (!function_exists('flus_session_resolve_terminal_id')) {
  function flus_session_resolve_terminal_id(PDO $pdo, array $meta = []): ?int
  {
    if (isset($meta['selected_terminal_id'])) {
      $terminalId = (int)$meta['selected_terminal_id'];
      return $terminalId > 0 ? $terminalId : null;
    }

    $terminalId = (int)($_SESSION['terminal_id'] ?? 0);
    if ($terminalId > 0) {
      return $terminalId;
    }

    if (function_exists('terminal_current_id')) {
      try {
        $terminalId = (int)terminal_current_id($pdo);
        if ($terminalId > 0) {
          $_SESSION['terminal_id'] = $terminalId;
          return $terminalId;
        }
      } catch (Throwable $e) {
        error_log('flus_session_resolve_terminal_id: ' . $e->getMessage());
      }
    }

    return null;
  }
}

if (!function_exists('flus_session_register')) {
  function flus_session_register(PDO $pdo, array $user, array $meta = []): void
  {
    if (!flus_user_sessions_table_exists($pdo)) {
      return;
    }

    $sessionId = (string)($meta['session_id'] ?? session_id());
    $userId = (int)($user['id'] ?? 0);
    if ($sessionId === '' || $userId <= 0) {
      return;
    }

    $selectedTerminalId = flus_session_resolve_terminal_id($pdo, $meta);
    $path = (string)($meta['last_path'] ?? flus_session_registry_path());
    $ip = (string)($meta['ip_address'] ?? flus_session_registry_ip());
    $userAgent = (string)($meta['user_agent'] ?? flus_session_registry_user_agent());

    try {
      $sql = "
        INSERT INTO user_sessions (
          session_id,
          user_id,
          status,
          login_at,
          last_seen_at,
          last_path,
          ip_address,
          user_agent,
          selected_terminal_id,
          revoked_at,
          revoked_by,
          revoked_reason,
          logout_at
        ) VALUES (
          :sid,
          :uid,
          'ACTIVE',
          NOW(),
          NOW(),
          :path,
          :ip,
          :ua,
          :terminal,
          NULL,
          NULL,
          NULL,
          NULL
        )
        ON DUPLICATE KEY UPDATE
          user_id = VALUES(user_id),
          status = 'ACTIVE',
          last_seen_at = NOW(),
          last_path = VALUES(last_path),
          ip_address = VALUES(ip_address),
          user_agent = VALUES(user_agent),
          selected_terminal_id = VALUES(selected_terminal_id),
          revoked_at = NULL,
          revoked_by = NULL,
          revoked_reason = NULL,
          logout_at = NULL
      ";

      $pdo->prepare($sql)->execute([
        ':sid' => $sessionId,
        ':uid' => $userId,
        ':path' => $path !== '' ? $path : null,
        ':ip' => $ip !== '' ? $ip : null,
        ':ua' => flus_session_registry_truncate($userAgent, 255),
        ':terminal' => $selectedTerminalId,
      ]);

      $_SESSION['__user_session_touched_at'] = 0;
    } catch (Throwable $e) {
      error_log('flus_session_register: ' . $e->getMessage());
    }
  }
}

if (!function_exists('flus_session_touch')) {
  function flus_session_touch(PDO $pdo, int $userId, string $sessionId, array $meta = []): void
  {
    if ($userId <= 0 || $sessionId === '' || !flus_user_sessions_table_exists($pdo)) {
      return;
    }

    $force = !empty($meta['force']);
    $nowTs = time();
    $selectedTerminalId = flus_session_resolve_terminal_id($pdo, $meta);
    $path = (string)($meta['last_path'] ?? flus_session_registry_path());
    $ip = (string)($meta['ip_address'] ?? flus_session_registry_ip());
    $userAgent = (string)($meta['user_agent'] ?? flus_session_registry_user_agent());
    $lastTouchTs = (int)($_SESSION['__user_session_touched_at'] ?? 0);

    if (!$force && $lastTouchTs > 0 && ($nowTs - $lastTouchTs) < 20) {
      $sessionRow = flus_session_fetch($pdo, $sessionId);
      $lastPath = trim((string)($sessionRow['last_path'] ?? ''));
      $rowTerminalId = (int)($sessionRow['selected_terminal_id'] ?? 0);
      $resolvedTerminalId = $selectedTerminalId ?? 0;

      if ($lastPath === trim($path) && $rowTerminalId === $resolvedTerminalId) {
        return;
      }
    }

    try {
      $st = $pdo->prepare("
        UPDATE user_sessions
        SET
          last_seen_at = NOW(),
          last_path = :path,
          ip_address = :ip,
          user_agent = :ua,
          selected_terminal_id = :terminal
        WHERE session_id = :sid
          AND user_id = :uid
          AND status = 'ACTIVE'
      ");
      $st->execute([
        ':path' => $path !== '' ? $path : null,
        ':ip' => $ip !== '' ? $ip : null,
        ':ua' => flus_session_registry_truncate($userAgent, 255),
        ':terminal' => $selectedTerminalId,
        ':sid' => $sessionId,
        ':uid' => $userId,
      ]);

      $_SESSION['__user_session_touched_at'] = $nowTs;
    } catch (Throwable $e) {
      error_log('flus_session_touch: ' . $e->getMessage());
    }
  }
}

if (!function_exists('flus_session_update_selected_terminal')) {
  function flus_session_update_selected_terminal(PDO $pdo, string $sessionId, ?int $terminalId): void
  {
    if ($sessionId === '' || !flus_user_sessions_table_exists($pdo)) {
      return;
    }

    try {
      $st = $pdo->prepare("
        UPDATE user_sessions
        SET selected_terminal_id = :terminal, last_seen_at = NOW()
        WHERE session_id = :sid
          AND status = 'ACTIVE'
      ");
      $st->execute([
        ':terminal' => ($terminalId !== null && $terminalId > 0) ? $terminalId : null,
        ':sid' => $sessionId,
      ]);
      $_SESSION['__user_session_touched_at'] = time();
    } catch (Throwable $e) {
      error_log('flus_session_update_selected_terminal: ' . $e->getMessage());
    }
  }
}

if (!function_exists('flus_session_mark_logged_out')) {
  function flus_session_mark_logged_out(PDO $pdo, string $sessionId, bool $preserveRevoked = false): void
  {
    if ($sessionId === '' || !flus_user_sessions_table_exists($pdo)) {
      return;
    }

    try {
      $st = $pdo->prepare("
        UPDATE user_sessions
        SET
          status = CASE
            WHEN :preserve = 1 AND status = 'REVOKED' THEN status
            ELSE 'LOGGED_OUT'
          END,
          selected_terminal_id = NULL,
          logout_at = NOW(),
          last_seen_at = NOW()
        WHERE session_id = :sid
      ");
      $st->execute([
        ':preserve' => $preserveRevoked ? 1 : 0,
        ':sid' => $sessionId,
      ]);
    } catch (Throwable $e) {
      error_log('flus_session_mark_logged_out: ' . $e->getMessage());
    }
  }
}

if (!function_exists('flus_session_revoke')) {
  function flus_session_revoke(PDO $pdo, string $sessionId, int $revokedBy, string $reason = ''): void
  {
    if ($sessionId === '' || !flus_user_sessions_table_exists($pdo)) {
      return;
    }

    try {
      $st = $pdo->prepare("
        UPDATE user_sessions
        SET
          status = 'REVOKED',
          revoked_at = NOW(),
          revoked_by = :revoked_by,
          revoked_reason = :reason
        WHERE session_id = :sid
      ");
      $st->execute([
        ':sid' => $sessionId,
        ':revoked_by' => $revokedBy > 0 ? $revokedBy : null,
        ':reason' => flus_session_registry_truncate($reason, 255),
      ]);
    } catch (Throwable $e) {
      error_log('flus_session_revoke: ' . $e->getMessage());
    }

    if (function_exists('terminal_lock_release_by_session')) {
      terminal_lock_release_by_session($pdo, $sessionId);
    }
  }
}

if (!function_exists('flus_session_list_active')) {
  function flus_session_list_active(PDO $pdo, int $minutes = 30): array
  {
    if (!flus_user_sessions_table_exists($pdo)) {
      return [];
    }

    $minutes = max(1, min(1440, $minutes));
    $cutoff = (new DateTimeImmutable('now'))->modify("-{$minutes} minutes")->format('Y-m-d H:i:s');

    try {
      $sql = "
        SELECT
          us.session_id,
          us.user_id,
          us.status,
          us.login_at,
          us.last_seen_at,
          us.last_path,
          us.ip_address,
          us.user_agent,
          us.selected_terminal_id,
          u.nombre AS user_nombre,
          u.username,
          st.nombre AS selected_terminal_nombre,
          tl.terminal_id AS locked_terminal_id,
          tl.expires_at AS lock_expires_at,
          lt.nombre AS locked_terminal_nombre
        FROM user_sessions us
        JOIN users u ON u.id = us.user_id
        LEFT JOIN terminales st ON st.id = us.selected_terminal_id
        LEFT JOIN terminal_locks tl ON tl.session_id = us.session_id AND tl.expires_at >= NOW()
        LEFT JOIN terminales lt ON lt.id = tl.terminal_id
        WHERE us.status = 'ACTIVE'
          AND us.last_seen_at >= :cutoff
        ORDER BY us.last_seen_at DESC, us.login_at DESC
      ";

      $st = $pdo->prepare($sql);
      $st->execute([':cutoff' => $cutoff]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      return array_map(static function (array $row): array {
        $row['user_id'] = (int)($row['user_id'] ?? 0);
        $row['selected_terminal_id'] = (int)($row['selected_terminal_id'] ?? 0);
        $row['locked_terminal_id'] = (int)($row['locked_terminal_id'] ?? 0);
        $row['session_id'] = (string)($row['session_id'] ?? '');
        $row['username'] = (string)($row['username'] ?? '');
        $row['user_nombre'] = (string)($row['user_nombre'] ?? '');
        $row['selected_terminal_nombre'] = (string)($row['selected_terminal_nombre'] ?? '');
        $row['locked_terminal_nombre'] = (string)($row['locked_terminal_nombre'] ?? '');
        $row['last_seen_at'] = (string)($row['last_seen_at'] ?? '');
        $row['login_at'] = (string)($row['login_at'] ?? '');
        $row['last_path'] = (string)($row['last_path'] ?? '');
        $row['ip_address'] = (string)($row['ip_address'] ?? '');
        $row['user_agent'] = (string)($row['user_agent'] ?? '');
        $row['status'] = (string)($row['status'] ?? 'ACTIVE');
        $row['lock_expires_at'] = (string)($row['lock_expires_at'] ?? '');
        $row['display_name'] = trim($row['user_nombre']) !== '' ? $row['user_nombre'] : $row['username'];
        return $row;
      }, $rows);
    } catch (Throwable $e) {
      error_log('flus_session_list_active: ' . $e->getMessage());
      return [];
    }
  }
}
