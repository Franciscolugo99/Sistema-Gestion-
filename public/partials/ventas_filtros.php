<?php
// partials/ventas_filtros.php

$estado = $estado ?? '';
?>
<form method="get" class="ventas-filters" id="ventasFilters">

  <div class="filters-grid">

    <!-- MEDIO DE PAGO -->
    <div class="field">
      <label for="medio">Medio</label>
      <select name="medio" id="medio">
        <option value="">(Todos)</option>
        <?php foreach ($allowedMedios as $m): ?>
          <option value="<?= h((string)$m) ?>" <?= ($medio === $m) ? 'selected' : '' ?>>
            <?= h((string)$m) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- ESTADO -->
    <div class="field">
      <label for="estado">Estado</label>
      <select name="estado" id="estado">
        <option value="" <?= ($estado === '') ? 'selected' : '' ?>>(Todas)</option>
        <option value="EMITIDA" <?= ($estado === 'EMITIDA') ? 'selected' : '' ?>>Emitidas</option>
        <option value="ANULADA" <?= ($estado === 'ANULADA') ? 'selected' : '' ?>>Anuladas</option>
      </select>
    </div>

    <!-- FECHA DESDE -->
    <div class="field">
      <label for="desde">Desde</label>
      <input type="date" name="desde" id="desde" value="<?= h((string)($desde ?? '')) ?>">
    </div>

    <!-- FECHA HASTA -->
    <div class="field">
      <label for="hasta">Hasta</label>
      <input type="date" name="hasta" id="hasta" value="<?= h((string)($hasta ?? '')) ?>">
    </div>

    <!-- POR PÁGINA -->
    <div class="field">
      <label for="per_page">Mostrar</label>
      <select name="per_page" id="per_page">
        <?php foreach ([20,50,100] as $n): ?>
          <option value="<?= (int)$n ?>" <?= ((int)$perPage === (int)$n) ? 'selected' : '' ?>>
            <?= (int)$n ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- ACCIONES -->
    <div class="field field-actions">
      <button type="submit" class="v-btn v-btn--primary">Filtrar</button>
      <a href="ventas.php" id="ventasClear" class="v-btn v-btn--ghost">Limpiar</a>
    </div>

    <!-- RÁPIDOS -->
    <div class="field field-quick">
      <label>Rápido</label>
      <div class="quick">
        <button type="button" class="chip" data-range="today">Hoy</button>
        <button type="button" class="chip" data-range="7d">7 días</button>
        <button type="button" class="chip" data-range="30d">30 días</button>
      </div>
    </div>

    <!-- FILTRO: ID VENTA -->
    <div class="field">
      <label for="venta_id">ID venta</label>
      <input
        type="number"
        min="1"
        name="venta_id"
        id="venta_id"
        placeholder="Ej: 29"
        value="<?= h((string)$venta_id) ?>"
      >
    </div>

    <!-- MIN TOTAL (acepta AR) -->
    <div class="field">
      <label for="min_total">Min total</label>
      <input
        type="text"
        inputmode="decimal"
        name="min_total"
        id="min_total"
        placeholder="Ej: 1.234,56"
        value="<?= h((string)($min_total_raw ?? '')) ?>"
      >
    </div>

    <!-- MAX TOTAL (acepta AR) -->
    <div class="field">
      <label for="max_total">Max total</label>
      <input
        type="text"
        inputmode="decimal"
        name="max_total"
        id="max_total"
        placeholder="Ej: 9.999,99"
        value="<?= h((string)($max_total_raw ?? '')) ?>"
      >
    </div>

  </div>

  <input type="hidden" name="page" id="page" value="<?= (int)$page ?>">
</form>
