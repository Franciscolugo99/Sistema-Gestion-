<?php
declare(strict_types=1);
// public/api/actions/verificar_cc.php

$clienteId = (int)($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);
$monto = parse_num($_GET['monto'] ?? $_POST['monto'] ?? 0);

if ($clienteId <= 0) {
  json_fail('Cliente invalido', 400);
}

$pdo = $pdo ?? getPDO();
$ccCtrl = new CuentaCorrienteController($pdo);

try {
  $cliente = $ccCtrl->getClienteCC($clienteId);
  if (!$cliente) {
    json_fail('Cliente no encontrado', 404);
  }

  if (!($cliente['cc_habilitado'] ?? false)) {
    json_ok([
      'habilitado' => false,
      'puede_comprar' => false,
      'mensaje' => 'Cliente sin cuenta corriente habilitada',
    ]);
  }

  $saldo = (float)($cliente['cc_saldo'] ?? 0);
  $limite = (float)($cliente['cc_limite'] ?? 0);
  $disponible = $limite - $saldo;
  $excede = ($saldo + $monto) > $limite;
  $puedeAutorizar = function_exists('user_has_permission') && user_has_permission('vender_excedido_cc');
  $puedeComprar = !$excede || $puedeAutorizar;

  $mensaje = '';
  if ($excede) {
    $mensaje = $puedeAutorizar
      ? 'Excede limite por $' . number_format($monto - $disponible, 2, ',', '.') . ' (autorizado)'
      : 'Excede limite. Disponible: $' . number_format($disponible, 2, ',', '.');
  }

  json_ok([
    'habilitado' => true,
    'puede_comprar' => $puedeComprar,
    'excede' => $excede,
    'puede_autorizar' => $puedeAutorizar,
    'saldo_actual' => $saldo,
    'limite' => $limite,
    'disponible' => $disponible,
    'monto_solicitado' => $monto,
    'mensaje' => $mensaje,
  ]);
} catch (Throwable $e) {
  error_log('verificar_cc fallo: ' . $e->getMessage());
  json_fail('DB_ERROR', 500);
}
