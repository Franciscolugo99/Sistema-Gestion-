<?php
// public/lib/terminal.php
declare(strict_types=1);

/*
  Terminales (puestos/cajas físicas) + Locks
  -----------------------------------------
  - terminal_id se guarda en cookie 'terminal_id'
  - El lock evita 2 cajeros en el mismo terminal a la vez
*/

function terminal_cookie_id(): int {
  $v = $_COOKIE['terminal_id'] ?? '';
  return is_numeric($v) ? (int)$v : 0;
}

function terminal_set_cookie(int $terminalId): void {
  // 365 días
  setcookie('terminal_id', (string)$terminalId, [
    'expires'  => time() + 365*24*60*60,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => false, // lo puede leer JS si alguna vez lo necesitás
    'samesite' => 'Lax',
  ]);
  $_COOKIE['terminal_id'] = (string)$terminalId;
}

function terminal_get(PDO $pdo, int $terminalId): ?array {
  if ($terminalId <= 0) return null;
  $st = $pdo->prepare("SELECT id, nombre, codigo, activo FROM terminales WHERE id = :id LIMIT 1");
  $st->execute([':id' => $terminalId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function terminal_list_active(PDO $pdo): array {
  $st = $pdo->query("SELECT id, nombre, codigo FROM terminales WHERE activo = 1 ORDER BY id ASC");
  return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * Devuelve terminal_id elegido (cookie), validando que exista y esté activo.
 * Si no hay, devuelve 0.
 */
function terminal_current_id(PDO $pdo): int {
  $tid = terminal_cookie_id();
  if ($tid <= 0) return 0;
  $t = terminal_get($pdo, $tid);
  if (!$t || (int)$t['activo'] !== 1) return 0;
  return (int)$t['id'];
}

/**
 * Limpia locks vencidos (por TTL).
 * Nota: TTL pensado para caídas de navegador/PC.
 */
function terminal_locks_gc(PDO $pdo, int $ttlSeconds = 90): void {
  $ttlSeconds = max(30, (int)$ttlSeconds);
  $sql = "DELETE FROM terminal_locks WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL {$ttlSeconds} SECOND)";
  $pdo->exec($sql);
}

/**
 * Intenta tomar lock del terminal para (user_id, session_id).
 * Si está ocupado por otro, retorna ['ok'=>false,'error'=>'LOCKED','by'=>['username'=>...]]
 */
function terminal_lock_acquire(PDO $pdo, int $terminalId, int $userId, string $sessionId, int $ttlSeconds = 90): array {
  if ($terminalId <= 0) return ['ok' => false, 'error' => 'NO_TERMINAL'];
  if ($userId <= 0) return ['ok' => false, 'error' => 'NO_USER'];
  if ($sessionId === '') return ['ok' => false, 'error' => 'NO_SESSION'];

  $ttlSeconds = max(30, (int)$ttlSeconds);

  $pdo->beginTransaction();
  try {
    terminal_locks_gc($pdo, $ttlSeconds);

    $st = $pdo->prepare("
      SELECT tl.terminal_id, tl.user_id, tl.session_id, tl.last_seen_at,
             u.username
      FROM terminal_locks tl
      LEFT JOIN users u ON u.id = tl.user_id
      WHERE tl.terminal_id = :tid
      LIMIT 1
      FOR UPDATE
    ");
    $st->execute([':tid' => $terminalId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    // No hay lock -> lo creamos
    if (!$row) {
      $ins = $pdo->prepare("
        INSERT INTO terminal_locks (terminal_id, user_id, session_id, last_seen_at, created_at)
        VALUES (:tid, :uid, :sid, NOW(), NOW())
      ");
      $ins->execute([':tid' => $terminalId, ':uid' => $userId, ':sid' => $sessionId]);
      $pdo->commit();
      return ['ok' => true, 'info' => 'CREATED'];
    }

    $lockedUserId    = (int)($row['user_id'] ?? 0);
    $lockedBySession = (string)($row['session_id'] ?? '');
    $lastSeen        = (string)($row['last_seen_at'] ?? '');
    $lastTs          = $lastSeen ? strtotime($lastSeen) : 0;
    $expired         = (!$lastTs) || (time() - $lastTs > $ttlSeconds);

    // Misma sesión -> refrescar
    if ($lockedBySession === $sessionId) {
      $up = $pdo->prepare("
        UPDATE terminal_locks
        SET user_id = :uid, last_seen_at = NOW()
        WHERE terminal_id = :tid AND session_id = :sid
      ");
      $up->execute([':uid' => $userId, ':tid' => $terminalId, ':sid' => $sessionId]);
      $pdo->commit();
      return ['ok' => true, 'info' => 'REFRESH'];
    }

    // Mismo usuario -> takeover permitido
    if ($lockedUserId === $userId) {
      $up = $pdo->prepare("
        UPDATE terminal_locks
        SET session_id = :sid, last_seen_at = NOW()
        WHERE terminal_id = :tid AND user_id = :uid
      ");
      $up->execute([':sid' => $sessionId, ':tid' => $terminalId, ':uid' => $userId]);
      $pdo->commit();
      return ['ok' => true, 'info' => 'TAKEOVER_SAME_USER'];
    }

    // Expiró -> takeover
    if ($expired) {
      $up = $pdo->prepare("
        UPDATE terminal_locks
        SET user_id = :uid, session_id = :sid, last_seen_at = NOW()
        WHERE terminal_id = :tid
      ");
      $up->execute([':uid' => $userId, ':sid' => $sessionId, ':tid' => $terminalId]);
      $pdo->commit();
      return ['ok' => true, 'info' => 'TAKEOVER_EXPIRED'];
    }

    // Ocupado por otro usuario
    $pdo->commit();
    return [
      'ok'    => false,
      'error' => 'LOCKED',
      'by'    => [
        'user_id'   => $lockedUserId,
        'username'  => (string)($row['username'] ?? 'Otro usuario'),
        'last_seen' => $lastSeen,
      ],
    ];

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ['ok' => false, 'error' => 'ERROR', 'detail' => $e->getMessage()];
  }
}

/**
 * Usalo en require_login() para evitar loops.
 */
function terminal_lock_assert(PDO $pdo, int $terminalId, int $userId, string $sessionId, int $ttlSeconds = 90): bool {
  if ($terminalId <= 0 || $userId <= 0 || $sessionId === '') return false;
  $res = terminal_lock_acquire($pdo, $terminalId, $userId, $sessionId, $ttlSeconds);
  return (bool)($res['ok'] ?? false);
}

function terminal_lock_touch(PDO $pdo, int $terminalId, int $userId, string $sessionId): bool {
  $st = $pdo->prepare("
    UPDATE terminal_locks
    SET last_seen_at = NOW()
    WHERE terminal_id = ? AND user_id = ? AND session_id = ?
  ");
  $st->execute([$terminalId, $userId, $sessionId]);
  return $st->rowCount() > 0;
}

/**
 * Compat (no recomendado). Mejor usar terminal_lock_touch() o terminal_lock_assert().
 */
function terminal_lock_refresh(PDO $pdo, int $terminalId, string $sessionId): bool {
  if ($terminalId <= 0 || $sessionId === '') return false;
  $st = $pdo->prepare("
    UPDATE terminal_locks
    SET last_seen_at = NOW()
    WHERE terminal_id = :tid AND session_id = :sid
  ");
  $st->execute([':tid' => $terminalId, ':sid' => $sessionId]);
  return $st->rowCount() > 0;
}

/**
 * Libera lock:
 * - Preferente por terminal+user (porque session_id puede cambiar por regenerate)
 * - Si pasás session_id, también lo valida (más estricto)
 */
function terminal_lock_release(PDO $pdo, int $terminalId, int $userId = 0, string $sessionId = ''): void {
  if ($terminalId <= 0) return;

  if ($userId > 0 && $sessionId !== '') {
    $st = $pdo->prepare("DELETE FROM terminal_locks WHERE terminal_id = :tid AND user_id = :uid AND session_id = :sid");
    $st->execute([':tid' => $terminalId, ':uid' => $userId, ':sid' => $sessionId]);
    return;
  }

  if ($userId > 0) {
    $st = $pdo->prepare("DELETE FROM terminal_locks WHERE terminal_id = :tid AND user_id = :uid");
    $st->execute([':tid' => $terminalId, ':uid' => $userId]);
    return;
  }

  // fallback
  $st = $pdo->prepare("DELETE FROM terminal_locks WHERE terminal_id = :tid");
  $st->execute([':tid' => $terminalId]);
}
