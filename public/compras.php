<?php
// public/compras.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();


$msg = '';
$savedFlag = (string)($_GET['saved'] ?? '');

/* -----------------------------
   Helpers
------------------------------ */


/* -----------------------------
   POST actions
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $msg = 'Token CSRF inválido. Recargá y probá de nuevo.';
  } else {

    $accion = (string)($_POST['accion'] ?? '');

    /* =========================================================
       1) Guardar BORRADOR
    ========================================================= */
    if ($accion === 'guardar_borrador') {

      $proveedorTxt = trim((string)($_POST['proveedor'] ?? ''));
      $tipoComp     = trim((string)($_POST['tipo_comp'] ?? ''));
      $nroComp      = trim((string)($_POST['nro_comp'] ?? ''));
      $observacion  = trim((string)($_POST['observacion'] ?? ''));

      $prodIds = $_POST['producto_id'] ?? [];
      $cants   = $_POST['cantidad'] ?? [];
      $costos  = $_POST['costo_unitario'] ?? [];

      if ($proveedorTxt === '') {
        $msg = 'Proveedor es obligatorio.';
      } elseif (!is_array($prodIds) || count($prodIds) === 0) {
        $msg = 'Agregá al menos 1 ítem.';
      }

      // Armar items + total
      $items = [];
      $total = 0.0;

      if ($msg === '') {
        $n = count($prodIds);
        for ($i = 0; $i < $n; $i++) {
          $pid = (int)($prodIds[$i] ?? 0);
          if ($pid <= 0) continue;

          $qty = parse_decimal((string)($cants[$i] ?? ''), 0.0);
          $cu  = parse_decimal((string)($costos[$i] ?? ''), 0.0);

          if ($qty <= 0) { $msg = 'Cantidad inválida en un ítem.'; break; }
          if ($cu < 0)   { $msg = 'Costo unitario inválido en un ítem.'; break; }

          $sub = $qty * $cu;
          $total += $sub;

          $items[] = [
            'producto_id'    => $pid,
            'cantidad'       => $qty,
            'costo_unitario' => $cu,
            'subtotal'       => $sub
          ];
        }

        if ($msg === '' && count($items) === 0) {
          $msg = 'Agregá al menos 1 ítem válido.';
        }
      }

      if ($msg === '') {
        try {
          $pdo->beginTransaction();

          // proveedor_id (buscar o crear). OJO: proveedores NO tiene updated_at en tu BD.
          $stFind = $pdo->prepare("SELECT id FROM proveedores WHERE nombre = ? LIMIT 1");
          $stFind->execute([$proveedorTxt]);
          $proveedorId = (int)($stFind->fetchColumn() ?: 0);

          if ($proveedorId <= 0) {
            $stInsProv = $pdo->prepare("
              INSERT INTO proveedores (nombre, activo)
              VALUES (?, 1)
            ");
            $stInsProv->execute([$proveedorTxt]);
            $proveedorId = (int)$pdo->lastInsertId();
          }

          // compras: tu tabla tiene total_neto, total_iva, total, obs, created_at (NO updated_at)
          $totalNeto = $total;
          $totalIva  = 0.0;

          $stCompra = $pdo->prepare("
            INSERT INTO compras
              (fecha, proveedor_id, tipo_comp, nro_comp, obs, estado, total_neto, total_iva, total)
            VALUES
              (CURDATE(), :proveedor_id, :tipo_comp, :nro_comp, :obs, 'BORRADOR', :total_neto, :total_iva, :total)
          ");
          $stCompra->execute([
            ':proveedor_id' => $proveedorId,
            ':tipo_comp'    => $tipoComp,
            ':nro_comp'     => $nroComp,
            ':obs'          => $observacion,
            ':total_neto'   => $totalNeto,
            ':total_iva'    => $totalIva,
            ':total'        => $total,
          ]);

          $compraId = (int)$pdo->lastInsertId();

          // compra_items: NO tiene created_at en tu BD
          $stItem = $pdo->prepare("
            INSERT INTO compra_items
              (compra_id, producto_id, cantidad, costo_unitario, subtotal, comentario)
            VALUES
              (:compra_id, :producto_id, :cantidad, :costo_unitario, :subtotal, :comentario)
          ");

          foreach ($items as $it) {
            $stItem->execute([
              ':compra_id'      => $compraId,
              ':producto_id'    => $it['producto_id'],
              ':cantidad'       => $it['cantidad'],
              ':costo_unitario' => $it['costo_unitario'],
              ':subtotal'       => $it['subtotal'],
              ':comentario'     => '',
            ]);
          }

          $pdo->commit();

          header("Location: compras.php?saved=created");
          exit;

        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $msg = "Error al guardar: " . $e->getMessage();
        }
      }
    }

    /* =========================================================
       2) Confirmar (impacta stock + movimientos)
    ========================================================= */
    if ($accion === 'confirmar' && $msg === '') {

      $compraId = (int)($_POST['compra_id'] ?? 0);
      if ($compraId <= 0) {
        $msg = "ID inválido.";
      } else {
        try {
          $pdo->beginTransaction();

          // Bloquear compra
          $st = $pdo->prepare("SELECT estado FROM compras WHERE id = ? FOR UPDATE");
          $st->execute([$compraId]);
          $estado = (string)($st->fetchColumn() ?: '');

          if ($estado !== 'BORRADOR') {
            throw new RuntimeException("La compra no está en BORRADOR.");
          }

          // Traer items
          $itSt = $pdo->prepare("
            SELECT producto_id, cantidad, costo_unitario
            FROM compra_items
            WHERE compra_id = ?
          ");
          $itSt->execute([$compraId]);
          $items = $itSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

          if (!$items) throw new RuntimeException("La compra no tiene ítems.");

          // Impactar stock + movimientos (tu tabla movimientos_stock tiene referencia_compra_id)
          $stUpdStock = $pdo->prepare("UPDATE productos SET stock = stock + :qty WHERE id = :pid");
          $stMov = $pdo->prepare("
            INSERT INTO movimientos_stock
              (fecha, producto_id, tipo, cantidad, referencia_venta_id, referencia_compra_id, comentario)
            VALUES
              (NOW(), :pid, 'COMPRA', :qty, NULL, :compra_id, :com)
          ");
          $stUpdCosto = $pdo->prepare("UPDATE productos SET costo = :costo WHERE id = :pid");

          foreach ($items as $it) {
            $pid = (int)$it['producto_id'];
            $qty = (float)$it['cantidad'];
            $cu  = (float)$it['costo_unitario'];

            if ($qty <= 0) continue;

            $stUpdStock->execute([':qty' => $qty, ':pid' => $pid]);
            $stMov->execute([
              ':pid'      => $pid,
              ':qty'      => $qty,
              ':compra_id'=> $compraId,
              ':com'      => "Compra #{$compraId}",
            ]);

            // Opcional: guardar último costo en producto
            if ($cu > 0) {
              $stUpdCosto->execute([':costo' => $cu, ':pid' => $pid]);
            }
          }

          // Confirmar compra (tu compras NO tiene updated_at)
          $pdo->prepare("UPDATE compras SET estado='CONFIRMADA' WHERE id=?")->execute([$compraId]);

          $pdo->commit();

          header("Location: compras.php?saved=confirmed");
          exit;

        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $msg = "Error al confirmar: " . $e->getMessage();
        }
      }
    }
  }
}

/* -----------------------------
   Datos para UI
------------------------------ */

// Productos (select items)
$prodStmt = $pdo->query("
  SELECT id, codigo, nombre, es_pesable, unidad_venta, costo
  FROM productos
  WHERE activo = 1
  ORDER BY nombre
");
$productos = $prodStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Listado + filtros
$q      = trim((string)($_GET['q'] ?? ''));
$estado = (string)($_GET['estado'] ?? '');
$desde  = validDateYmd((string)($_GET['desde'] ?? ''));
$hasta  = validDateYmd((string)($_GET['hasta'] ?? ''));

$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20,50,100], true)) $perPage = 50;

$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($q !== '') {
  $where[] = "(p.nombre LIKE :q OR c.tipo_comp LIKE :q OR c.nro_comp LIKE :q OR c.id = :idExact)";
  $params[':q'] = "%{$q}%";
  $params[':idExact'] = ctype_digit($q) ? (int)$q : -1;
}

$allowedEstados = ['BORRADOR','CONFIRMADA','ANULADA'];
if ($estado !== '' && in_array($estado, $allowedEstados, true)) {
  $where[] = "c.estado = :estado";
  $params[':estado'] = $estado;
}

if ($desde) {
  $where[] = "c.fecha >= :desde";
  $params[':desde'] = $desde;
}

if ($hasta) {
  $where[] = "c.fecha <= :hasta";
  $params[':hasta'] = $hasta;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Count
$stCount = $pdo->prepare("
  SELECT COUNT(*)
  FROM compras c
  LEFT JOIN proveedores p ON p.id = c.proveedor_id
  {$whereSql}
");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page-1)*$perPage; }

// List
$stList = $pdo->prepare("
  SELECT c.*, p.nombre AS proveedor_nombre
  FROM compras c
  LEFT JOIN proveedores p ON p.id = c.proveedor_id
  {$whereSql}
  ORDER BY c.id DESC
  LIMIT :lim OFFSET :off
");
foreach ($params as $k=>$v) $stList->bindValue($k, $v);
$stList->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stList->bindValue(':off', $offset, PDO::PARAM_INT);
$stList->execute();
$compras = $stList->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* -----------------------------
   Header
------------------------------ */
$pageTitle = "Compras";
$currentSection = "compras";
$extraCss = ["assets/css/compras.css"];
$extraJs  = ["assets/js/compras.js"];
require __DIR__ . "/partials/header.php";
?>

<!-- FORMULARIO MEJORADO -->
 <div class="compras-page">
<div class="panel">
  <header class="page-header">
    <div>
      <h1 class="page-title">✨ Compras</h1>
      <p class="page-sub">Buscá productos con autocomplete, editá items en línea y confirmá para impactar stock.</p>
    </div>
  </header>

  <form method="post" id="compraForm" class="compras-form">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar_borrador">

    <!-- Select oculto con data para JS -->
    <select id="productosData" style="display:none;">
      <option value="">--</option>
      <?php foreach ($productos as $p): ?>
        <option
          value="<?= (int)$p['id'] ?>"
          data-codigo="<?= h((string)$p['codigo']) ?>"
          data-es-pesable="<?= (int)($p['es_pesable'] ?? 0) ?>"
          data-unidad="<?= h((string)($p['unidad_venta'] ?? 'UNIDAD')) ?>"
          data-ultimo-costo="<?= (float)($p['costo'] ?? 0) ?>"
        >
          <?= h((string)$p['nombre']) ?> (<?= h((string)$p['codigo']) ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <div class="form-grid">
      <div class="field">
        <label>🏢 Proveedor</label>
        <input name="proveedor" placeholder="Ej: Mayorista X" required autocomplete="off">
      </div>

      <div class="field">
        <label>📄 Tipo comprobante</label>
        <input name="tipo_comp" placeholder="Ej: Factura A" autocomplete="off">
      </div>

      <div class="field">
        <label>🔢 Nro comprobante</label>
        <input name="nro_comp" placeholder="Ej: 0001-00001234" autocomplete="off">
      </div>

      <div class="field field-wide">
        <label>📝 Observación</label>
        <input name="observacion" placeholder="Notas internas (opcional)" autocomplete="off">
      </div>
    </div>

    <div class="hr"></div>

    <div class="items-grid">
      <div class="field field-wide">
        <label>🔍 Buscar producto</label>
        <div class="search-wrapper">
          <svg class="search-icon" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
            <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/>
          </svg>
          <input
            type="text"
            id="itemBuscar"
            placeholder="Escribe para buscar productos..."
            autocomplete="off"
          >
          <div class="suggestions-box" id="suggestions"></div>
        </div>
      </div>

      <div class="field">
        <label>📦 Cantidad</label>
        <input id="itemCantidad" type="number" step="0.001" min="0.001" value="1" autocomplete="off">
        <div class="help" id="itemUnidad">Unidad: UNIDAD</div>
      </div>

      <div class="field">
        <label>💵 Costo unitario</label>
        <input id="itemCosto" type="number" step="0.01" min="0" value="0" autocomplete="off">
      </div>

      <div class="field">
        <label>&nbsp;</label>
        <button type="button" class="btn btn-primary" id="btnAddItem">
          ➕ Agregar
        </button>
      </div>
    </div>

    <div class="table-wrapper">
      <table class="compras-table" id="itemsTable">
        <thead>
          <tr>
            <th>Producto</th>
            <th class="right">Cantidad</th>
            <th class="right">Costo unitario</th>
            <th class="right">Subtotal</th>
            <th class="center">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr class="empty-row">
            <td colspan="5" class="empty-cell">
              Todavía no agregaste ítems. Buscá un producto arriba para comenzar.
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" class="right"><strong>TOTAL</strong></td>
            <td class="right"><strong id="totalLbl">$0,00</strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="actions">
      <button class="btn btn-primary" type="submit">
        💾 Guardar borrador
      </button>
      <button class="btn btn-secondary" type="button" onclick="location.reload()">
        🔄 Limpiar todo
      </button>
    </div>

    <?php if ($msg): ?>
      <div class="msg msg-visible msg-info">
        <?= h($msg) ?>
      </div>
    <?php endif; ?>
  </form>
</div>

<!-- LISTADO MEJORADO -->
<div class="panel" style="margin-top:22px;">
  <h2 class="sub-title-page">📋 Listado de Compras</h2>

  <form method="get" class="filters">
    <div class="filters-left">
      <input
        type="text"
        name="q"
        placeholder="🔍 Buscar por proveedor, comprobante o ID..."
        value="<?= h($q) ?>"
        autocomplete="off"
      >
    </div>

    <div class="filters-right">
      <select name="estado">
        <option value="">Todos los estados</option>
        <?php foreach (['BORRADOR','CONFIRMADA','ANULADA'] as $e): ?>
          <option value="<?= $e ?>" <?= $estado===$e?'selected':'' ?>><?= $e ?></option>
        <?php endforeach; ?>
      </select>

      <input type="date" name="desde" value="<?= h($desde ?? '') ?>" title="Desde">
      <input type="date" name="hasta" value="<?= h($hasta ?? '') ?>" title="Hasta">

      <select name="per_page" title="Items por página">
        <?php foreach ([20,50,100] as $n): ?>
          <option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?> / pág</option>
        <?php endforeach; ?>
      </select>

      <input type="hidden" name="page" value="1">
      <button class="btn btn-filter" type="submit">Filtrar</button>
      <a class="btn btn-secondary" href="compras.php">Limpiar</a>
    </div>
  </form>

  <div class="table-wrapper">
    <table class="compras-list">
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha</th>
          <th>Proveedor</th>
          <th>Comprobante</th>
          <th>Estado</th>
          <th class="right">Total</th>
          <th class="center">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$compras): ?>
          <tr><td colspan="7" class="empty-cell">No hay compras con esos filtros.</td></tr>
        <?php else: foreach ($compras as $c): ?>
          <tr>
            <td><strong>#<?= (int)$c['id'] ?></strong></td>
            <td><?= date('d/m/Y', strtotime((string)$c['fecha'])) ?></td>
            <td><?= h((string)($c['proveedor_nombre'] ?? 'Sin nombre')) ?></td>
            <td>
              <div><?= h((string)$c['tipo_comp']) ?></div>
              <div class="muted"><?= h((string)$c['nro_comp']) ?></div>
            </td>
            <td>
              <span class="estado-badge estado-<?= h((string)$c['estado']) ?>">
                <?= h((string)$c['estado']) ?>
              </span>
            </td>
            <td class="right"><strong><?= money_ar((float)$c['total']) ?></strong></td>
            <td class="center">
              <?php if ((string)$c['estado'] === 'BORRADOR'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('¿Confirmar esta compra? Se actualizará el stock.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="confirmar">
                  <input type="hidden" name="compra_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-primary" type="submit" style="font-size:.82rem;padding:6px 12px;">
                    ✅ Confirmar
                  </button>
                </form>
              <?php elseif ((string)$c['estado'] === 'CONFIRMADA'): ?>
                <span style="opacity:.6;font-size:.82rem;">✓ Confirmada</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <div class="pagination-info">
        Mostrando <strong><?= $totalRows ? ($offset + 1) : 0 ?>–<?= min($offset + $perPage, $totalRows) ?></strong>
        de <strong><?= $totalRows ?></strong> registros
      </div>

      <div class="pagination-pages">
        <?php
        $showPages = 5;
        $start = max(1, $page - floor($showPages/2));
        $end = min($totalPages, $start + $showPages - 1);
        $start = max(1, $end - $showPages + 1);

        if ($start > 1): ?>
          <a class="page-btn" href="<?= h(urlWith(['page'=>1])) ?>">1</a>
          <?php if ($start > 2): ?>
            <span style="opacity:.5;padding:0 4px;">...</span>
          <?php endif; ?>
        <?php endif; ?>

        <?php for ($i=$start; $i<=$end; $i++): ?>
          <a class="page-btn <?= $i===$page?'active':'' ?>" href="<?= h(urlWith(['page'=>$i])) ?>">
            <?= $i ?>
          </a>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
          <?php if ($end < $totalPages - 1): ?>
            <span style="opacity:.5;padding:0 4px;">...</span>
          <?php endif; ?>
          <a class="page-btn" href="<?= h(urlWith(['page'=>$totalPages])) ?>"><?= $totalPages ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

 </div>


<?php if ($savedFlag !== ''): ?>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    const msg = <?= json_encode($savedFlag === 'confirmed'
      ? '✅ Compra confirmada. Stock actualizado correctamente.'
      : '💾 Compra guardada en borrador. Podés confirmarla desde el listado.'
    ) ?>;
    
    if (window.showToast) {
      window.showToast(msg, 'success');
    } else {
      const toast = document.createElement("div");
      toast.className = "toast toast-success show";
      toast.textContent = msg;
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 3000);
    }
  });
</script>
<?php endif; ?>

<?php require __DIR__ . "/partials/footer.php"; ?>