<?php
// public/ventas.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/VentasController.php';

(new VentasController())->index();
