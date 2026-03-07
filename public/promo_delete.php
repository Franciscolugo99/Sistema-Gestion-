<?php
// public/promo_delete.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('editar_promos');

// Solo POST (evita deletes por link / CSRF fácil)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  flus_abort(405, 'Método no permitido');
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

  // BUG-07 FIX: invalidar cache APCu de PromoEngine para que la promo eliminada
  // no siga apareciendo como activa en caja hasta que expire el TTL.
  // La ruta API ya lo hacía; esta ruta legacy (form) no.
  require_once __DIR__ . '/promos_logic.php';
  if (function_exists('invalidarCachePromos')) {
    invalidarCachePromos();
  }

  header('Location: promos.php?msg=' . urlencode('Promo eliminada'));
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: promos.php?err=' . urlencode('No se pudo eliminar la promo'));
  exit;
}
