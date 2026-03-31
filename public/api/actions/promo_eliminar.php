<?php
declare(strict_types=1);
// public/api/actions/promo_eliminar.php

$id = (int)($_GET['id'] ?? ($body['id'] ?? 0));
if ($id <= 0) {
  json_fail('ID invalido', 422);
}

try {
  $pdo->beginTransaction();

  $pdo->prepare('DELETE FROM promo_productos WHERE promo_id = ?')->execute([$id]);
  $pdo->prepare('DELETE FROM promo_combo_items WHERE promo_id = ?')->execute([$id]);
  $pdo->prepare('DELETE FROM promos WHERE id = ?')->execute([$id]);

  $pdo->commit();

  if (function_exists('invalidate_promos_cache')) {
    invalidate_promos_cache($pdo);
  }

  json_ok();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  json_fail('DB_ERROR', 500);
}
