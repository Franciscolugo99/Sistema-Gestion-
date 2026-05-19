<?php
// config.example.php
// Copiar a "config.php" y completar credenciales
declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

$flusVersionFile = __DIR__ . '/version.php';
if (is_file($flusVersionFile)) {
    require_once $flusVersionFile;
}

if (!function_exists('flus_env')) {
    function flus_env(string $name, string $default = ''): string {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        return (string)$value;
    }
}

if (!function_exists('flus_env_bool')) {
    function flus_env_bool(string $name, bool $default = false): bool {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

// ============================================
// CONFIGURACION DE BASE DE DATOS
// ============================================
define('DB_HOST', flus_env('FLUS_DB_HOST', flus_env('DB_HOST', '127.0.0.1')));
define('DB_PORT', (int)flus_env('FLUS_DB_PORT', flus_env('DB_PORT', '3306')));
define('DB_NAME', flus_env('FLUS_DB_NAME', flus_env('DB_NAME', 'kiosco')));
define('DB_USER', flus_env('FLUS_DB_USER', flus_env('DB_USER', 'root')));
define('DB_PASS', flus_env('FLUS_DB_PASS', flus_env('DB_PASS', '')));
define('DB_CHARSET', flus_env('FLUS_DB_CHARSET', flus_env('DB_CHARSET', 'utf8mb4')));

// ============================================
// CONFIGURACION DE APLICACION
// ============================================
$flusAppEnv = strtolower(flus_env('FLUS_APP_ENV', flus_env('APP_ENV', 'production')));
if (!in_array($flusAppEnv, ['production', 'development', 'testing'], true)) {
    $flusAppEnv = 'production';
}
define('APP_ENV', $flusAppEnv); // production | development | testing
define('APP_DEBUG', flus_env_bool('FLUS_APP_DEBUG', APP_ENV !== 'production' && APP_ENV !== 'testing'));
define('APP_NAME', 'FLUS');
define('APP_VERSION', defined('FLUS_VERSION') ? FLUS_VERSION : '4.0.0');
define('APP_BUILD', defined('FLUS_BUILD') ? FLUS_BUILD : '');
define('APP_SECRET', 'flus-default-secret-change-me'); // reemplazar por un secreto fuerte y persistente

// ============================================
// CONEXION PDO
// ============================================
if (!function_exists('flus_pdo_dsn')) {
    function flus_pdo_dsn(int $connectTimeout = 0): string {
        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=%s",
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        if ($connectTimeout > 0) {
            $dsn .= ';connect_timeout=' . $connectTimeout;
        }

        return $dsn;
    }
}

if (!function_exists('flus_pdo_options')) {
    function flus_pdo_options(int $timeout = 0): array {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        if ($timeout > 0) {
            $options[PDO::ATTR_TIMEOUT] = $timeout;
        }

        return $options;
    }
}

if (!function_exists('flus_pdo_exception_is_connection_lost')) {
    function flus_pdo_exception_is_connection_lost(Throwable $e): bool {
        if (!$e instanceof PDOException) {
            return false;
        }

        $message = $e->getMessage();
        return strpos($message, '2006') !== false
            || strpos($message, '2013') !== false
            || stripos($message, 'server has gone away') !== false
            || stripos($message, 'lost connection') !== false;
    }
}

if (!function_exists('flus_pdo_exception_is_connectivity')) {
    function flus_pdo_exception_is_connectivity(Throwable $e): bool {
        if (!$e instanceof PDOException) {
            return false;
        }

        $message = $e->getMessage();
        return strpos($message, '2002') !== false
            || strpos($message, '(10061)') !== false
            || strpos($message, '(10060)') !== false
            || stripos($message, "can't connect") !== false
            || flus_pdo_exception_is_connection_lost($e);
    }
}

if (!function_exists('flus_pdo_fresh')) {
    function flus_pdo_fresh(int $timeout = 3): PDO {
        $pdo = new PDO(flus_pdo_dsn($timeout), DB_USER, DB_PASS, flus_pdo_options($timeout));
        $pdo->exec("SET time_zone = '-03:00'");
        return $pdo;
    }
}

if (!function_exists('getPDO')) {
    function getPDO(): PDO {
        static $pdo = null;
        static $lastPingAt = 0.0;

        $now = microtime(true);
        if ($pdo instanceof PDO) {
            if (($now - $lastPingAt) < 2.0) {
                return $pdo;
            }

            try {
                $pdo->query('SELECT 1')->fetchColumn();
                $lastPingAt = $now;
                return $pdo;
            } catch (PDOException $e) {
                if (!flus_pdo_exception_is_connectivity($e)) {
                    throw $e;
                }
                $pdo = null;
            }
        }

        $pdo = flus_pdo_fresh();
        $lastPingAt = microtime(true);
        return $pdo;
    }
}
