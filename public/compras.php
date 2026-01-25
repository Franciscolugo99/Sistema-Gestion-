<?php
// public/compras.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('editar_stock');


// ✅ Compatibilidad de esquema (evita 1054 Unknown column en instalaciones viejas)
require_once FLUS_ROOT . '/src/db_helpers.php';

/**
 * Intenta asegurar columnas nuevas usadas por Compras.
 * Si no puede (permisos), el módulo sigue funcionando con fallback.
 */
function flus_compras_ensure_schema(PDO $pdo): void {
  try {
    if (has_table($pdo, 'compras')) {
      if (!has_column($pdo, 'compras', 'total_neto')) {
        $pdo->exec("ALTER TABLE compras ADD COLUMN total_neto DECIMAL(12,2) NOT NULL DEFAULT 0");
      }
      if (!has_column($pdo, 'compras', 'total_iva')) {
        $pdo->exec("ALTER TABLE compras ADD COLUMN total_iva DECIMAL(12,2) NOT NULL DEFAULT 0");
      }
      if (!has_column($pdo, 'compras', 'total_bruto')) {
        $pdo->exec("ALTER TABLE compras ADD COLUMN total_bruto DECIMAL(12,2) NOT NULL DEFAULT 0");
      }
      if (!has_column($pdo, 'compras', 'descuento_tipo')) {
        $pdo->exec("ALTER TABLE compras ADD COLUMN descuento_tipo VARCHAR(10) NOT NULL DEFAULT 'MONTO'");
      }
      if (!has_column($pdo, 'compras', 'descuento_valor')) {
        $pdo->exec("ALTER TABLE compras ADD COLUMN descuento_valor DECIMAL(12,2) NOT NULL DEFAULT 0");
      }
      if (!has_column($pdo, 'compras', 'descuento_total')) {
        $pdo->exec("ALTER TABLE compras ADD COLUMN descuento_total DECIMAL(12,2) NOT NULL DEFAULT 0");
      }

      // Backfill suave para DBs viejas (si se agregaron columnas recién)
      if (has_column($pdo, 'compras', 'total_bruto') && has_column($pdo, 'compras', 'total')) {
        $pdo->exec("UPDATE compras SET total_bruto = total WHERE total_bruto = 0 AND total <> 0");
      }
      if (has_column($pdo, 'compras', 'total_neto') && has_column($pdo, 'compras', 'total')) {
        $pdo->exec("UPDATE compras SET total_neto = total WHERE total_neto = 0 AND total <> 0");
      }
    }

    if (has_table($pdo, 'compra_items')) {
      if (!has_column($pdo, 'compra_items', 'descuento')) {
        $pdo->exec("ALTER TABLE compra_items ADD COLUMN descuento DECIMAL(12,2) NOT NULL DEFAULT 0");
      }
      if (!has_column($pdo, 'compra_items', 'descuento_tipo')) {
        $pdo->exec("ALTER TABLE compra_items ADD COLUMN descuento_tipo VARCHAR(10) NOT NULL DEFAULT 'MONTO'");
      }
      if (!has_column($pdo, 'compra_items', 'descuento_porc')) {
        $pdo->exec("ALTER TABLE compra_items ADD COLUMN descuento_porc DECIMAL(12,2) NOT NULL DEFAULT 0");
      }
    }
  } catch (Throwable $e) {
    // No rompemos la pantalla por schema (fallback en queries)
  }
}

flus_compras_ensure_schema($pdo);

$HAS_COMPRAS_TOTAL_NETO      = has_column($pdo, 'compras', 'total_neto');
$HAS_COMPRAS_TOTAL_IVA       = has_column($pdo, 'compras', 'total_iva');
$HAS_COMPRAS_TOTAL_BRUTO     = has_column($pdo, 'compras', 'total_bruto');
$HAS_COMPRAS_DESCUENTO_TIPO  = has_column($pdo, 'compras', 'descuento_tipo');
$HAS_COMPRAS_DESCUENTO_VALOR = has_column($pdo, 'compras', 'descuento_valor');
$HAS_COMPRAS_DESCUENTO_TOTAL = has_column($pdo, 'compras', 'descuento_total');
$HAS_COMPRA_ITEMS_DESCUENTO       = has_column($pdo, 'compra_items', 'descuento');
$HAS_COMPRA_ITEMS_DESCUENTO_TIPO  = has_column($pdo, 'compra_items', 'descuento_tipo');
$HAS_COMPRA_ITEMS_DESCUENTO_PORC  = has_column($pdo, 'compra_items', 'descuento_porc');

$msg = '';
$msgType = 'info';
$savedFlag = (string)($_GET['saved'] ?? '');
$editMode = false;
$compraEdit = null;

/* -----------------------------
   Helpers
------------------------------ */
function normalizeProveedorName(string $name): string {
  $name = trim($name);
  $name = preg_replace('/\s+/', ' ', $name); // múltiples espacios → uno
  // Capitalizar cada palabra
  return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Obtiene o crea un proveedor de forma segura (evita duplicados por race condition)
 */
function getOrCreateProveedor(PDO $pdo, string $nombre): int {
  $nombre = normalizeProveedorName($nombre);
  
  // Primero intentar buscar
  $stFind = $pdo->prepare("
    SELECT id FROM proveedores 
    WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) 
    LIMIT 1
  ");
  $stFind->execute([$nombre]);
  $proveedorId = (int)($stFind->fetchColumn() ?: 0);
  
  if ($proveedorId > 0) {
    return $proveedorId;
  }
  
  // Intentar crear (con manejo de duplicate key por si otro proceso lo creó)
  try {
    $stIns = $pdo->prepare("
      INSERT INTO proveedores (nombre, activo)
      VALUES (?, 1)
    ");
    $stIns->execute([$nombre]);
    return (int)$pdo->lastInsertId();
  } catch (PDOException $e) {
    // Si es error de duplicate key (código 23000), buscar de nuevo
    if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate')) {
      $stFind->execute([$nombre]);
      $proveedorId = (int)($stFind->fetchColumn() ?: 0);
      if ($proveedorId > 0) {
        return $proveedorId;
      }
    }
    throw $e;
  }
}

/* -----------------------------
   EDITAR: cargar datos
------------------------------ */
if (isset($_GET['editar'])) {
  $editId = (int)$_GET['editar'];
  
  try {
    $st = $pdo->prepare("
      SELECT c.*, p.nombre AS proveedor_nombre
      FROM compras c
      LEFT JOIN proveedores p ON p.id = c.proveedor_id
      WHERE c.id = ? AND c.estado = 'BORRADOR'
    ");
    $st->execute([$editId]);
    $compraEdit = $st->fetch(PDO::FETCH_ASSOC);
    
    if ($compraEdit) {
      $editMode = true;
      
      // Traer items
      $stItems = $pdo->prepare("
        SELECT ci.*, p.nombre, p.codigo, p.es_pesable, p.unidad_venta
        FROM compra_items ci
        JOIN productos p ON p.id = ci.producto_id
        WHERE ci.compra_id = ?
        ORDER BY ci.id
      ");
      $stItems->execute([$editId]);
      $compraEdit['items'] = $stItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      $msg = 'Compra no encontrada o ya fue confirmada.';
      $msgType = 'warning';
    }
  } catch (Throwable $e) {
    $msg = 'Error al cargar compra: ' . $e->getMessage();
    $msgType = 'error';
  }
}

/* -----------------------------
   POST actions
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $msg = 'Token CSRF inválido. Recargá y probá de nuevo.';
    $msgType = 'error';
  } else {

    $accion = (string)($_POST['accion'] ?? '');

    /* =========================================================
       1) Guardar BORRADOR (crear o actualizar)
    ========================================================= */
    if ($accion === 'guardar_borrador') {

      $compraId = (int)($_POST['compra_id'] ?? 0); // 0 = crear, >0 = editar
      $proveedorTxt = trim((string)($_POST['proveedor'] ?? ''));
      $tipoComp     = trim((string)($_POST['tipo_comp'] ?? ''));
      $nroComp      = trim((string)($_POST['nro_comp'] ?? ''));
      $observacion  = trim((string)($_POST['observacion'] ?? ''));

      // Descuento (opcional): MONTO ($) o PORC (%)
      $descuentoTipo  = strtoupper(trim((string)($_POST['descuento_tipo'] ?? 'MONTO')));
      $descuentoValor = parse_decimal((string)($_POST['descuento_valor'] ?? ''), 0.0);
      if (!in_array($descuentoTipo, ['MONTO','PORC'], true)) $descuentoTipo = 'MONTO';
      if ($descuentoValor < 0) $descuentoValor = 0.0;

      $prodIds = $_POST['producto_id'] ?? [];
      $cants   = $_POST['cantidad'] ?? [];
      $costos  = $_POST['costo_unitario'] ?? [];

      // Descuento por ítem (opcional): MONTO ($) o PORC (%)
      $itemDescTipos  = $_POST['item_descuento_tipo'] ?? [];
      $itemDescValores = $_POST['item_descuento_valor'] ?? [];

      if ($proveedorTxt === '') {
        $msg = 'Proveedor es obligatorio.';
        $msgType = 'warning';
      } elseif (!is_array($prodIds) || count($prodIds) === 0) {
        $msg = 'Agregá al menos 1 ítem a la compra.';
        $msgType = 'warning';
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

          if ($qty <= 0) { 
            $msg = 'Cantidad inválida en un ítem.'; 
            $msgType = 'warning';
            break; 
          }
          if ($cu < 0) { 
            $msg = 'Costo unitario inválido en un ítem.'; 
            $msgType = 'warning';
            break; 
          }

          // Validaciones de rango
          if ($qty > 99999) {
            $msg = 'Cantidad muy alta en un ítem (máx: 99,999).';
            $msgType = 'warning';
            break;
          }
          if ($cu > 9999999) {
            $msg = 'Costo muy alto en un ítem (máx: $9,999,999).';
            $msgType = 'warning';
            break;
          }

          $sub = $qty * $cu;
          $total += $sub;

          // Descuento por ítem (clamp)
          $dTipo = strtoupper(trim((string)($itemDescTipos[$i] ?? 'MONTO')));
          if (!in_array($dTipo, ['MONTO','PORC'], true)) $dTipo = 'MONTO';
          $dVal  = parse_decimal((string)($itemDescValores[$i] ?? ''), 0.0);
          if ($dVal < 0) $dVal = 0.0;

          $dMonto = 0.0;
          $dPorc  = 0.0;

          if ($sub > 0 && $dVal > 0) {
            if ($dTipo === 'PORC') {
              if ($dVal > 100) $dVal = 100.0;
              $dPorc  = $dVal;
              $dMonto = $sub * ($dPorc / 100.0);
            } else { // MONTO
              if ($dVal > $sub) $dVal = $sub;
              $dMonto = $dVal;
              $dPorc  = 0.0;
            }
          }

          $dMonto = round($dMonto, 2);

          $items[] = [
            'producto_id'    => $pid,
            'cantidad'       => $qty,
            'costo_unitario' => $cu,
            'subtotal'       => $sub,

            'descuento_tipo'  => $dTipo,
            'descuento_valor' => $dVal,    // % o monto según tipo
            'descuento_porc'  => $dPorc,   // % real (0 si MONTO)
            'descuento_monto' => $dMonto,  // $ real (0 si no aplica)
          ];
        }

        if ($msg === '' && count($items) === 0) {
          $msg = 'Agregá al menos 1 ítem válido.';
          $msgType = 'warning';
        }
      }

      if ($msg === '') {
        try {
          $pdo->beginTransaction();

          // Proveedor (buscar normalizado o crear - con protección contra duplicados)
          $proveedorId = getOrCreateProveedor($pdo, $proveedorTxt);

          $totalBruto = $total;

// Total descuento por ítems
$descItemsTotal = 0.0;
foreach ($items as $it) {
  $descItemsTotal += (float)($it['descuento_monto'] ?? 0.0);
}
$descItemsTotal = round($descItemsTotal, 2);

// Base para descuento global: bruto - descuentos por ítem
$baseGlobal = max(0.0, round($totalBruto - $descItemsTotal, 2));

// Calcular descuento global total (clamp) SOBRE baseGlobal
$descuentoTotal = 0.0;
if ($baseGlobal > 0 && $descuentoValor > 0) {
  if ($descuentoTipo === 'PORC') {
    if ($descuentoValor > 100) $descuentoValor = 100.0;
    $descuentoTotal = $baseGlobal * ($descuentoValor / 100.0);
  } else { // MONTO
    if ($descuentoValor > $baseGlobal) $descuentoValor = $baseGlobal;
    $descuentoTotal = $descuentoValor;
  }
}

$descuentoTotal = round($descuentoTotal, 2);
$totalFinal = max(0.0, round($baseGlobal - $descuentoTotal, 2));

// Totales guardados
$totalNeto = $totalFinal;
$totalIva  = 0.0;
$total     = $totalFinal;

if ($compraId > 0) {
            // EDITAR: verificar que esté en BORRADOR
            $stCheck = $pdo->prepare("SELECT estado FROM compras WHERE id = ?");
            $stCheck->execute([$compraId]);
            $estadoActual = (string)($stCheck->fetchColumn() ?: '');
            
            if ($estadoActual !== 'BORRADOR') {
              throw new RuntimeException('Solo se pueden editar compras en BORRADOR.');
            }

            
            // Actualizar compra (compat: columnas opcionales)
            $set = [
              "proveedor_id = :proveedor_id",
              "tipo_comp = :tipo_comp",
              "nro_comp = :nro_comp",
              "obs = :obs",
            ];

            $paramsUpd = [
              ':id' => $compraId,
              ':proveedor_id' => $proveedorId,
              ':tipo_comp' => $tipoComp,
              ':nro_comp' => $nroComp,
              ':obs' => $observacion,
              ':total' => $total,
            ];

            if ($HAS_COMPRAS_TOTAL_NETO)      { $set[] = "total_neto = :total_neto";           $paramsUpd[':total_neto'] = $totalNeto; }
            if ($HAS_COMPRAS_TOTAL_IVA)       { $set[] = "total_iva = :total_iva";             $paramsUpd[':total_iva'] = $totalIva; }
            if ($HAS_COMPRAS_TOTAL_BRUTO)     { $set[] = "total_bruto = :total_bruto";         $paramsUpd[':total_bruto'] = $totalBruto; }
            if ($HAS_COMPRAS_DESCUENTO_TIPO)  { $set[] = "descuento_tipo = :descuento_tipo";   $paramsUpd[':descuento_tipo'] = $descuentoTipo; }
            if ($HAS_COMPRAS_DESCUENTO_VALOR) { $set[] = "descuento_valor = :descuento_valor"; $paramsUpd[':descuento_valor'] = $descuentoValor; }
            if ($HAS_COMPRAS_DESCUENTO_TOTAL) { $set[] = "descuento_total = :descuento_total"; $paramsUpd[':descuento_total'] = $descuentoTotal; }

            $set[] = "total = :total";

            $sqlUpd = "UPDATE compras SET
  " . implode(",
  ", $set) . "
WHERE id = :id AND estado = 'BORRADOR'";
            $stUpd = $pdo->prepare($sqlUpd);
            $stUpd->execute($paramsUpd);

            // Borrar items viejos
            $pdo->prepare("DELETE FROM compra_items WHERE compra_id = ?")
               ->execute([$compraId]);

          } else {
            
// CREAR nueva (compat: columnas opcionales)
$cols = ['fecha','proveedor_id','tipo_comp','nro_comp','obs','estado','total'];
$vals = ['CURDATE()',':proveedor_id',':tipo_comp',':nro_comp',':obs',"'BORRADOR'",':total'];

$paramsIns = [
  ':proveedor_id' => $proveedorId,
  ':tipo_comp'    => $tipoComp,
  ':nro_comp'     => $nroComp,
  ':obs'          => $observacion,
  ':total'        => $total,
];

if ($HAS_COMPRAS_TOTAL_NETO)      { $cols[] = 'total_neto';      $vals[] = ':total_neto';      $paramsIns[':total_neto'] = $totalNeto; }
if ($HAS_COMPRAS_TOTAL_IVA)       { $cols[] = 'total_iva';       $vals[] = ':total_iva';       $paramsIns[':total_iva'] = $totalIva; }
if ($HAS_COMPRAS_TOTAL_BRUTO)     { $cols[] = 'total_bruto';     $vals[] = ':total_bruto';     $paramsIns[':total_bruto'] = $totalBruto; }
if ($HAS_COMPRAS_DESCUENTO_TIPO)  { $cols[] = 'descuento_tipo';  $vals[] = ':descuento_tipo';  $paramsIns[':descuento_tipo'] = $descuentoTipo; }
if ($HAS_COMPRAS_DESCUENTO_VALOR) { $cols[] = 'descuento_valor'; $vals[] = ':descuento_valor'; $paramsIns[':descuento_valor'] = $descuentoValor; }
if ($HAS_COMPRAS_DESCUENTO_TOTAL) { $cols[] = 'descuento_total'; $vals[] = ':descuento_total'; $paramsIns[':descuento_total'] = $descuentoTotal; }

$sqlIns = "INSERT INTO compras (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
$stCompra = $pdo->prepare($sqlIns);
$stCompra->execute($paramsIns);

$compraId = (int)$pdo->lastInsertId();
          }

          // Insertar items
          // Insertar items (compat: columnas opcionales de descuento por ítem)
          $colsIt = ['compra_id','producto_id','cantidad','costo_unitario','subtotal','comentario'];
          if ($HAS_COMPRA_ITEMS_DESCUENTO)      { $colsIt[] = 'descuento'; }
          if ($HAS_COMPRA_ITEMS_DESCUENTO_TIPO) { $colsIt[] = 'descuento_tipo'; }
          if ($HAS_COMPRA_ITEMS_DESCUENTO_PORC) { $colsIt[] = 'descuento_porc'; }

          $phIt = array_map(fn($c) => ':' . $c, $colsIt);

          $stItem = $pdo->prepare("
            INSERT INTO compra_items
              (" . implode(', ', $colsIt) . ")
            VALUES
              (" . implode(', ', $phIt) . ")
          ");

          foreach ($items as $it) {
            $paramsItem = [
              ':compra_id'      => $compraId,
              ':producto_id'    => $it['producto_id'],
              ':cantidad'       => $it['cantidad'],
              ':costo_unitario' => $it['costo_unitario'],
              ':subtotal'       => $it['subtotal'],
              ':comentario'     => '',
            ];

            if ($HAS_COMPRA_ITEMS_DESCUENTO) {
              $paramsItem[':descuento'] = (float)($it['descuento_monto'] ?? 0.0);
            }
            if ($HAS_COMPRA_ITEMS_DESCUENTO_TIPO) {
              $paramsItem[':descuento_tipo'] = (string)($it['descuento_tipo'] ?? 'MONTO');
            }
            if ($HAS_COMPRA_ITEMS_DESCUENTO_PORC) {
              $paramsItem[':descuento_porc'] = (float)($it['descuento_porc'] ?? 0.0);
            }

            $stItem->execute($paramsItem);
          }

          $pdo->commit();

          header("Location: compras.php?saved=" . ($editMode ? 'updated' : 'created'));
          exit;

        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $msg = "Error al guardar: " . $e->getMessage();
          $msgType = 'error';
        }
      }
    }

    /* =========================================================
       2) Eliminar BORRADOR
    ========================================================= */
    if ($accion === 'eliminar_borrador') {
      $compraId = (int)($_POST['compra_id'] ?? 0);
      
      if ($compraId <= 0) {
        $msg = 'ID inválido.';
        $msgType = 'error';
      } else {
        try {
          $pdo->beginTransaction();

          // Verificar estado
          $st = $pdo->prepare("SELECT estado FROM compras WHERE id = ?");
          $st->execute([$compraId]);
$rowCompra = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$estado = (string)($rowCompra['estado'] ?? '');

$compraTotalBruto     = (float)($rowCompra['total_bruto'] ?? 0.0);
$compraDescuentoTotal = (float)($rowCompra['descuento_total'] ?? 0.0);

if ($estado !== 'BORRADOR') {
            throw new RuntimeException('Solo se pueden eliminar compras en BORRADOR.');
          }

          // Eliminar items
          $pdo->prepare("DELETE FROM compra_items WHERE compra_id = ?")
             ->execute([$compraId]);

          // Eliminar compra
          $pdo->prepare("DELETE FROM compras WHERE id = ?")
             ->execute([$compraId]);

          $pdo->commit();

          header("Location: compras.php?saved=deleted");
          exit;

        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $msg = "Error al eliminar: " . $e->getMessage();
          $msgType = 'error';
        }
      }
    }

    /* =========================================================
      3) Confirmar (impacta stock + movimientos)
    ========================================================= */
    if ($accion === 'confirmar') {

      $compraId = (int)($_POST['compra_id'] ?? 0);
      if ($compraId <= 0) {
        $msg = "ID inválido.";
        $msgType = 'error';
      } else {
        try {
          $pdo->beginTransaction();

          // 1) Bloquear compra y traer datos necesarios

$colsLock = ['estado'];
// total_bruto puede no existir en DBs viejas: usamos total como fallback
$colsLock[] = $HAS_COMPRAS_TOTAL_BRUTO ? 'total_bruto' : 'total';
if ($HAS_COMPRAS_DESCUENTO_TOTAL) $colsLock[] = 'descuento_total';

$st = $pdo->prepare("SELECT " . implode(', ', array_unique($colsLock)) . " FROM compras WHERE id = ? FOR UPDATE");
          $st->execute([$compraId]);
          $rowCompra = $st->fetch(PDO::FETCH_ASSOC) ?: [];

          if (!$rowCompra) {
            throw new RuntimeException("Compra no encontrada.");
          }

          $estado = (string)($rowCompra['estado'] ?? '');
          $compraTotalBruto     = (float)($rowCompra['total_bruto'] ?? ($rowCompra['total'] ?? 0.0));
          $compraDescuentoTotal = (float)($rowCompra['descuento_total'] ?? 0.0);

          if ($estado !== 'BORRADOR') {
            throw new RuntimeException("Solo se pueden confirmar compras en BORRADOR.");
          }

          // 2) Traer ítems (compat: columnas opcionales)
          $colsItSel = ['id','producto_id','cantidad','costo_unitario','subtotal'];
          if ($HAS_COMPRA_ITEMS_DESCUENTO)      { $colsItSel[] = 'descuento'; }
          if ($HAS_COMPRA_ITEMS_DESCUENTO_TIPO) { $colsItSel[] = 'descuento_tipo'; }
          if ($HAS_COMPRA_ITEMS_DESCUENTO_PORC) { $colsItSel[] = 'descuento_porc'; }

          $itSt = $pdo->prepare("
            SELECT " . implode(', ', $colsItSel) . "
            FROM compra_items
            WHERE compra_id = ?
            ORDER BY id ASC
          ");
          $itSt->execute([$compraId]);
          $items = $itSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

          if (!$items) {
            throw new RuntimeException("La compra no tiene ítems.");
          }

          // 3) Verificar productos (existen + activos)
          $stCheckProd = $pdo->prepare("SELECT id, nombre, activo FROM productos WHERE id = ?");
          foreach ($items as $it) {
            $pid = (int)$it['producto_id'];
            $stCheckProd->execute([$pid]);
            $prod = $stCheckProd->fetch(PDO::FETCH_ASSOC);
            if (!$prod) {
              throw new RuntimeException("El producto ID {$pid} ya no existe en el sistema.");
            }
            if (isset($prod['activo']) && (int)$prod['activo'] === 0) {
              $nombre = (string)($prod['nombre'] ?? '');
              throw new RuntimeException("El producto '{$nombre}' está desactivado. Eliminalo de la compra o reactivá el producto.");
            }
          }

          // 4) Calcular descuento por ítem (guardado) y prorratear descuento global (compras.descuento_total)
          $baseNet = 0.0;
          $netByItemId = []; // id => neto (después de desc ítem)
          $dItemByItemId = []; // id => desc ítem ($)

          foreach ($items as $it) {
            $itemId = (int)($it['id'] ?? 0);
            $qty = (float)($it['cantidad'] ?? 0.0);
            $cu  = (float)($it['costo_unitario'] ?? 0.0);

            $sub = (float)($it['subtotal'] ?? 0.0);
            if ($sub <= 0) $sub = $qty * $cu;

            // Descuento por ítem: preferir tipo/porc si existe, si no usar descuento (monto)
            $dItem = 0.0;
            if ($HAS_COMPRA_ITEMS_DESCUENTO_TIPO && isset($it['descuento_tipo'])) {
              $t = strtoupper((string)$it['descuento_tipo']);
              if ($t === 'PORC') {
                $porc = (float)($it['descuento_porc'] ?? 0.0);
                if ($porc < 0) $porc = 0.0;
                if ($porc > 100) $porc = 100.0;
                $dItem = $sub * ($porc / 100.0);
              } else { // MONTO (o desconocido)
                $monto = (float)($it['descuento'] ?? 0.0);
                if ($monto < 0) $monto = 0.0;
                if ($monto > $sub) $monto = $sub;
                $dItem = $monto;
              }
            } elseif ($HAS_COMPRA_ITEMS_DESCUENTO && isset($it['descuento'])) {
              $monto = (float)$it['descuento'];
              if ($monto < 0) $monto = 0.0;
              if ($monto > $sub) $monto = $sub;
              $dItem = $monto;
            }

            $dItem = round($dItem, 2);

            $net = max(0.0, round($sub - $dItem, 2));
            $baseNet += $net;

            $dItemByItemId[$itemId] = $dItem;
            $netByItemId[$itemId] = $net;
          }

          $baseNet = round($baseNet, 2);

          // Prorratear descuento global por neto (post-desc ítem)
          $dGlobalByItemId = []; // id => desc global ($)
          if ($compraDescuentoTotal > 0 && $baseNet > 0) {
            $sum = 0.0;
            $lastId = null;

            foreach ($items as $it) {
              $itemId = (int)($it['id'] ?? 0);
              $lastId = $itemId;

              $net = (float)($netByItemId[$itemId] ?? 0.0);
              $d = round(($net / $baseNet) * $compraDescuentoTotal, 2);
              if ($d < 0) $d = 0.0;

              $sum += $d;
              $dGlobalByItemId[$itemId] = $d;
            }

            // Ajuste por redondeo para que cierre exacto: SUM(desc_global) == descuento_total
            $diff = round($compraDescuentoTotal - $sum, 2);
            if (abs($diff) >= 0.01 && $lastId !== null) {
              $newLast = round(($dGlobalByItemId[$lastId] ?? 0.0) + $diff, 2);
              if ($newLast < 0) $newLast = 0.0;
              if ($newLast > (float)($netByItemId[$lastId] ?? 0.0)) $newLast = (float)($netByItemId[$lastId] ?? 0.0);
              $dGlobalByItemId[$lastId] = $newLast;
            }
          }

// 5) Impactar stock + movimientos + costo (costo neto por ítem)

          $stUpdStock = $pdo->prepare("UPDATE productos SET stock = stock + :qty WHERE id = :pid");

          $stMov = $pdo->prepare("
            INSERT INTO movimientos_stock
              (fecha, producto_id, tipo, cantidad, referencia_venta_id, referencia_compra_id, comentario)
            VALUES
              (NOW(), :pid, 'COMPRA', :qty, NULL, :compra_id, :com)
          ");

          $stUpdCosto = $pdo->prepare("UPDATE productos SET costo = :costo WHERE id = :pid");

          foreach ($items as $it) {
            $itemId = (int)$it['id'];
            $pid = (int)$it['producto_id'];
            $qty = (float)$it['cantidad'];
            $cu  = (float)$it['costo_unitario'];

            if ($qty <= 0) continue;

            // stock + movimiento
            $stUpdStock->execute([':qty' => $qty, ':pid' => $pid]);
            $stMov->execute([
              ':pid'       => $pid,
              ':qty'       => $qty,
              ':compra_id' => $compraId,
              ':com'       => "Compra #{$compraId}",
            ]);

            // costo neto: (subtotal - descuento_item) / qty
            $sub = (float)($it['subtotal'] ?? 0.0);
            if ($sub <= 0) $sub = $qty * $cu;

            $dItem = (float)($dItemByItemId[$itemId] ?? 0.0);
            $dGlob = (float)($dGlobalByItemId[$itemId] ?? 0.0);

            $netItem = max(0.0, $sub - $dItem - $dGlob);

            $cuAdj = round($netItem / $qty, 2);
            if ($cuAdj > 0) {
              $stUpdCosto->execute([':costo' => $cuAdj, ':pid' => $pid]);
            }
          }

          // 6) Confirmar compra
          $pdo->prepare("UPDATE compras SET estado='CONFIRMADA' WHERE id=?")->execute([$compraId]);

          $pdo->commit();

          header("Location: compras.php?saved=confirmed");
          exit;

        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $msg = "Error al confirmar: " . $e->getMessage();
          $msgType = 'error';
        }
      }
    }


    /* =========================================================
       4) Anular CONFIRMADA (opcional: revierte stock)
    ========================================================= */
    if ($accion === 'anular_confirmada') {
      $compraId = (int)($_POST['compra_id'] ?? 0);
      $revertirStock = isset($_POST['revertir_stock']); // checkbox
      
      if ($compraId <= 0) {
        $msg = 'ID inválido.';
        $msgType = 'error';
      } else {
        try {
          $pdo->beginTransaction();

          // Verificar estado
          $st = $pdo->prepare("SELECT estado FROM compras WHERE id = ? FOR UPDATE");
          $st->execute([$compraId]);
          $estado = (string)($st->fetchColumn() ?: '');

          if ($estado !== 'CONFIRMADA') {
            throw new RuntimeException('Solo se pueden anular compras CONFIRMADAS.');
          }

          if ($revertirStock) {
            // Traer items
            $itSt = $pdo->prepare("
              SELECT producto_id, cantidad
              FROM compra_items
              WHERE compra_id = ?
            ");
            $itSt->execute([$compraId]);
            $items = $itSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Revertir stock
            $stUpdStock = $pdo->prepare("UPDATE productos SET stock = stock - :qty WHERE id = :pid");
            $stMov = $pdo->prepare("
              INSERT INTO movimientos_stock
                (fecha, producto_id, tipo, cantidad, referencia_venta_id, referencia_compra_id, comentario)
              VALUES
                (NOW(), :pid, 'ANULACION_COMPRA', :qty, NULL, :compra_id, :com)
            ");

            foreach ($items as $it) {
              $pid = (int)$it['producto_id'];
              $qty = (float)$it['cantidad'];
              if ($qty <= 0) continue;

              $stUpdStock->execute([':qty' => $qty, ':pid' => $pid]);
              $stMov->execute([
                ':pid' => $pid,
                ':qty' => -$qty, // negativo
                ':compra_id' => $compraId,
                ':com' => "Anulación compra #{$compraId}",
              ]);
            }
          }

          // Marcar como ANULADA
          $pdo->prepare("UPDATE compras SET estado='ANULADA' WHERE id=?")
             ->execute([$compraId]);

          $pdo->commit();

          header("Location: compras.php?saved=anulada");
          exit;

        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $msg = "Error al anular: " . $e->getMessage();
          $msgType = 'error';
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

<div class="compras-page">
  <!-- FORMULARIO -->
  <div class="panel">
    <header class="page-header">
      <div>
        <h1 class="page-title">✨ Compras <?= $editMode ? '(Editando #'.(int)$compraEdit['id'].')' : '' ?></h1>
        <p class="page-sub">
          <?= $editMode 
            ? 'Modificá los datos y guardá los cambios.' 
            : 'Buscá productos con autocomplete, editá ítems en línea y confirmá para impactar stock.' 
          ?>
        </p>
      </div>
      <?php if ($editMode): ?>
        <a href="compras.php" class="btn btn-secondary">❌ Cancelar edición</a>
      <?php endif; ?>
    </header>

    <form method="post" id="compraForm" class="compras-form">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="guardar_borrador">
      <?php if ($editMode): ?>
        <input type="hidden" name="compra_id" value="<?= (int)$compraEdit['id'] ?>">
      <?php endif; ?>

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
          <input 
            name="proveedor" 
            placeholder="Ej: Mayorista X" 
            required 
            autocomplete="off"
            value="<?= $editMode ? h((string)$compraEdit['proveedor_nombre']) : '' ?>"
          >
          <div class="help">Se normalizará automáticamente</div>
        </div>

        <div class="field">
          <label>📄 Tipo comprobante</label>
          <input 
            name="tipo_comp" 
            placeholder="Ej: Factura A" 
            autocomplete="off"
            value="<?= $editMode ? h((string)$compraEdit['tipo_comp']) : '' ?>"
          >
        </div>

        <div class="field">
          <label>🔢 Nro comprobante</label>
          <input 
            name="nro_comp" 
            placeholder="Ej: 0001-00001234" 
            autocomplete="off"
            value="<?= $editMode ? h((string)$compraEdit['nro_comp']) : '' ?>"
          >
        </div>

        <div class="field field-wide">
          <label>📝 Observación</label>
          <input 
            name="observacion" 
            placeholder="Notas internas (opcional)" 
            autocomplete="off"
            value="<?= $editMode ? h((string)$compraEdit['obs']) : '' ?>"
          >
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

        <div class="field" id="qtyFieldContainer">
          <label>📦 Cantidad</label>
          <input id="itemCantidad" type="number" step="1" min="1" value="1" autocomplete="off">
          <div class="help" id="itemUnidad">Unidad: UNIDAD</div>
        </div>

        <div class="field">
          <label>💵 Costo unitario</label>
          <input id="itemCosto" type="number" step="0.01" min="0" value="0" autocomplete="off">
        </div>

        <div class="field">
          <label>🏷️ Desc. ítem</label>
          <div class="inline-controls">
            <select id="itemDescTipo" title="Tipo de descuento por ítem">
              <option value="MONTO">$ Monto</option>
              <option value="PORC">% Porcentaje</option>
            </select>
            <input id="itemDescValor" type="number" step="0.01" min="0" value="0" autocomplete="off" placeholder="0">
          </div>
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
              <th class="right">Desc. ítem</th>
              <th class="right">Subtotal</th>
              <th class="center">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($editMode && !empty($compraEdit['items'])): ?>
              <?php foreach ($compraEdit['items'] as $it): ?>
                <tr class="preloaded-item" 
                    data-producto-id="<?= (int)$it['producto_id'] ?>"
                    data-cantidad="<?= (float)$it['cantidad'] ?>"
                    data-costo="<?= (float)$it['costo_unitario'] ?>"
                    data-desc-tipo="<?= h(strtoupper((string)($it['descuento_tipo'] ?? 'MONTO'))) ?>"
                    data-desc-valor="<?= (float)(((strtoupper((string)($it['descuento_tipo'] ?? 'MONTO'))) === 'PORC') ? ($it['descuento_porc'] ?? 0) : ($it['descuento'] ?? 0)) ?>"
                    data-es-pesable="<?= (int)$it['es_pesable'] ?>"
                    data-unidad="<?= h((string)$it['unidad_venta']) ?>"
                    data-nombre="<?= h((string)$it['nombre']) ?>"
                    data-codigo="<?= h((string)$it['codigo']) ?>">
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr class="empty-row">
                <td colspan="6" class="empty-cell">
                  Todavía no agregaste ítems. Buscá un producto arriba para comenzar.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
<tfoot>
            <tr>
              <td colspan="4" class="right"><strong>BRUTO</strong></td>
              <td class="right"><strong id="totalBrutoLbl">$0,00</strong></td>
              <td></td>
            </tr>
            <tr>
              <td colspan="4" class="right"><strong>DESC. ÍTEMS</strong></td>
              <td class="right"><strong id="descuentoItemsLbl">-$0,00</strong></td>
              <td></td>
            </tr>
            <tr>
              <td colspan="4" class="right">
                <div class="discount-row">
                  <strong>DESCUENTO</strong>
                  <div class="discount-controls">
                    <select id="descuentoTipo" name="descuento_tipo" title="Tipo de descuento">
                      <option value="MONTO" <?= (($editMode ? (string)($compraEdit['descuento_tipo'] ?? 'MONTO') : 'MONTO') === 'MONTO') ? 'selected' : '' ?>>$ Monto</option>
                      <option value="PORC" <?= (($editMode ? (string)($compraEdit['descuento_tipo'] ?? 'MONTO') : 'MONTO') === 'PORC') ? 'selected' : '' ?>>% Porcentaje</option>
                    </select>
                    <input
                      id="descuentoValor"
                      name="descuento_valor"
                      type="number"
                      step="0.01"
                      min="0"
                      value="<?= $editMode ? h((string)($compraEdit['descuento_valor'] ?? 0)) : '0' ?>"
                      placeholder="0"
                      autocomplete="off"
                    >
                  </div>
                </div>
              </td>
              <td class="right"><strong id="descuentoTotalLbl">-$0,00</strong></td>
              <td></td>
            </tr>
            <tr>
              <td colspan="4" class="right"><strong>TOTAL</strong></td>
              <td class="right"><strong id="totalLbl">$0,00</strong></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="actions">
        <button class="btn btn-primary" type="submit">
          💾 <?= $editMode ? 'Actualizar' : 'Guardar' ?> borrador
        </button>
        <button class="btn btn-secondary" type="button" onclick="location.href='compras.php'">
          🔄 <?= $editMode ? 'Cancelar' : 'Limpiar todo' ?>
        </button>
      </div>

      <?php if ($msg): ?>
        <div class="msg msg-visible msg-<?= h($msgType) ?>">
          <?= h($msg) ?>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- LISTADO -->
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
                <div style="font-size:.78rem;opacity:.65;"><?= h((string)$c['nro_comp']) ?></div>
              </td>
              <td>
                <span class="estado-badge estado-<?= h((string)$c['estado']) ?>">
                  <?= h((string)$c['estado']) ?>
                </span>
              </td>
              <td class="right"><strong><?= money_ar((float)$c['total']) ?></strong></td>
              <td class="center">
                <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                  <button 
                    type="button" 
                    class="btn-icon" 
                    onclick="verDetalle(<?= (int)$c['id'] ?>)"
                    title="Ver detalle"
                  >
                    👁️
                  </button>
                  
                  <?php if ((string)$c['estado'] === 'BORRADOR'): ?>
                    <a 
                      href="compras.php?editar=<?= (int)$c['id'] ?>" 
                      class="btn-icon"
                      title="Editar"
                    >
                      ✏️
                    </a>
                    
                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Confirmar esta compra? Se actualizará el stock.')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="confirmar">
                      <input type="hidden" name="compra_id" value="<?= (int)$c['id'] ?>">
                      <button class="btn-icon btn-icon-success" type="submit" title="Confirmar">
                        ✅
                      </button>
                    </form>

                    <form method="post" style="display:inline;" onsubmit="return confirm('¿ELIMINAR esta compra? Esta acción no se puede deshacer.')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="eliminar_borrador">
                      <input type="hidden" name="compra_id" value="<?= (int)$c['id'] ?>">
                      <button class="btn-icon btn-icon-danger" type="submit" title="Eliminar">
                        🗑️
                      </button>
                    </form>
                    
                  <?php elseif ((string)$c['estado'] === 'CONFIRMADA'): ?>
                    <button 
                      type="button" 
                      class="btn-icon btn-icon-warning" 
                      onclick="anularCompra(<?= (int)$c['id'] ?>)"
                      title="Anular"
                    >
                      ⛔
                    </button>
                  <?php endif; ?>
                </div>
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

<!-- Modal detalle -->
<div id="modalDetalle" class="modal-overlay" style="display:none;">
  <div class="modal-box modal-detalle">
    <div class="modal-header">
      <h3 id="modalTitle">Detalle de Compra</h3>
      <button class="btn-close" onclick="cerrarDetalle()">✕</button>
    </div>
    <div class="modal-body" id="modalContent">
      <div class="loading">Cargando...</div>
    </div>
  </div>
</div>

<?php if ($savedFlag !== ''): ?>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    const messages = {
      'created': '💾 Compra guardada en borrador.',
      'updated': '✏️ Compra actualizada correctamente.',
      'confirmed': '✅ Compra confirmada. Stock actualizado.',
      'deleted': '🗑️ Compra eliminada correctamente.',
      'anulada': '⛔ Compra anulada.'
    };
    
    const msg = messages[<?= json_encode($savedFlag) ?>] || 'Operación exitosa';
    
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

<script>
// Ver detalle (modal) - con manejo de errores mejorado
function verDetalle(id) {
  const modal = document.getElementById('modalDetalle');
  const content = document.getElementById('modalContent');
  const title = document.getElementById('modalTitle');
  
  modal.style.display = 'flex';
  content.innerHTML = '<div class="loading">⏳ Cargando...</div>';
  
  fetch(`api/compra_detalle.php?id=${id}`)
    .then(r => {
      if (!r.ok) throw new Error(`Error HTTP: ${r.status}`);
      return r.json();
    })
    .then(data => {
      if (data.error) {
        content.innerHTML = `<div class="msg msg-error">${data.error}</div>`;
        return;
      }
      
      title.textContent = `Compra #${data.id} - ${data.proveedor}`;
      
      let html = `
        <div class="detalle-info">
          <div><strong>Fecha:</strong> ${data.fecha}</div>
          <div><strong>Estado:</strong> <span class="estado-badge estado-${data.estado}">${data.estado}</span></div>
          <div><strong>Comprobante:</strong> ${data.tipo_comp} ${data.nro_comp}</div>
          ${data.obs ? `<div><strong>Observación:</strong> ${data.obs}</div>` : ''}
        </div>
        
        <div class="table-wrapper" style="margin-top:16px;">
          <table class="compras-table">
            <thead>
              <tr>
                <th>Producto</th>
                <th class="right">Cantidad</th>
                <th class="right">Costo Unit.</th>
                <th class="right">Desc. ítem</th>
                <th class="right">Subtotal</th>
              </tr>
            </thead>
            <tbody>
      `;
      
      data.items.forEach(it => {
        html += `
          <tr>
            <td>
              <div style="font-weight:700;">${it.nombre}</div>
              <div style="font-size:.78rem;opacity:.65;">${it.codigo}</div>
            </td>
            <td class="right">${it.cantidad_fmt}</td>
            <td class="right">${it.costo_fmt}</td>
            <td class="right">${it.desc_item_fmt}</td>
            <td class="right"><strong>${it.subtotal_fmt}</strong></td>
          </tr>
        `;
      });
      
      html += `
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="right"><strong>BRUTO</strong></td>
                <td class="right"><strong>${data.total_bruto_fmt}</strong></td>
              </tr>
              <tr>
                <td colspan="4" class="right"><strong>DESC. ÍTEMS</strong></td>
                <td class="right"><strong>-${data.descuento_items_total_fmt}</strong></td>
              </tr>
              <tr>
                <td colspan="4" class="right"><strong>DESCUENTO GLOBAL</strong></td>
                <td class="right"><strong>-${data.descuento_total_fmt}</strong></td>
              </tr>
              <tr>
                <td colspan="4" class="right"><strong>TOTAL</strong></td>
                <td class="right"><strong>${data.total_fmt}</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
      `;
      
      content.innerHTML = html;
    })
    .catch(err => {
      content.innerHTML = `
        <div class="msg msg-error">
          Error al cargar: ${err.message}
          <br>
          <button class="btn btn-secondary" onclick="verDetalle(${id})" style="margin-top:12px;">
            🔄 Reintentar
          </button>
        </div>
      `;
    });
}

function cerrarDetalle() {
  document.getElementById('modalDetalle').style.display = 'none';
}

// Anular compra
function anularCompra(id) {
  const modal = document.createElement('div');
  modal.className = 'modal-overlay compras-modal-overlay active';
  modal.innerHTML = `
    <div class="modal-box">
      <h3 style="margin:0 0 16px;">⛔ Anular Compra #${id}</h3>
      <p style="margin-bottom:16px;">¿Estás seguro que querés anular esta compra?</p>
      
      <form method="post" id="formAnular">
        ${document.querySelector('input[name="csrf_token"]').outerHTML}
        <input type="hidden" name="accion" value="anular_confirmada">
        <input type="hidden" name="compra_id" value="${id}">
        
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer;">
          <input type="checkbox" name="revertir_stock" value="1" style="width:auto;">
          <span>Revertir stock (quitar productos del inventario)</span>
        </label>
        
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">
            Cancelar
          </button>
          <button type="submit" class="btn btn-primary" style="background:#ef4444;">
            Anular compra
          </button>
        </div>
      </form>
    </div>
  `;
  
  document.body.appendChild(modal);
  
  modal.addEventListener('click', (e) => {
    if (e.target === modal) modal.remove();
  });
}

// Cerrar modal con ESC
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    const modal = document.getElementById('modalDetalle');
    if (modal && modal.style.display !== 'none') {
      cerrarDetalle();
    }
    // También cerrar modal de anulación si existe
    const anularModal = document.querySelector('.compras-modal-overlay');
    if (anularModal) anularModal.remove();
  }
});
</script>

<?php require __DIR__ . "/partials/footer.php"; ?>