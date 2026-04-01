<?php
declare(strict_types=1);
// public/api/actions/buscar_clientes_cc.php

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
  json_ok(['clientes' => []]);
}

$pdo = $pdo ?? getPDO();

$sql = "
  SELECT id, nombre, cuit, telefono, cc_saldo, cc_limite,
         (cc_limite - cc_saldo) AS cc_disponible
  FROM clientes
  WHERE activo = 1
    AND cc_habilitado = 1
    AND (nombre LIKE ? OR telefono LIKE ? OR cuit LIKE ?)
  ORDER BY cc_saldo DESC, nombre ASC
  LIMIT 10
";

try {
  $st = $pdo->prepare($sql);
  $like = '%' . $q . '%';
  $st->execute([$like, $like, $like]);
  $clientes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($clientes as &$cliente) {
    $cliente['id'] = (int)($cliente['id'] ?? 0);
    $cliente['cc_saldo'] = (float)($cliente['cc_saldo'] ?? 0);
    $cliente['cc_limite'] = (float)($cliente['cc_limite'] ?? 0);
    $cliente['cc_disponible'] = (float)($cliente['cc_disponible'] ?? 0);
  }
  unset($cliente);

  json_ok(['clientes' => $clientes]);
} catch (Throwable $e) {
  error_log('buscar_clientes_cc fallo: ' . $e->getMessage());
  json_fail('DB_ERROR', 500);
}
