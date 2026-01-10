<?php
// config.example.php
// Copiar a "config.php" y completar credenciales
declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// ============================================
// CONFIGURACIÓN DE BASE DE DATOS
// ============================================
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'kiosco');
define('DB_USER', 'root');
define('DB_PASS', ''); // poner tu clave si corresponde
define('DB_CHARSET', 'utf8mb4');

// ============================================
// CONFIGURACIÓN DE APLICACIÓN
// ============================================
define('APP_DEBUG', false);  // false en producción
define('APP_NAME', 'FLUS');
define('APP_VERSION', '2.1.3');

// ============================================
// CONEXIÓN PDO (singleton)
// ============================================
function getPDO(): PDO {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=%s",
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        
        $pdo->exec("SET time_zone = '-03:00'");
    }
    
    return $pdo;
}
