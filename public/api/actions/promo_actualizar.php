<?php
declare(strict_types=1);
// public/api/actions/promo_actualizar.php
require_login_json();
require_perm_json('editar_promos');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  json_fail('Método no permitido', 405);
}

require_csrf_json($body);

$id = (int)($body['id'] ?? 0);
if ($id <= 0) json_fail('ID inválido', 422);

$nombre = trim((string)($body['nombre'] ?? ''));
$tipo   = strtoupper(trim((string)($body['tipo'] ?? '')));

$allowed = ['N_PAGA_M','NTH_PCT','COMBO_FIJO'];
if ($nombre === '') json_fail('Nombre requerido', 422);
if (!in_array($tipo, $allowed, true)) json_fail('Tipo inválido', 422);

try {
  $pdo->beginTransaction();

  // asegurar que existe
  $st = $pdo->prepare("SELECT id FROM promos WHERE id = :id LIMIT 1");
  $st->execute([':id' => $id]);
  if (!$st->fetchColumn()) {
    $pdo->rollBack();
    json_fail('Promo no encontrada', 404);
  }

  // update base
  $precioCombo = null;
  if ($tipo === 'COMBO_FIJO') {
    $precioCombo = (float)($body['precio_combo'] ?? 0);
    if ($precioCombo <= 0) {
      $pdo->rollBack();
      json_fail('Precio de combo inválido', 422);
    }
  }

  // Nota: no tocamos fecha_inicio/fecha_fin/activo acá (se conservan)
  $st = $pdo->prepare("
    UPDATE promos
    SET nombre = :nombre,
        tipo   = :tipo,
        precio_combo = :precio_combo
    WHERE id = :id
  ");
  $st->execute([
    ':nombre' => $nombre,
    ':tipo'   => $tipo,
    ':precio_combo' => $precioCombo,
    ':id'     => $id,
  ]);

  // limpiar relaciones para evitar restos de otro tipo
  $pdo->prepare("DELETE FROM promo_productos WHERE promo_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM promo_combo_items WHERE promo_id = ?")->execute([$id]);

  if ($tipo === 'N_PAGA_M') {
    $productoId = (int)($body['producto_id'] ?? 0);
    $n = (int)($body['n'] ?? 0);
    $m = (int)($body['m'] ?? 0);
    if ($productoId <= 0 || $n <= 0 || $m <= 0 || $m > $n) {
      $pdo->rollBack();
      json_fail('Datos NxM inválidos', 422);
    }

    $st = $pdo->prepare("
      INSERT INTO promo_productos (promo_id, producto_id, n, m, porcentaje)
      VALUES (:promo_id, :producto_id, :n, :m, NULL)
    ");
    $st->execute([
      ':promo_id'    => $id,
      ':producto_id' => $productoId,
      ':n'           => $n,
      ':m'           => $m,
    ]);

  } elseif ($tipo === 'NTH_PCT') {
    $productoId = (int)($body['producto_id'] ?? 0);
    $n = (int)($body['n'] ?? 0);
    $pct = isset($body['porcentaje']) ? (float)$body['porcentaje'] : 0.0;

    if ($productoId <= 0 || $n <= 0 || $pct <= 0 || $pct > 100) {
      $pdo->rollBack();
      json_fail('Datos NTH_PCT inválidos', 422);
    }

    $st = $pdo->prepare("
      INSERT INTO promo_productos (promo_id, producto_id, n, m, porcentaje)
      VALUES (:promo_id, :producto_id, :n, NULL, :porcentaje)
    ");
    $st->execute([
      ':promo_id'    => $id,
      ':producto_id' => $productoId,
      ':n'           => $n,
      ':porcentaje'  => $pct,
    ]);

  } else { // COMBO_FIJO
    $items = $body['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
      $pdo->rollBack();
      json_fail('El combo debe tener items', 422);
    }

    $ins = $pdo->prepare("
      INSERT INTO promo_combo_items (promo_id, producto_id, cantidad_requerida)
      VALUES (:promo_id, :producto_id, :cantidad)
    ");

    $okAny = false;
    foreach ($items as $it) {
      $pid = (int)($it['producto_id'] ?? 0);
      $cant = (float)($it['cantidad'] ?? 0);
      if ($pid > 0 && $cant > 0) {
        $ins->execute([
          ':promo_id' => $id,
          ':producto_id' => $pid,
          ':cantidad' => $cant,
        ]);
        $okAny = true;
      }
    }
    if (!$okAny) {
      $pdo->rollBack();
      json_fail('Items de combo inválidos', 422);
    }
  }

  $pdo->commit();

  // invalidar cache promos (si existe)
  if (function_exists('invalidate_promos_cache')) {
    invalidate_promos_cache($pdo);
  }

  json_ok();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_fail('DB_ERROR', 500);
}
