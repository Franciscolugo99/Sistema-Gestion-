<?php
// public/factura_nueva.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

/* =========================================================
   1) OBTENER VENTA
========================================================= */
$ventaId = isset($_GET['venta_id']) ? (int)$_GET['venta_id'] : 0;
if ($ventaId <= 0) {
  header('Location: ventas.php');
  exit;
}

$stVenta = $pdo->prepare("
  SELECT id, fecha, total
  FROM ventas
  WHERE id = ?
  LIMIT 1
");
$stVenta->execute([$ventaId]);
$venta = $stVenta->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
  header('Location: ventas.php');
  exit;
}

/* =========================================================
   2) SI YA ESTÁ FACTURADA ESTA VENTA -> REDIRIGIR
========================================================= */
$stExiste = $pdo->prepare("
  SELECT id
  FROM facturas
  WHERE venta_id = ?
  ORDER BY id DESC
  LIMIT 1
");
$stExiste->execute([$ventaId]);
$ya = $stExiste->fetch(PDO::FETCH_ASSOC);

if ($ya && !isset($_GET['force'])) {
  header('Location: factura_ver.php?id=' . (int)$ya['id']);
  exit;
}

/* =========================================================
   3) CONFIG FACTURACIÓN ACTIVA
========================================================= */
$cfgError = null;

$stCfg = $pdo->query("
  SELECT *
  FROM config_facturacion
  WHERE activo = 1
  ORDER BY id DESC
  LIMIT 1
");
$config = $stCfg ? $stCfg->fetch(PDO::FETCH_ASSOC) : null;

if (!$config) {
  $cfgError = "Falta configurar la facturación (config_facturacion).";
}

/* =========================================================
   4) CLIENTES ACTIVOS
========================================================= */
$clientes = $pdo->query("
  SELECT id, nombre, cuit, cond_iva
  FROM clientes
  WHERE activo = 1
  ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   5) POST: EMITIR FACTURA
========================================================= */
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($cfgError)) {

  // CSRF
  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $errores[] = "Sesión vencida (CSRF). Actualizá la página e intentá de nuevo.";
  }

  $clienteId = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;

  if ($clienteId <= 0) {
    $errores[] = "Tenés que seleccionar un cliente.";
  } else {
    $st = $pdo->prepare("SELECT id FROM clientes WHERE id = ? AND activo = 1 LIMIT 1");
    $st->execute([$clienteId]);
    if (!$st->fetch()) {
      $errores[] = "El cliente seleccionado no es válido.";
    }
  }

  if (empty($errores)) {
    try {
      $pdo->beginTransaction();

      // Re-validar venta dentro de transacción (por seguridad)
      $stVenta2 = $pdo->prepare("SELECT id, fecha, total FROM ventas WHERE id = ? LIMIT 1");
      $stVenta2->execute([$ventaId]);
      $venta2 = $stVenta2->fetch(PDO::FETCH_ASSOC);
      if (!$venta2) {
        throw new Exception("La venta ya no existe.");
      }

      // Evitar doble facturación concurrente
      $stExiste2 = $pdo->prepare("
        SELECT id
        FROM facturas
        WHERE venta_id = ?
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
      ");
      $stExiste2->execute([$ventaId]);
      $ya2 = $stExiste2->fetch(PDO::FETCH_ASSOC);

      if ($ya2) {
        // ya existe: devolvemos a la existente
        $pdo->commit();
        header('Location: factura_ver.php?id=' . (int)$ya2['id']);
        exit;
      }

      // Lock de config para no repetir número
      $stCfgLock = $pdo->prepare("
        SELECT *
        FROM config_facturacion
        WHERE id = ?
        FOR UPDATE
      ");
      $stCfgLock->execute([(int)$config['id']]);
      $cfg = $stCfgLock->fetch(PDO::FETCH_ASSOC);

      if (!$cfg || (int)$cfg['activo'] !== 1) {
        throw new Exception("No se encontró configuración activa de facturación.");
      }

      $puntoVenta = (int)$cfg['punto_venta'];
      $tipo       = (string)$cfg['tipo_comprobante'];  // ej: FACTURA A/B/C
      $numero     = (int)$cfg['proximo_numero'];

      if ($puntoVenta <= 0) throw new Exception("Punto de venta inválido.");
      if ($numero <= 0)     throw new Exception("Próximo número inválido.");

      $total = (float)$venta2['total'];

      // Insertar factura (IMPORTANTE: usamos facturas.tipo, no tipo_comprobante)
      $stIns = $pdo->prepare("
        INSERT INTO facturas
          (fecha, venta_id, cliente_id, punto_venta, tipo, numero, total, estado)
        VALUES
          (NOW(), ?, ?, ?, ?, ?, ?, 'EMITIDA')
      ");
      $stIns->execute([
        $ventaId,
        $clienteId,
        $puntoVenta,
        $tipo,
        $numero,
        $total
      ]);

      $facturaId = (int)$pdo->lastInsertId();

      // Incrementar contador
      $stUpdCfg = $pdo->prepare("
        UPDATE config_facturacion
        SET proximo_numero = proximo_numero + 1
        WHERE id = ?
      ");
      $stUpdCfg->execute([(int)$cfg['id']]);

      $pdo->commit();

      // Mejor UX: ir directo a ver/imprimir
      header('Location: factura_ver.php?id=' . $facturaId);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errores[] = "Error al emitir la factura: " . $e->getMessage();
    }
  }
}

/* =========================================================
   HEADER
========================================================= */
$pageTitle      = "Nueva factura";
$currentSection = "facturacion";
$extraCss       = ["assets/css/facturacion.css?v=1"];

require __DIR__ . "/partials/header.php";
?>

<div class="page-wrap">
  <div class="panel fact-panel">

    <header class="page-header with-back">
      <div class="page-header-left">
        <a href="facturacion.php" class="link-back">← Volver a facturación</a>
        <h1 class="page-title">Nueva factura</h1>
        <p class="page-sub">
          Emitir comprobante a partir de la venta #<?= (int)$venta['id'] ?>.
        </p>
      </div>
    </header>

    <?php if (!empty($cfgError)): ?>
      <div class="alert alert-error" style="margin-top:12px;">
        <?= h($cfgError) ?>
      </div>
    <?php endif; ?>

    <section class="fact-venta-resumen">
      <h2 class="sub-title-page">Resumen de venta</h2>
      <div class="fact-venta-grid">
        <div>
          <div class="muted">Venta</div>
          <div>#<?= (int)$venta['id'] ?></div>
        </div>
        <div>
          <div class="muted">Fecha</div>
          <div><?= h($venta['fecha']) ?></div>
        </div>
        <div>
          <div class="muted">Total</div>
          <div class="mono"><?= money_ar($venta['total']) ?></div>
        </div>
        <div>
          <div class="muted">Tipo comprobante</div>
          <?php if (!empty($config)): ?>
            <div><?= h($config['tipo_comprobante']) ?> – PV <?= str_pad((string)$config['punto_venta'], 4, '0', STR_PAD_LEFT) ?></div>
          <?php else: ?>
            <div class="muted">Sin configuración</div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="fact-form-section" style="margin-top:18px;">
      <h2 class="sub-title-page">Datos del cliente</h2>

      <form method="post" class="fact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="venta_id" value="<?= (int)$ventaId ?>">

        <div class="fact-form-grid">
          <div class="ff-field ff-field-wide">
            <label>Cliente</label>
            <select name="cliente_id" required <?= !empty($cfgError) ? 'disabled' : '' ?>>
              <option value="">Seleccionar cliente...</option>
              <?php foreach ($clientes as $cli): ?>
                <option
                  value="<?= (int)$cli['id'] ?>"
                  <?= (isset($_POST['cliente_id']) && (int)$_POST['cliente_id'] === (int)$cli['id']) ? 'selected' : '' ?>
                >
                  <?= h($cli['nombre']) ?>
                  <?php if (!empty($cli['cuit'])): ?>
                    (<?= h($cli['cuit']) ?>)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="pf-actions" style="margin-top:18px;">
          <button type="submit" class="btn btn-primary" <?= !empty($cfgError) ? 'disabled' : '' ?>>
            Emitir factura
          </button>

          <a href="facturacion.php" class="btn btn-secondary">
            Cancelar
          </a>
        </div>

        <?php if (!empty($errores)): ?>
          <div class="msg msg-visible msg-error" style="margin-top:12px;">
            <ul>
              <?php foreach ($errores as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

      </form>
    </section>
  </div>
</div>

<?php require __DIR__ . "/partials/footer.php"; ?>
