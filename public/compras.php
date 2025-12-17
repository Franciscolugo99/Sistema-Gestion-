<?php
// public/compras.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

$msg = '';
$savedFlag = (string)($_GET['saved'] ?? '');

/* -----------------------------
   Helpers
------------------------------ */
function parse_decimal(?string $s, float $default = 0.0): float {
  if ($s === null) return $default;
  $s = trim($s);
  if ($s === '') return $default;

  $s = str_replace(' ', '', $s);

  // Formato AR: 1.234,56
  if (strpos($s, ',') !== false) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else {
    $s = str_replace(',', '', $s);
  }

  return is_numeric($s) ? (float)$s : $default;
}

function urlWith(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  return 'compras.php' . (empty($q) ? '' : '?' . http_build_query($q));
}

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
  SELECT id, codigo, nombre, es_pesable, unidad_venta
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

<div class="page-wrap">

  <div class="panel">
    <header class="page-header">
      <div>
        <h1 class="page-title">Compras</h1>
        <p class="page-sub">Cargá compras en <b>BORRADOR</b> y luego confirmalas para impactar stock.</p>
      </div>
    </header>

    <form method="post" id="compraForm" class="compras-form">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="guardar_borrador">

      <div class="form-grid">
        <div class="field">
          <label>Proveedor</label>
          <input name="proveedor" placeholder="Ej: Mayorista X" required>
        </div>

        <div class="field">
          <label>Comprobante tipo</label>
          <input name="tipo_comp" placeholder="Ej: Factura A">
        </div>

        <div class="field">
          <label>Comprobante nro</label>
          <input name="nro_comp" placeholder="Ej: 0001-00001234">
        </div>

        <div class="field field-wide">
          <label>Observación</label>
          <input name="observacion" placeholder="Notas internas (opcional)">
        </div>
      </div>

      <div class="hr"></div>

      <div class="items-grid">
        <div class="field field-wide">
          <label>Producto</label>
          <select id="itemProducto">
            <option value="">Seleccionar…</option>
            <?php foreach ($productos as $p): ?>
              <option
                value="<?= (int)$p['id'] ?>"
                data-es-pesable="<?= (int)($p['es_pesable'] ?? 0) ?>"
                data-unidad="<?= h((string)($p['unidad_venta'] ?? 'UNIDAD')) ?>"
              >
                <?= h((string)$p['nombre']) ?> (<?= h((string)$p['codigo']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Cantidad</label>
          <input id="itemCantidad" type="number" step="1" min="1" value="1">
          <div class="help" id="itemUnidad">Unidad: UNIDAD</div>
        </div>

        <div class="field">
          <label>Costo unitario</label>
          <input id="itemCosto" type="number" step="0.01" min="0" value="0">
        </div>

        <div class="field">
          <label>&nbsp;</label>
          <button type="button" class="btn btn-secondary" id="btnAddItem">Agregar ítem</button>
        </div>
      </div>

      <div class="table-wrapper">
        <table class="compras-table" id="itemsTable">
          <thead>
            <tr>
              <th>Producto</th>
              <th class="right">Cantidad</th>
              <th class="right">Costo</th>
              <th class="right">Subtotal</th>
              <th class="center">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr class="empty-row">
              <td colspan="5" class="empty-cell">Todavía no agregaste ítems.</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" class="right"><b>Total</b></td>
              <td class="right"><b id="totalLbl">$0,00</b></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="actions">
        <button class="btn btn-primary" type="submit">Guardar borrador</button>
      </div>

      <?php if ($msg): ?>
        <div class="msg msg-visible msg-info" style="margin-top:12px;">
          <?= h($msg) ?>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="panel" style="margin-top:18px;">
    <h2 class="sub-title-page">Listado</h2>

    <form method="get" class="filters">
      <div class="filters-left">
        <input type="text" name="q" placeholder="Buscar por proveedor, comprobante o ID…" value="<?= h($q) ?>">
      </div>

      <div class="filters-right">
        <select name="estado">
          <option value="">Todos</option>
          <?php foreach (['BORRADOR','CONFIRMADA','ANULADA'] as $e): ?>
            <option value="<?= $e ?>" <?= $estado===$e?'selected':'' ?>><?= $e ?></option>
          <?php endforeach; ?>
        </select>

        <input type="date" name="desde" value="<?= h($desde ?? '') ?>">
        <input type="date" name="hasta" value="<?= h($hasta ?? '') ?>">

        <select name="per_page">
          <?php foreach ([20,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?></option>
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
            <tr><td colspan="6" class="empty-cell">No hay compras con esos filtros.</td></tr>
          <?php else: foreach ($compras as $c): ?>
            <tr>
              <td><?= h((string)$c['fecha']) ?></td>
              <td><?= h((string)($c['proveedor_nombre'] ?? '')) ?></td>
              <td><?= h((string)$c['tipo_comp']) ?> <?= h((string)$c['nro_comp']) ?></td>
              <td><?= h((string)$c['estado']) ?></td>
              <td class="right"><?= money_ar((float)$c['total']) ?></td>
              <td class="center">
                <?php if ((string)$c['estado'] === 'BORRADOR'): ?>
                  <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="confirmar">
                    <input type="hidden" name="compra_id" value="<?= (int)$c['id'] ?>">
                    <button class="btn-line" type="submit">Confirmar</button>
                  </form>
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
          Mostrando <?= $totalRows ? ($offset + 1) : 0 ?>–<?= min($offset + $perPage, $totalRows) ?>
          de <?= $totalRows ?>
        </div>

        <div class="pagination-pages">
          <?php for ($i=1; $i<=$totalPages; $i++): ?>
            <a class="page-btn <?= $i===$page?'active':'' ?>" href="<?= h(urlWith(['page'=>$i])) ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php if ($savedFlag !== ''): ?>
<script>
  if (window.showToast) {
    const msg =
      <?= json_encode($savedFlag === 'confirmed'
        ? 'Compra confirmada. Stock actualizado.'
        : 'Compra guardada en borrador.'
      ) ?>;
    window.showToast(msg);
  }
</script>
<?php endif; ?>

<?php require __DIR__ . "/partials/footer.php"; ?>
