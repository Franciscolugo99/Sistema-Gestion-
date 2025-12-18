<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo  = getPDO();
$user = current_user();

audit_log($pdo, (int)$user['id'], 'TEST', 'SISTEMA', 'debug', null, [
  'msg' => 'Prueba auditoría OK',
  'ts'  => date('c'),
]);

echo "OK: log insertado";
