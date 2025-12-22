<?php
// public/promos.php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_permission('editar_productos');
require_login();
$pdo = getPDO();

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
      - Simples: promo_productos (1 producto)
      - Combos : promo_combo_items (varios productos)
-------------------------------------------------------- */
$promoIds = array_map(fn($r) => (int)($r['id'] ?? 0), $promosBase);
$promoIds = array_values(array_filter($promoIds, fn($id) => $id > 0));

$itemsSimpleByPromo = [];
$itemsComboByPromo  = [];

if ($promoIds) {
  $ph = implode(',', array_fill(0, count($promoIds), '?'));

  // --- Simples (NxM / NTH%) ---
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

  // --- Combos (COMBO_FIJO) ---
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

/* --------------------------------------------------------
   METADATOS PARA HEADER GLOBAL
-------------------------------------------------------- */
$pageTitle      = 'Promociones';
$currentSection = 'promos';
$extraCss       = ['assets/css/promos.css?v=1'];
$extraJs        = ['assets/js/promos.js?v=1'];

require __DIR__ . '/partials/header.php';
?>

<div class="root container-global" id="promos-page" data-csrf="<?= h(csrf_token()) ?>">
  <div class="panel panel-promos">

    <!-- HEADER -->
    <div class="panel-header">
      <div>
        <h1 class="panel-title">Promociones</h1>
        <p class="panel-sub">Definí y gestioná promociones aplicadas automáticamente en caja.</p>
      </div>

      <div class="promo-actions-top">
        <a href="promo_form.php" class="v-btn v-btn--primary">+ Nueva promo</a>
        <a href="promo_combo_form.php" class="v-btn v-btn--outline">+ Nuevo combo fijo</a>
      </div>
    </div>

    <!-- FILTROS -->
    <div class="promo-filters">
      <div class="filters-grid">
        <div class="field">
          <label for="filtroTexto">Buscar</label>
          <input type="text" id="filtroTexto" class="input" placeholder="Nombre, producto, código…">
        </div>

        <div class="field">
          <label for="filtroTipo">Tipo</label>
          <select id="filtroTipo" class="input">
            <option value="">Todos</option>
            <option value="N_PAGA_M">NxM</option>
            <option value="NTH_PCT">% a la N°</option>
            <option value="COMBO_FIJO">Combo fijo</option>
          </select>
        </div>

        <div class="field">
          <label for="filtroEstado">Estado</label>
          <select id="filtroEstado" class="input">
            <option value="">Todas</option>
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
              <td colspan="8" class="empty-cell">No hay promociones cargadas aún.</td>
            </tr>
          <?php else: ?>
            <?php $nro = 0; foreach ($promosBase as $p): $nro++; ?>
              <?php
                $id     = (int)($p['id'] ?? 0);
                $tipo   = (string)($p['tipo'] ?? '');
                $activa = ((int)($p['activo'] ?? 0) === 1);

                $desde = !empty($p['fecha_inicio']) ? date('d/m/Y', strtotime((string)$p['fecha_inicio'])) : '—';
                $hasta = !empty($p['fecha_fin'])    ? date('d/m/Y', strtotime((string)$p['fecha_fin']))    : '—';

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
                      $cod  = trim((string)($it['prod_codigo'] ?? ''));
                      $nom  = trim((string)($it['prod_nombre'] ?? ''));
                      $cant = (float)($it['cantidad'] ?? 0);

                      // 1 / 1.5 / 0.250
                      $cantTxt = rtrim(rtrim(number_format($cant, 3, '.', ''), '0'), '.');

                      $parts[] = ($cod !== '' ? "[$cod] " : '') . $nom . " x{$cantTxt}";
                    }
                    $extra = $cnt > 2 ? " +".($cnt - 2) : "";
                    $prodLabel = $cnt . " productos: " . implode(', ', $parts) . $extra;
                  }
                } else {
                  $it0 = $items[0] ?? null;
                  if ($it0 && (!empty($it0['prod_codigo']) || !empty($it0['prod_nombre']))) {
                    $prodLabel = '[' . (string)($it0['prod_codigo'] ?? '') . '] ' . (string)($it0['prod_nombre'] ?? '');
                  } else {
                    $prodLabel = '—';
                  }
                }

                // Parámetros
                $paramsLabel = '—';
                $it0 = $items[0] ?? null;

                if ($tipo === 'N_PAGA_M' && $it0) {
                  $paramsLabel = 'Llevás <strong>'.(int)($it0['n'] ?? 0).'</strong> y pagás <strong>'.(int)($it0['m'] ?? 0).'</strong>';
                } elseif ($tipo === 'NTH_PCT' && $it0) {
                  $pct = (float)($it0['porcentaje'] ?? 0);
                  $nn  = (int)($it0['n'] ?? 0);
                  $pctTxt = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
                  $paramsLabel = '<strong>'.$pctTxt.'%</strong> en la <strong>'.$nn.'°</strong> unidad';
                } elseif ($tipo === 'COMBO_FIJO') {
                  $paramsLabel = '<span class="muted">Precio combo: <strong>' . h(money_ar((float)($p['precio_combo'] ?? 0))) . '</strong></span>';
                }
              ?>

              <tr
                class="promo-row"
                data-id="<?= (int)$id ?>"
                data-tipo="<?= h($tipo) ?>"
                data-estado="<?= $activa ? 'activa' : 'inactiva' ?>"
              >
                <td><?= (int)$nro ?></td>

                <td class="promo-name"><?= h($p['nombre'] ?? '') ?></td>

                <td>
                  <?php if ($tipo === 'N_PAGA_M'): ?>
                    <span class="badge badge-nxm">NxM</span>
                  <?php elseif ($tipo === 'NTH_PCT'): ?>
                    <span class="badge badge-nth">% a la N°</span>
                  <?php elseif ($tipo === 'COMBO_FIJO'): ?>
                    <span class="badge badge-combo">Combo fijo</span>
                  <?php else: ?>
                    <span class="badge badge-otro"><?= h($tipo) ?></span>
                  <?php endif; ?>
                </td>

                <td class="promo-prod"><?= h($prodLabel) ?></td>

                <td class="promo-params"><?= $paramsLabel ?></td>

                <td class="promo-date">
                  <?= h($desde) ?> <span class="dot">→</span> <?= h($hasta) ?>
                </td>

                <td>
                  <span class="badge <?= $activa ? 'badge-activa' : 'badge-inactiva' ?>">
                    <?= $activa ? 'Activa' : 'Inactiva' ?>
                  </span>
                </td>

                <td class="t-right">
                  <button
                    type="button"
                    class="btn-mini btn-mini-ok btn-edit-promo"
                    data-id="<?= (int)$id ?>"
                  >Editar</button>

                  <button
                    type="button"
                    class="btn-mini btn-mini-ghost js-delete-promo"
                    data-id="<?= (int)$id ?>"
                    data-nombre="<?= h($p['nombre'] ?? '') ?>"
                  >Eliminar</button>
                </td>
              </tr>

            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<!-- PANEL LATERAL EDITAR PROMO -->
<div id="promoEditOverlay" class="promo-overlay">
  <div class="promo-panel">

    <div class="promo-panel-header">
      <h2 id="promoEditTitle">Editar promoción</h2>
      <button id="promoCloseBtn" class="panel-close-btn" type="button">×</button>
    </div>

    <div class="promo-panel-content">
      <form id="promoEditForm">
        <label for="promoNombre">Nombre</label>
        <input type="text" id="promoNombre" autocomplete="off">

        <label for="promoTipo">Tipo</label>
        <select id="promoTipo" disabled>
          <option value="N_PAGA_M">N paga M</option>
          <option value="NTH_PCT">% en N°</option>
          <option value="COMBO_FIJO">Combo fijo</option>
        </select>

        <div id="promoSimplesFields">
          <label for="promoProducto">Producto</label>
          <select id="promoProducto"></select>

          <label for="promoN">N (cada cuántas unidades)</label>
          <input type="number" id="promoN" min="1">

          <label for="promoM">M (cuántas paga)</label>
          <input type="number" id="promoM" min="1">

          <label for="promoPct">Porcentaje</label>
          <input type="number" id="promoPct" min="0" max="100" step="0.1">
        </div>

        <div id="promoComboFields" style="display:none;">
          <label for="comboPrecio">Precio combo</label>
          <input type="number" id="comboPrecio" min="0" step="0.01">

          <div id="comboItemsContainer"></div>

          <button type="button" id="btnAddComboItem" class="btn-small">
            Agregar producto
          </button>
        </div>

        <button type="submit" class="btn-save">Guardar cambios</button>
      </form>
    </div>

  </div>
</div>

<!-- MODAL ELIMINAR PROMO -->
<div id="modalEliminarPromo" class="modal-overlay">
  <div class="modal-box">
    <h2 class="modal-title">ELIMINAR PROMOCIÓN</h2>
    <p class="modal-text">¿Seguro que querés eliminar esta promo?</p>

    <div class="modal-actions">
      <button id="btnCancelarEliminarPromo" class="modal-btn-cancel" type="button">Cancelar</button>
      <button id="btnConfirmarEliminarPromo" class="modal-btn-danger" type="button">Sí, eliminar</button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div id="promoToast" class="promo-toast"></div>

<?php require __DIR__ . '/partials/footer.php'; ?>
