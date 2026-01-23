<?php
declare(strict_types=1);
require_once __DIR__ . '/../_csrf_guard.php'; csrf_require(['methods'=>['POST','PUT','DELETE']]);
// public/api/actions/promo_eliminar.php
require_login_json();
require_perm_json('editar_promos');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  json_fail('Método no permitido', 405);
}

require_csrf_json($body);

$id = (int)($_GET['id'] ?? ($body['id'] ?? 0));
if ($id <= 0) json_fail('ID inválido', 422);

try {
  $pdo->beginTransaction();

  $pdo->prepare("DELETE FROM promo_productos WHERE promo_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM promo_combo_items WHERE promo_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM promos WHERE id = ?")->execute([$id]);

  $pdo->commit();

  if (function_exists('invalidate_promos_cache')) {
    invalidate_promos_cache($pdo);
  }

  json_ok();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_fail('DB_ERROR', 500);
}
