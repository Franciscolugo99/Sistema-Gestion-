<?php
// config.php

// 1) Zona horaria PHP (Argentina)
date_default_timezone_set('America/Argentina/Buenos_Aires');

$DB_HOST = 'localhost';
$DB_NAME = 'kiosco';
$DB_USER = 'root';
$DB_PASS = ''; // si tenés clave distinta, cambiala acá

function getPDO() {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

    $dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";

    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 2) Zona horaria MySQL (misma que PHP: -03:00)
    // (Esto afecta NOW(), CURDATE(), y comparaciones con DATE(fecha))
    $pdo->exec("SET time_zone = '-03:00'");

    return $pdo;
}
