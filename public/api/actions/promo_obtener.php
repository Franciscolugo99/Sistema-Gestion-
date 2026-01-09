<?php
declare(strict_types=1);
// public/api/actions/promo_obtener.php
require_login_json();
require_perm_json('editar_promos');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  json_fail('Método no permitido', 405);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_fail('ID inválido', 422);

try {
  $st = $pdo->prepare("SELECT * FROM promos WHERE id = :id LIMIT 1");
  $st->execute([':id' => $id]);
  $promo = $st->fetch(PDO::FETCH_ASSOC);
  if (!$promo) json_fail('Promo no encontrada', 404);

  $tipo = (string)($promo['tipo'] ?? '');

  // Normalizar respuesta esperada por promos.js
  $out = [
    'id'          => (int)$promo['id'],
    'nombre'      => (string)($promo['nombre'] ?? ''),
    'tipo'        => (string)($promo['tipo'] ?? ''),
    'fecha_inicio'=> $promo['fecha_inicio'] ?? null,
    'fecha_fin'   => $promo['fecha_fin'] ?? null,
    'activo'      => isset($promo['activo']) ? (int)$promo['activo'] : 1,
  ];

  if ($tipo === 'COMBO_FIJO') {
    $out['precio_combo'] = isset($promo['precio_combo']) ? (float)$promo['precio_combo'] : 0.0;

    $st = $pdo->prepare("
      SELECT producto_id, cantidad_requerida
      FROM promo_combo_items
      WHERE promo_id = :id
      ORDER BY producto_id ASC
    ");
    $st->execute([':id' => $id]);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $items[] = [
        'producto_id' => (int)$r['producto_id'],
        'cantidad'    => (float)$r['cantidad_requerida'],
      ];
    }
    $out['items'] = $items;

  } else {
    $st = $pdo->prepare("
      SELECT producto_id, n, m, porcentaje
      FROM promo_productos
      WHERE promo_id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $pp = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $out['producto_id'] = isset($pp['producto_id']) ? (int)$pp['producto_id'] : null;
    $out['n']           = isset($pp['n']) ? (int)$pp['n'] : null;
    $out['m']           = isset($pp['m']) ? (int)$pp['m'] : null;
    $out['porcentaje']  = isset($pp['porcentaje']) ? (float)$pp['porcentaje'] : null;
  }

  json_ok(['promo' => $out]);
} catch (Throwable $e) {
  json_fail('DB_ERROR', 500);
}
