<?php
// public/auth.php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';

// Sesión
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/**
 * Devuelve el usuario logueado o null
 */
function current_user(): ?array {
  return isset($_SESSION['user']) && is_array($_SESSION['user'])
    ? $_SESSION['user']
    : null;
}

function is_logged_in(): bool {
  return current_user() !== null;
}

/**
 * Requiere login. Opcional: agrega next para volver a la página pedida.
 */
function require_login(bool $withNext = true): void {
  if (!is_logged_in()) {
    $url = 'login.php';
    if ($withNext) {
      $req = $_SERVER['REQUEST_URI'] ?? '';
      if ($req !== '') {
        $url .= '?next=' . urlencode($req);
      }
    }
    header('Location: ' . $url);
    exit;
  }
}

/**
 * Chequea permiso por slug.
 * Cachea en memoria (por request) para no pegarle al DB 10 veces.
 */
function user_has_permission(string $slug): bool {
  $u = current_user();
  if (!$u) return false;

  static $cache = []; // ['slug' => true/false]
  if (array_key_exists($slug, $cache)) return (bool)$cache[$slug];

  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) {
    $cache[$slug] = false;
    return false;
  }

  $pdo = getPDO();

  $sql = "
    SELECT 1
    FROM users u
    JOIN roles r ON u.role_id = r.id
    JOIN role_permission rp ON r.id = rp.role_id
    JOIN permissions p ON rp.permission_id = p.id
    WHERE u.id = :uid
      AND u.activo = 1
      AND p.slug = :slug
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':uid'  => $userId,
    ':slug' => $slug,
  ]);

  $ok = (bool)$stmt->fetchColumn();
  $cache[$slug] = $ok;
  return $ok;
}

/**
 * Requiere permiso, si no -> 403.
 */
function require_permission(string $slug): void {
  if (!user_has_permission($slug)) {
    http_response_code(403);
    echo "No tenés permisos para acceder a esta sección.";
    exit;
  }
}
