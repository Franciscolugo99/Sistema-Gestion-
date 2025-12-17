<?php
// public/api/promos_api.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_login();

$pdo = getPDO();

/* ============================================================
   JSON HELPERS
============================================================ */
function json_out(array $arr, int $status = 200): void {
  http_response_code($status);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function is_post(): bool {
  return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function read_json_body(): array {
  $raw  = file_get_contents('php://input');
  $data = json_decode($raw ?: '', true);
  if (!is_array($data)) {
    json_out(['ok' => false, 'error' => 'JSON inválido'], 400);
  }
  return $data;
}

function require_int(array $a, string $k, int $min = 1): int {
  $v = isset($a[$k]) ? (int)$a[$k] : 0;
  if ($v < $min) json_out(['ok'=>false,'error'=>"Campo inválido: $k"], 400);
  return $v;
}

function require_float(array $a, string $k, float $min = 0.0): float {
  $v = isset($a[$k]) ? (float)$a[$k] : 0.0;
  if ($v < $min) json_out(['ok'=>false,'error'=>"Campo inválido: $k"], 400);
  return $v;
}

function require_str(array $a, string $k, int $minLen = 1): string {
  $v = trim((string)($a[$k] ?? ''));
  if (mb_strlen($v) < $minLen) json_out(['ok'=>false,'error'=>"Campo inválido: $k"], 400);
  return $v;
}

function product_exists_active(PDO $pdo, int $id): bool {
  $st = $pdo->prepare("SELECT 1 FROM productos WHERE id = ? AND activo = 1 LIMIT 1");
  $st->execute([$id]);
  return (bool)$st->fetchColumn();
}

function require_csrf_header_or_fail(): void {
  // Apache/Nginx lo exponen así:
  $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if ($token === '' && isset($_SERVER['HTTP_X_CSRF'])) {
    $token = (string)$_SERVER['HTTP_X_CSRF'];
  }

  if (!csrf_verify($token !== '' ? $token : null)) {
    json_out(['ok'=>false,'error'=>'CSRF inválido. Recargá la página e intentá de nuevo.'], 403);
  }
}

/* ============================================================
   PERMISO (si lo tenés)
============================================================ */
if (function_exists('user_has_permission')) {
  if (!user_has_permission('editar_promos') && !user_has_permission('administrar_config')) {
    json_out(['ok'=>false,'error'=>'Sin permiso'], 403);
  }
}

/* ============================================================
   ACTION
============================================================ */
$action = (string)($_GET['action'] ?? '');

/* ============================================================
   1) LISTAR PRODUCTOS ACTIVOS (para selects)
============================================================ */
if ($action === 'productos') {
  $sql = "
    SELECT id, codigo, nombre
    FROM productos
    WHERE activo = 1
    ORDER BY nombre ASC
  ";
  $prod = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  json_out(['ok' => true, 'productos' => $prod]);
}

/* ============================================================
   2) OBTENER PROMO
============================================================ */
if ($action === 'obtener') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) json_out(['ok'=>false,'error'=>'ID inválido'], 400);

  $st = $pdo->prepare("
    SELECT id, nombre, tipo, activo, fecha_inicio, fecha_fin, precio_combo
    FROM promos
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$id]);
  $promo = $st->fetch(PDO::FETCH_ASSOC);
  if (!$promo) json_out(['ok'=>false,'error'=>'Promo no encontrada'], 404);

  $tipo = (string)($promo['tipo'] ?? '');

  // Promo simple
  if ($tipo !== 'COMBO_FIJO') {
    $st2 = $pdo->prepare("
      SELECT producto_id, n, m, porcentaje
      FROM promo_productos
      WHERE promo_id = ?
      LIMIT 1
    ");
    $st2->execute([$id]);
    $pp = $st2->fetch(PDO::FETCH_ASSOC) ?: [];

    $promo['producto_id'] = isset($pp['producto_id']) ? (int)$pp['producto_id'] : null;
    $promo['n']           = isset($pp['n']) ? (int)$pp['n'] : null;
    $promo['m']           = isset($pp['m']) ? (int)$pp['m'] : null;
    $promo['porcentaje']  = isset($pp['porcentaje']) ? (float)$pp['porcentaje'] : null;

    json_out(['ok'=>true,'promo'=>$promo]);
  }

  // Combo fijo
  $st3 = $pdo->prepare("
    SELECT producto_id, cantidad_requerida AS cantidad
    FROM promo_combo_items
    WHERE promo_id = ?
    ORDER BY id ASC
  ");
  $st3->execute([$id]);
  $promo['items'] = $st3->fetchAll(PDO::FETCH_ASSOC) ?: [];

  json_out(['ok'=>true,'promo'=>$promo]);
}

/* ============================================================
   3) ACTUALIZAR PROMO
============================================================ */
if ($action === 'actualizar') {
  if (!is_post()) json_out(['ok'=>false,'error'=>'Método no permitido'], 405);
  require_csrf_header_or_fail();

  $input = read_json_body();

  $id     = require_int($input, 'id', 1);
  $tipoIn = require_str($input, 'tipo', 1);
  $nombre = require_str($input, 'nombre', 2);

  $allowedTipos = ['N_PAGA_M', 'NTH_PCT', 'COMBO_FIJO'];
  if (!in_array($tipoIn, $allowedTipos, true)) {
    json_out(['ok'=>false,'error'=>'Tipo de promo inválido'], 400);
  }

  // Tipo real (no se puede cambiar desde panel)
  $stT = $pdo->prepare("SELECT tipo FROM promos WHERE id = ? LIMIT 1");
  $stT->execute([$id]);
  $tipoDb = (string)($stT->fetchColumn() ?: '');
  if ($tipoDb === '') json_out(['ok'=>false,'error'=>'Promo no encontrada'], 404);

  if ($tipoDb !== $tipoIn) {
    json_out(['ok'=>false,'error'=>'No se permite cambiar el tipo de la promo desde el panel.'], 400);
  }

  try {
    $pdo->beginTransaction();

    // Limpieza cruzada para consistencia
    if ($tipoIn === 'COMBO_FIJO') {
      $pdo->prepare("DELETE FROM promo_productos WHERE promo_id=?")->execute([$id]);
    } else {
      $pdo->prepare("DELETE FROM promo_combo_items WHERE promo_id=?")->execute([$id]);
      $pdo->prepare("UPDATE promos SET precio_combo = NULL WHERE id=?")->execute([$id]);
    }

    // -----------------------------
    // PROMO SIMPLE
    // -----------------------------
    if ($tipoIn === 'N_PAGA_M' || $tipoIn === 'NTH_PCT') {

      $productoId = require_int($input, 'producto_id', 1);
      $n          = require_int($input, 'n', 1);

      $m   = array_key_exists('m', $input) ? (int)$input['m'] : null;
      $pct = array_key_exists('porcentaje', $input) ? (float)$input['porcentaje'] : null;

      if (!product_exists_active($pdo, $productoId)) {
        $pdo->rollBack();
        json_out(['ok'=>false,'error'=>'Producto inválido o inactivo'], 400);
      }

      if ($tipoIn === 'N_PAGA_M') {
        if ($n <= 1) {
          $pdo->rollBack();
          json_out(['ok'=>false,'error'=>'En NxM, N debe ser >= 2'], 400);
        }
        if ($m === null || $m < 1) {
          $pdo->rollBack();
          json_out(['ok'=>false,'error'=>'En NxM, M debe ser >= 1'], 400);
        }
        if ($m >= $n) {
          $pdo->rollBack();
          json_out(['ok'=>false,'error'=>'En NxM, M debe ser menor que N (ej: 3x2).'], 400);
        }
        $pct = null;
      } else {
        // NTH_PCT
        if ($n < 2) {
          $pdo->rollBack();
          json_out(['ok'=>false,'error'=>'En % a la N°, N debe ser >= 2'], 400);
        }
        if ($pct === null || $pct <= 0 || $pct > 100) {
          $pdo->rollBack();
          json_out(['ok'=>false,'error'=>'Porcentaje debe estar entre 1 y 100'], 400);
        }
        $m = null;
      }

      $pdo->prepare("UPDATE promos SET nombre=:nom WHERE id=:id")
          ->execute([':nom'=>$nombre, ':id'=>$id]);

      $pdo->prepare("DELETE FROM promo_productos WHERE promo_id=?")->execute([$id]);

      $pdo->prepare("
        INSERT INTO promo_productos (promo_id, producto_id, n, m, porcentaje)
        VALUES (:pid, :prod, :n, :m, :pct)
      ")->execute([
        ':pid'  => $id,
        ':prod' => $productoId,
        ':n'    => $n,
        ':m'    => $m,
        ':pct'  => $pct,
      ]);

      $pdo->commit();
      json_out(['ok'=>true]);
    }

    // -----------------------------
    // COMBO FIJO
    // -----------------------------
    if ($tipoIn === 'COMBO_FIJO') {

      $precioCombo = require_float($input, 'precio_combo', 0.01);

      $items = $input['items'] ?? [];
      if (!is_array($items) || count($items) === 0) {
        $pdo->rollBack();
        json_out(['ok'=>false,'error'=>'El combo debe tener al menos 1 producto.'], 400);
      }

      // Agrupar repetidos
      $agg = []; // producto_id => cantidad
      foreach ($items as $it) {
        if (!is_array($it)) continue;

        $pid  = (int)($it['producto_id'] ?? 0);
        $cant = (float)($it['cantidad'] ?? 0);

        if ($pid <= 0) {
          $pdo->rollBack();
          json_out(['ok'=>false,'error'=>'Hay un item sin producto.'], 400);
        }
        if ($cant <= 0) {
          $pdo->rollBack();
          json_out(['ok'=>false,'error'=>'Hay un item con cantidad inválida.'], 400);
        }
        if (!product_exists_active($pdo, $pid)) {
          $pdo->rollBack();
          json_out(['ok'=>false,'error'=>'Hay un producto inválido o inactivo en el combo.'], 400);
        }

        $agg[$pid] = ($agg[$pid] ?? 0.0) + $cant;
      }

      $pdo->prepare("UPDATE promos SET nombre=:nom, precio_combo=:pc WHERE id=:id")
          ->execute([':nom'=>$nombre, ':pc'=>$precioCombo, ':id'=>$id]);

      $pdo->prepare("DELETE FROM promo_combo_items WHERE promo_id=?")->execute([$id]);

      $stIns = $pdo->prepare("
        INSERT INTO promo_combo_items (promo_id, producto_id, cantidad_requerida)
        VALUES (:promo, :prod, :cant)
      ");

      foreach ($agg as $pid => $cant) {
        $stIns->execute([
          ':promo' => $id,
          ':prod'  => (int)$pid,
          ':cant'  => (float)$cant,
        ]);
      }

      $pdo->commit();
      json_out(['ok'=>true]);
    }

    $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'Tipo de promo desconocido'], 400);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // no filtramos detalles por defecto
    json_out(['ok'=>false,'error'=>'Error interno al actualizar.'], 500);
  }
}

/* ============================================================
   4) ELIMINAR PROMO
============================================================ */
if ($action === 'eliminar') {
  if (!is_post()) json_out(['ok'=>false,'error'=>'Método no permitido'], 405);
  require_csrf_header_or_fail();

  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) json_out(['ok'=>false,'error'=>'ID inválido'], 400);

  try {
    $pdo->beginTransaction();

    // si no existe, 404
    $st = $pdo->prepare("SELECT 1 FROM promos WHERE id=? LIMIT 1");
    $st->execute([$id]);
    if (!$st->fetchColumn()) {
      $pdo->rollBack();
      json_out(['ok'=>false,'error'=>'Promo no encontrada'], 404);
    }

    $pdo->prepare("DELETE FROM promo_productos WHERE promo_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM promo_combo_items WHERE promo_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM promos WHERE id=?")->execute([$id]);

    $pdo->commit();
    json_out(['ok'=>true]);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'Error interno al eliminar.'], 500);
  }
}

json_out(['ok'=>false,'error'=>'Acción inválida'], 400);
