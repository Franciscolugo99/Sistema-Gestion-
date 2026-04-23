<?php
declare(strict_types=1);

$compraMargenId = (int)($_GET['compra_id'] ?? 0);
if (($savedFlag ?? '') === 'confirmed' && $compraMargenId > 0) {
  $margenCompra = flus_compras_margenes_para_compra($pdo, $compraMargenId);
  $productosMargen = $margenCompra['productos'] ?? [];
  if ($productosMargen !== []) {
    $idsMargen = implode(',', array_map('intval', $margenCompra['ids'] ?? []));
    $preciosHref = 'precios_historial.php?v=herramientas&ids=' . urlencode($idsMargen);
    ?>
    <section class="msg msg-visible msg-info" style="margin-top:16px;">
      <strong>Margenes de la compra #<?= (int)$compraMargenId ?></strong>
      <div style="margin-top:6px;">
        <?= (int)count($productosMargen) ?> producto(s) impactados.
        <?= (int)($margenCompra['bajos'] ?? 0) ?> quedaron con margen menor a 20%.
        <?= (int)($margenCompra['negativos'] ?? 0) ?> quedaron con margen negativo.
      </div>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn btn-secondary btn-compact" href="<?= h($preciosHref) ?>">Revisar y aplicar margen</a>
        <a class="btn btn-secondary btn-compact" href="precios_historial.php?v=historial&tipo=COSTO">Ver historial de costos</a>
      </div>
    </section>
    <?php
  }
}
