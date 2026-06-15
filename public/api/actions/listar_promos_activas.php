<?php
declare(strict_types=1);
// public/api/actions/listar_promos_activas.php

$pdo = $pdo ?? getPDO();
$promoStatus = flus_promos_status($pdo);

try {
  $promos = obtenerPromosActivas($pdo);
} catch (Throwable $e) {
  error_log('listar_promos_activas fallo: ' . $e->getMessage());
  $promos = ['simples' => [], 'combos' => []];
}

json_ok([
  'simples' => $promos['simples'] ?? [],
  'combos' => $promos['combos'] ?? [],
  'promos_estado' => $promoStatus,
]);
