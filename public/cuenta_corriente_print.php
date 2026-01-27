<?php
// public/cuenta_corriente_print.php
// FLUS - Impresión de estado de cuenta
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/CuentaCorrienteController.php';

require_login();
require_permission('ver_cuenta_corriente');

$pdo = getPDO();
$cc = new CuentaCorrienteController($pdo);

$clienteId = sanitize_int($_GET['id'] ?? 0);
if ($clienteId <= 0) {
    die('ID de cliente inválido');
}

$cliente = $cc->getClienteCC($clienteId);
if (!$cliente) {
    die('Cliente no encontrado');
}

// Obtener movimientos (últimos 100)
$resultado = $cc->getMovimientos($clienteId, ['per_page' => 100, 'page' => 1]);
$movimientos = $resultado['movimientos'];

$saldo = (float)$cliente['cc_saldo'];
$limite = (float)$cliente['cc_limite'];
$disponible = $limite - $saldo;

// Obtener config de negocio si existe
$nombreNegocio = 'FLUS';
$direccionNegocio = '';
$telefonoNegocio = '';
try {
    $stConfig = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('nombre_negocio', 'direccion', 'telefono')");
    while ($row = $stConfig->fetch(PDO::FETCH_ASSOC)) {
        match($row['clave']) {
            'nombre_negocio' => $nombreNegocio = $row['valor'],
            'direccion' => $direccionNegocio = $row['valor'],
            'telefono' => $telefonoNegocio = $row['valor'],
            default => null
        };
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Estado de Cuenta - <?= h($cliente['nombre']) ?></title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      font-size: 12px;
      line-height: 1.4;
      color: #1a1a1a;
      background: white;
      padding: 20px;
    }
    
    .print-container {
      max-width: 800px;
      margin: 0 auto;
    }
    
    /* Header */
    .print-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding-bottom: 15px;
      border-bottom: 2px solid #1a1a1a;
      margin-bottom: 20px;
    }
    
    .negocio-info h1 {
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 4px;
    }
    
    .negocio-info p {
      font-size: 11px;
      color: #666;
    }
    
    .doc-info {
      text-align: right;
    }
    
    .doc-info h2 {
      font-size: 14px;
      font-weight: 700;
      margin-bottom: 4px;
    }
    
    .doc-info p {
      font-size: 11px;
      color: #666;
    }
    
    /* Cliente */
    .cliente-section {
      display: flex;
      justify-content: space-between;
      padding: 15px;
      background: #f5f5f5;
      border-radius: 6px;
      margin-bottom: 20px;
    }
    
    .cliente-datos h3 {
      font-size: 14px;
      font-weight: 700;
      margin-bottom: 4px;
    }
    
    .cliente-datos p {
      font-size: 11px;
      color: #666;
    }
    
    .saldos-box {
      text-align: right;
    }
    
    .saldo-principal {
      font-size: 20px;
      font-weight: 800;
    }
    
    .saldo-principal.deuda {
      color: #dc2626;
    }
    
    .saldo-info {
      font-size: 10px;
      color: #666;
      margin-top: 4px;
    }
    
    /* Tabla */
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
    }
    
    th {
      background: #1a1a1a;
      color: white;
      padding: 8px 6px;
      text-align: left;
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    
    th.r { text-align: right; }
    
    td {
      padding: 8px 6px;
      border-bottom: 1px solid #e5e5e5;
      font-size: 11px;
    }
    
    td.r { text-align: right; }
    td.mono { font-family: ui-monospace, monospace; }
    
    tr.anulado {
      opacity: 0.5;
      text-decoration: line-through;
    }
    
    .badge {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 9px;
      font-weight: 600;
      text-transform: uppercase;
    }
    
    .badge-cargo { background: #fee2e2; color: #dc2626; }
    .badge-pago { background: #dcfce7; color: #16a34a; }
    .badge-ajuste { background: #fef3c7; color: #d97706; }
    .badge-reversa { background: #e5e5e5; color: #666; }
    
    .text-danger { color: #dc2626; }
    .text-success { color: #16a34a; }
    
    /* Footer */
    .print-footer {
      padding-top: 15px;
      border-top: 1px solid #e5e5e5;
      font-size: 10px;
      color: #666;
      display: flex;
      justify-content: space-between;
    }
    
    /* Print styles */
    @media print {
      body { padding: 0; }
      .no-print { display: none !important; }
      .print-container { max-width: 100%; }
    }
    
    /* Botón imprimir */
    .btn-print {
      position: fixed;
      bottom: 20px;
      right: 20px;
      padding: 12px 24px;
      background: #1a1a1a;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
    }
    
    .btn-print:hover {
      background: #333;
    }
  </style>
</head>
<body>
  <div class="print-container">
    <!-- Header -->
    <div class="print-header">
      <div class="negocio-info">
        <h1><?= h($nombreNegocio) ?></h1>
        <?php if ($direccionNegocio): ?>
          <p><?= h($direccionNegocio) ?></p>
        <?php endif; ?>
        <?php if ($telefonoNegocio): ?>
          <p>Tel: <?= h($telefonoNegocio) ?></p>
        <?php endif; ?>
      </div>
      <div class="doc-info">
        <h2>ESTADO DE CUENTA</h2>
        <p>Fecha: <?= date('d/m/Y H:i') ?></p>
      </div>
    </div>
    
    <!-- Cliente -->
    <div class="cliente-section">
      <div class="cliente-datos">
        <h3><?= h($cliente['nombre']) ?></h3>
        <?php if ($cliente['cuit']): ?>
          <p>CUIT: <?= h($cliente['cuit']) ?></p>
        <?php endif; ?>
        <?php if ($cliente['telefono']): ?>
          <p>Tel: <?= h($cliente['telefono']) ?></p>
        <?php endif; ?>
        <?php if ($cliente['direccion']): ?>
          <p><?= h($cliente['direccion']) ?></p>
        <?php endif; ?>
      </div>
      <div class="saldos-box">
        <div class="saldo-principal <?= $saldo > 0 ? 'deuda' : '' ?>">
          <?= $saldo > 0 ? 'Debe: ' : 'Saldo: ' ?>$<?= number_format(abs($saldo), 2, ',', '.') ?>
        </div>
        <div class="saldo-info">
          Límite: $<?= number_format($limite, 2, ',', '.') ?> | 
          Disponible: $<?= number_format(max(0, $disponible), 2, ',', '.') ?>
        </div>
      </div>
    </div>
    
    <!-- Movimientos -->
    <table>
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Tipo</th>
          <th>Concepto</th>
          <th class="r">Debe</th>
          <th class="r">Haber</th>
          <th class="r">Saldo</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($movimientos)): ?>
          <tr>
            <td colspan="6" style="text-align:center;padding:20px;color:#666;">
              Sin movimientos registrados
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($movimientos as $mov): ?>
            <?php
              $tipo = $mov['tipo'];
              $estado = $mov['estado'];
              $monto = (float)$mov['monto'];
              $saldoPost = (float)$mov['saldo_posterior'];
              $esAnulado = $estado === 'ANULADO';
              
              $esDebe = in_array($tipo, ['CARGO', 'AJUSTE_POS']);
              if ($tipo === 'REVERSA') {
                  $esDebe = $saldoPost > (float)$mov['saldo_anterior'];
              }
              
              $badgeClass = match($tipo) {
                  'CARGO' => 'badge-cargo',
                  'PAGO' => 'badge-pago',
                  'AJUSTE_POS', 'AJUSTE_NEG' => 'badge-ajuste',
                  'REVERSA' => 'badge-reversa',
                  default => ''
              };
              
              $tipoLabel = match($tipo) {
                  'CARGO' => 'Cargo',
                  'PAGO' => 'Pago',
                  'AJUSTE_POS' => 'Ajuste +',
                  'AJUSTE_NEG' => 'Ajuste -',
                  'REVERSA' => 'Reversa',
                  default => $tipo
              };
            ?>
            <tr class="<?= $esAnulado ? 'anulado' : '' ?>">
              <td class="mono"><?= date('d/m/Y', strtotime($mov['created_at'])) ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= $tipoLabel ?></span></td>
              <td><?= h($mov['concepto'] ?? '-') ?></td>
              <td class="r mono text-danger">
                <?= $esDebe && !$esAnulado ? '$' . number_format($monto, 2, ',', '.') : '' ?>
              </td>
              <td class="r mono text-success">
                <?= !$esDebe && !$esAnulado ? '$' . number_format($monto, 2, ',', '.') : '' ?>
              </td>
              <td class="r mono" style="font-weight:600;">
                $<?= number_format($saldoPost, 2, ',', '.') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    
    <!-- Footer -->
    <div class="print-footer">
      <span>Generado por FLUS</span>
      <span><?= date('d/m/Y H:i:s') ?></span>
    </div>
  </div>
  
  <button class="btn-print no-print" onclick="window.print()">
    🖨️ Imprimir
  </button>
</body>
</html>
