<?php
// public/promos.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('editar_promos');

/* --------------------------------------------------------
   1) Traer promos (1 fila por promo)
-------------------------------------------------------- */
$sqlPromos = "
  SELECT
    id,
    nombre,
    tipo,
    fecha_inicio,
    fecha_fin,
    activo,
    precio_combo
  FROM promos
  ORDER BY id DESC
";
$promosBase = $pdo->query($sqlPromos)->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* --------------------------------------------------------
   2) Traer items asociados
-------------------------------------------------------- */
$promoIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $promosBase), fn($id) => $id > 0));

$itemsSimpleByPromo = [];
$itemsComboByPromo  = [];

if ($promoIds) {
  $ph = implode(',', array_fill(0, count($promoIds), '?'));

  // Simples (NxM / NTH%)
  $sqlItemsSimples = "
    SELECT
      pp.promo_id,
      pr.codigo AS prod_codigo,
      pr.nombre AS prod_nombre,
      pp.n,
      pp.m,
      pp.porcentaje
    FROM promo_productos pp
    LEFT JOIN productos pr ON pr.id = pp.producto_id
    WHERE pp.promo_id IN ($ph)
    ORDER BY pp.promo_id ASC, pr.nombre ASC
  ";
  $st1 = $pdo->prepare($sqlItemsSimples);
  $st1->execute($promoIds);
  foreach (($st1->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $pid = (int)($row['promo_id'] ?? 0);
    if ($pid > 0) $itemsSimpleByPromo[$pid][] = $row;
  }

  // Combos (COMBO_FIJO)
  $sqlItemsCombos = "
    SELECT
      pci.promo_id,
      pr.codigo AS prod_codigo,
      pr.nombre AS prod_nombre,
      pci.cantidad_requerida AS cantidad
    FROM promo_combo_items pci
    LEFT JOIN productos pr ON pr.id = pci.producto_id
    WHERE pci.promo_id IN ($ph)
    ORDER BY pci.promo_id ASC, pr.nombre ASC
  ";
  $st2 = $pdo->prepare($sqlItemsCombos);
  $st2->execute($promoIds);
  foreach (($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $pid = (int)($row['promo_id'] ?? 0);
    if ($pid > 0) $itemsComboByPromo[$pid][] = $row;
  }
}

// Contadores para stats
$totalPromos = count($promosBase);
$activasCount = count(array_filter($promosBase, fn($p) => (int)($p['activo'] ?? 0) === 1));
$inactivasCount = $totalPromos - $activasCount;

/* --------------------------------------------------------
   Header
-------------------------------------------------------- */
$pageTitle      = 'Promociones';
$currentSection = 'promos';
$extraCss       = ['assets/css/promos.css?v=6'];
$extraJs        = ['assets/js/promos.js?v=3'];

require __DIR__ . '/partials/header.php';
?>
<div class="promos-page">
<div id="promos-page" data-csrf="<?= h(csrf_token()) ?>">
  <div class="panel panel-promos">

    <!-- HEADER -->
    <div class="panel-header page-header module-header">
      <div class="page-header-main module-header-main">
        <div class="panel-title-group module-header-hero">
          <span class="panel-icon">🏷️</span>
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="m20.59 13.41-7.18 7.18a2 2 0 0 1-2.82 0L2 12V4h8l8.59 8.59a2 2 0 0 1 0 2.82Z"/>
              <circle cx="7" cy="7" r="1.5"/>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="module-eyebrow">Motor comercial</span>
            <h1 class="panel-title page-title">Promociones</h1>
            <p class="panel-sub page-sub">Crea promociones que caja detecta al cargar productos y que quedan reflejadas en la venta y en el ticket.</p>
          </div>
        </div>
      </div>

      <div class="promo-actions-top module-header-actions">
        <a href="promo_builder.php" class="v-btn v-btn--primary">+ Nueva promocion</a>
      </div>
    </div>

    <!-- STATS (ARRIBA) -->
    <div class="promo-stats">
      <div class="stat-card">
        <div class="stat-label">Total</div>
        <div class="stat-value"><?= $totalPromos ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Activas</div>
        <div class="stat-value stat-value--success"><?= $activasCount ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Inactivas</div>
        <div class="stat-value stat-value--muted"><?= $inactivasCount ?></div>
      </div>
    </div>

    <!-- FILTROS -->
    <div class="promo-filters">
      <div class="filters-grid">
        <div class="field">
          <label for="filtroTexto">Buscar</label>
          <input type="text" id="filtroTexto" class="input" placeholder="Nombre, producto, código...">
        </div>

        <div class="field">
          <label for="filtroTipo">Tipo</label>
          <select id="filtroTipo" class="input">
            <option value="">Todos</option>
            <option value="N_PAGA_M">NxM</option>
            <option value="NTH_PCT">% Unidad</option>
            <option value="COMBO_FIJO">Combo</option>
          </select>
        </div>

        <div class="field">
          <label for="filtroEstado">Estado</label>
          <select id="filtroEstado" class="input">
            <option value="">Todos</option>
            <option value="activa">Activas</option>
            <option value="inactiva">Inactivas</option>
          </select>
        </div>
      </div>
    </div>

    <!-- TABLA -->
    <div class="table-wrapper">
      <table class="promo-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Nombre</th>
            <th>Tipo</th>
            <th>Producto / Items</th>
            <th>Parámetros</th>
            <th>Vigencia</th>
            <th>Estado</th>
            <th class="t-right">Acciones</th>
          </tr>
        </thead>

        <tbody>
          <?php if (!$promosBase): ?>
            <tr>
              <td colspan="8" class="empty-cell">No hay promociones cargadas</td>
            </tr>
          <?php else: ?>
            <?php $nro = 0; foreach ($promosBase as $p): $nro++; ?>
              <?php
                $id     = (int)($p['id'] ?? 0);
                $tipo   = (string)($p['tipo'] ?? '');
                $activa = ((int)($p['activo'] ?? 0) === 1);

                $desde = !empty($p['fecha_inicio']) ? date('d/m/y', strtotime((string)$p['fecha_inicio'])) : '';
                $hasta = !empty($p['fecha_fin']) ? date('d/m/y', strtotime((string)$p['fecha_fin'])) : '';
                if ($desde !== '' && $hasta !== '') {
                  $vigenciaLabel = $desde . ' -> ' . $hasta;
                  $vigenciaClass = '';
                } elseif ($desde !== '') {
                  $vigenciaLabel = 'Desde ' . $desde;
                  $vigenciaClass = '';
                } elseif ($hasta !== '') {
                  $vigenciaLabel = 'Hasta ' . $hasta;
                  $vigenciaClass = '';
                } else {
                  $vigenciaLabel = 'Sin vigencia';
                  $vigenciaClass = ' promo-date--empty';
                }

                $items = ($tipo === 'COMBO_FIJO')
                  ? ($itemsComboByPromo[$id] ?? [])
                  : ($itemsSimpleByPromo[$id] ?? []);

                // Label Producto/Items
                if ($tipo === 'COMBO_FIJO') {
                  $cnt = count($items);
                  if ($cnt <= 0) {
                    $prodLabel = '—';
                  } else {
                    $parts = [];
                    foreach (array_slice($items, 0, 2) as $it) {
                      $nom  = trim((string)($it['prod_nombre'] ?? ''));
                      $cant = (float)($it['cantidad'] ?? 0);
                      $cantTxt = rtrim(rtrim(number_format($cant, 1, '.', ''), '0'), '.');
                      $parts[] = $nom . ' ×' . $cantTxt;
                    }
                    $extra = $cnt > 2 ? ' +'.($cnt - 2).' más' : '';
                    $prodLabel = implode(', ', $parts) . $extra;
                  }
                } else {
                  $it0 = $items[0] ?? null;
                  if ($it0 && !empty($it0['prod_nombre'])) {
                    $prodLabel = (string)($it0['prod_nombre'] ?? '');
                  } else {
                    $prodLabel = '—';
                  }
                }

                // Parámetros
                $paramsLabel = '—';
                $it0 = $items[0] ?? null;

                if ($tipo === 'N_PAGA_M' && $it0) {
                  $n = (int)($it0['n'] ?? 0);
                  $m = (int)($it0['m'] ?? 0);
                  $paramsLabel = 'Llevás <strong>'.$n.'</strong>, pagás <strong>'.$m.'</strong>';
                } elseif ($tipo === 'NTH_PCT' && $it0) {
                  $pct = (float)($it0['porcentaje'] ?? 0);
                  $nn  = (int)($it0['n'] ?? 0);
                  $pctTxt = rtrim(rtrim(number_format($pct, 0), '0'), '.');
                  $paramsLabel = '<strong>'.$pctTxt.'%</strong> en la <strong>'.$nn.'°</strong> unidad';
                } elseif ($tipo === 'COMBO_FIJO') {
                  $paramsLabel = 'Precio: <strong>' . h(money_ar((float)($p['precio_combo'] ?? 0))) . '</strong>';
                }

                // Badge tipo
                $tipoLabel = match($tipo) {
                  'N_PAGA_M' => 'NxM',
                  'NTH_PCT' => '% Unidad',
                  'COMBO_FIJO' => 'Combo',
                  default => $tipo
                };
                
                $tipoBadgeClass = match($tipo) {
                  'N_PAGA_M' => 'badge-nxm',
                  'NTH_PCT' => 'badge-nth',
                  'COMBO_FIJO' => 'badge-combo',
                  default => 'badge-otro'
                };
              ?>

              <tr
                class="promo-row"
                data-id="<?= (int)$id ?>"
                data-tipo="<?= h($tipo) ?>"
                data-estado="<?= $activa ? 'activa' : 'inactiva' ?>"
              >
                <td><?= (int)$nro ?></td>

                <td><span class="promo-name"><?= h($p['nombre'] ?? '') ?></span></td>

                <td>
                  <span class="badge <?= $tipoBadgeClass ?>"><?= h($tipoLabel) ?></span>
                </td>

                <td class="promo-prod"><?= h($prodLabel) ?></td>

                <td class="promo-params"><?= $paramsLabel ?></td>

                <td class="promo-date<?= $vigenciaClass ?>"><?= h($vigenciaLabel) ?></td>

                <td>
                  <span class="badge <?= $activa ? 'badge-activa' : 'badge-inactiva' ?>">
                    <?= $activa ? 'Activa' : 'Inactiva' ?>
                  </span>
                </td>

                <td class="t-right actions-cell">
                  <div class="actions-desktop">
                    <button type="button" class="btn-mini btn-mini-ok btn-edit-promo" data-id="<?= (int)$id ?>">Editar</button>
                    <button type="button" class="btn-mini btn-mini-danger js-delete-promo" data-id="<?= (int)$id ?>">Eliminar</button>
                  </div>
                  <div class="actions-mobile">
                    <button type="button" class="btn-mini btn-mini-ok btn-edit-promo" data-id="<?= (int)$id ?>">Editar</button>
                    <button type="button" class="btn-mini btn-mini-danger js-delete-promo" data-id="<?= (int)$id ?>">Eliminar</button>
                  </div>
                </td>
              </tr>

            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>
</div>

<!-- PANEL LATERAL EDICIÓN RÁPIDA -->
<div class="promo-overlay" id="promoEditOverlay">
  <div class="promo-panel">
    <div class="promo-panel-header">
      <h2 id="promoEditTitle">Editar promo</h2>
      <button type="button" class="panel-close-btn" id="promoCloseBtn">&times;</button>
    </div>

    <div class="promo-panel-content">
      <form id="promoEditForm" autocomplete="off">
        <input type="hidden" name="id" id="promoId">

        <div class="promo-panel-intro">
          <div class="promo-panel-intro-title">Edicion rapida</div>
          <p class="promo-panel-intro-copy">Actualiza los datos principales sin salir del listado.</p>
        </div>

        <div class="promo-panel-section">
          <div class="promo-panel-section-title">Datos principales</div>

          <label for="promoNombre">Nombre</label>
          <input type="text" name="nombre" id="promoNombre" class="input" required>

          <label for="promoTipo">Tipo</label>
          <select name="tipo" id="promoTipo" class="input" disabled>
            <option value="N_PAGA_M">NxM</option>
            <option value="NTH_PCT">% Unidad</option>
            <option value="COMBO_FIJO">Combo</option>
          </select>
        </div>

        <div id="promoSimplesFields">
          <div class="promo-panel-section">
            <div class="promo-panel-section-title">Como se aplica</div>

            <label for="promoProducto">Producto</label>
            <select name="producto_id" id="promoProducto" class="input"></select>

            <label for="promoN">Cantidad que lleva</label>
            <input type="number" name="n" id="promoN" class="input" min="1">

            <label for="promoM">Cantidad que paga</label>
            <input type="number" name="m" id="promoM" class="input" min="1">

            <label for="promoPct">Descuento %</label>
            <input type="number" name="porcentaje" id="promoPct" class="input" min="1" max="100">
          </div>
        </div>

        <div id="promoComboFields" style="display:none;">
          <div class="promo-panel-section">
            <div class="promo-panel-section-title">Configuracion del combo</div>

            <label for="comboPrecio">Precio final</label>
            <input type="number" name="precio_combo" id="comboPrecio" class="input" step="0.01" min="0">

            <label>Productos incluidos</label>
            <div id="comboItemsContainer"></div>
            <button type="button" class="btn-small" id="btnAddComboItem">+ Agregar item</button>
          </div>
        </div>

        <button type="submit" class="btn-save">Guardar cambios</button>
      </form>
    </div>
  </div>
</div>

<!-- MODAL ELIMINAR -->
<div class="modal-overlay" id="modalEliminarPromo">
  <div class="modal-box">
    <div class="modal-title">Eliminar promoción</div>
    <p class="modal-text">
      ¿Seguro que querés eliminar "<strong id="delPromoName"></strong>"?
      <small>Esta acción no se puede deshacer.</small>
    </p>
    <div class="modal-actions">
      <button type="button" class="modal-btn-cancel" id="btnCancelarEliminarPromo">Cancelar</button>
      <button type="button" class="modal-btn-danger" id="btnConfirmarEliminarPromo">Eliminar</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>



