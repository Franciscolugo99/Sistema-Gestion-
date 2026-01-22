<?php
// public/api/cliente_consultar_cuit.php
// Stub de compatibilidad: redirige a /api/actions/cliente_consultar_cuit.php

declare(strict_types=1);

$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'actions/cliente_consultar_cuit.php' . ($qs ? ('?' . $qs) : '');

header('Location: ' . $target, true, 302);
exit;
