<?php
declare(strict_types=1);

if (!($canManageTesoreria ?? false)) {
    return;
}

$tesObligacionId = (int)($c['tes_obligacion_id'] ?? 0);
if ($tesObligacionId > 0):
    $tesSaldo = max(0, (float)($c['tes_obligacion_total'] ?? 0) - (float)($c['tes_obligacion_pagado'] ?? 0));
?>
  <a class="btn btn-secondary btn-compact" href="tesoreria_obligaciones.php?compra_id=<?= (int)$c['id'] ?>" title="Saldo deuda <?= h(money_ar($tesSaldo)) ?>">
    Deuda <?= h((string)($c['tes_obligacion_estado'] ?? '')) ?>
  </a>
<?php else: ?>
  <form method="post" style="display:inline;" class="js-compra-confirm-form" data-confirm-title="Crear deuda" data-confirm-message="Se creara una obligacion pendiente en Tesoreria para esta compra. Si ya existe, FLUS reutilizara la vinculacion.">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="crear_obligacion_tesoreria">
    <input type="hidden" name="compra_id" value="<?= (int)$c['id'] ?>">
    <button class="btn btn-secondary btn-compact" type="submit" title="Crear deuda en tesoreria">Crear deuda</button>
  </form>
<?php endif; ?>
