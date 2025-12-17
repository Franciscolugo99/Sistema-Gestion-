<?php
// public/caja_historial.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('ver_historial_caja');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo  = getPDO();
$user = current_user();

/* --------------------------------------------------------
   Consulta de sesiones de caja (últimas 50)
-------------------------------------------------------- */
$sql = "
  SELECT
    cs.id,
    cs.fecha_apertura,
    cs.fecha_cierre,
    cs.saldo_inicial,
    cs.saldo_sistema,
    cs.saldo_declarado,
    cs.diferencia,
    cs.total_ventas,
    cs.total_efectivo,
    cs.total_mp,
    cs.total_debito,
    cs.total_credito,
    cs.total_productos,
    cs.total_anulaciones,
    u.username
  FROM caja_sesiones cs
  LEFT JOIN users u ON u.id = cs.user_id
  ORDER BY cs.id DESC
  LIMIT 50
";
$st = $pdo->query($sql);
$filas = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

/* --------------------------------------------------------
   Header global
-------------------------------------------------------- */
$pageTitle      = 'Historial de caja - FLUS';
$currentSection = 'caja_historial';
$extraCss       = ['assets/css/caja_historial.css?v=1'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel hist-panel">
  <h1 class="hist-title">Historial de caja</h1>
  <p class="hist-sub">Últimas sesiones de caja (aperturas y cierres).</p>

  <div class="hist-table-wrapper">
    <table class="hist-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Usuario</th>
          <th>Apertura</th>
          <th>Cierre</th>
          <th class="t-right">Saldo inicial</th>
          <th class="t-right">Total sistema</th>
          <th class="t-right">Declarado</th>
          <th class="t-right">Diferencia</th>
          <th class="t-right">Efectivo</th>
          <th class="t-right">MP</th>
          <th class="t-right">Débito</th>
          <th class="t-right">Crédito</th>
          <th class="t-right">Productos</th>
          <th class="t-right">Anulaciones</th>
        </tr>
      </thead>

      <tbody>
        <?php if (!$filas): ?>
          <tr>
            <td colspan="14" class="t-center" style="padding:14px; opacity:.75;">
              No hay sesiones para mostrar.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($filas as $r): ?>
            <?php
              $id        = (int)($r['id'] ?? 0);
              $username  = (string)($r['username'] ?? '—');

              $apertura  = (string)($r['fecha_apertura'] ?? '');
              $cierre    = (string)($r['fecha_cierre'] ?? '');

              $dif       = (float)($r['diferencia'] ?? 0);
              $difClass  = $dif > 0.00001 ? 'pill pill-pos' : ($dif < -0.00001 ? 'pill pill-neg' : 'pill pill-zero');

              $isOpen    = ($cierre === '' || $cierre === '0000-00-00 00:00:00');
            ?>

            <tr class="<?= $isOpen ? 'row-open' : '' ?>">
              <td class="mono"><?= $id ?></td>

              <td><?= h($username) ?></td>

              <td class="mono"><?= h($apertura) ?></td>

              <td class="mono">
                <?php if ($isOpen): ?>
                  <span class="pill pill-open">Abierta</span>
                <?php else: ?>
                  <?= h($cierre) ?>
                <?php endif; ?>
              </td>

              <td class="t-right"><?= money_ar($r['saldo_inicial'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['saldo_sistema'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['saldo_declarado'] ?? 0) ?></td>

              <td class="t-right">
                <span class="<?= h($difClass) ?>"><?= money_ar($dif) ?></span>
              </td>

              <td class="t-right"><?= money_ar($r['total_efectivo'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['total_mp'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['total_debito'] ?? 0) ?></td>
              <td class="t-right"><?= money_ar($r['total_credito'] ?? 0) ?></td>

              <td class="t-right"><?= (int)($r['total_productos'] ?? 0) ?></td>
              <td class="t-right"><?= (int)($r['total_anulaciones'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
