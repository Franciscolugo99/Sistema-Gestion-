<?php
// public/promo_delete.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('editar_productos');
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

// Solo POST (evita deletes por link / CSRF fácil)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  die('Método no permitido');
}

// CSRF
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
  header('Location: promos.php?err=' . urlencode('CSRF inválido. Recargá y probá de nuevo.'));
  exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: promos.php?err=' . urlencode('ID inválido'));
  exit;
}

try {
  $pdo->beginTransaction();

  // Borrar dependencias (simples + combos)
  $pdo->prepare("DELETE FROM promo_productos   WHERE promo_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM promo_combo_items WHERE promo_id = ?")->execute([$id]);

  // Borrar promo
  $pdo->prepare("DELETE FROM promos WHERE id = ?")->execute([$id]);

  $pdo->commit();

  header('Location: promos.php?msg=' . urlencode('Promo eliminada'));
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: promos.php?err=' . urlencode('No se pudo eliminar la promo'));
  exit;
}
