<?php
declare(strict_types=1);
// public/api/actions/promo_toggle_estado.php

$id = (int)($body['id'] ?? ($_GET['id'] ?? 0));
if ($id <= 0) {
  json_fail('ID invalido', 422);
}

if (!array_key_exists('activo', $body)) {
  json_fail('Estado requerido', 422);
}

$activo = ((int)$body['activo'] === 1) ? 1 : 0;

try {
  $st = $pdo->prepare('UPDATE promos SET activo = :activo WHERE id = :id');
  $st->execute([
    ':activo' => $activo,
    ':id' => $id,
  ]);

  if ($st->rowCount() === 0) {
    $exists = $pdo->prepare('SELECT id FROM promos WHERE id = :id LIMIT 1');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) {
      json_fail('Promo no encontrada', 404);
    }
  }

  if (function_exists('invalidate_promos_cache')) {
    invalidate_promos_cache($pdo);
  }

  json_ok([
    'id' => $id,
    'activo' => $activo,
    'estado' => $activo === 1 ? 'activa' : 'inactiva',
  ]);
} catch (Throwable $e) {
  error_log('[promo_toggle_estado] ' . $e->getMessage());
  json_fail('DB_ERROR', 500);
}
