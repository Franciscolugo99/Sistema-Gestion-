<?php
declare(strict_types=1);
// public/api/actions/promo_productos.php

try {
  $st = $pdo->query(
    'SELECT id, codigo, nombre
     FROM productos
     WHERE activo = 1
     ORDER BY nombre ASC'
  );
  $productos = $st->fetchAll(PDO::FETCH_ASSOC);
  json_ok(['productos' => $productos]);
} catch (Throwable $e) {
  json_fail('DB_ERROR', 500);
}
