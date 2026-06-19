<?php
declare(strict_types=1);

/**
 * scripts/migrate.php
 * Runner CLI para aplicar migraciones en orden.
 *
 * Uso:
 *   php scripts/migrate.php
 *
 * Requiere:
 *   src/config.php (o config.example.php para pruebas)
 */

$root = realpath(__DIR__ . '/..');
if (!$root) {
  fwrite(STDERR, "No se pudo resolver root\n");
  exit(1);
}

$cfg = $root . '/src/config.php';
if (!is_file($cfg)) {
  $cfg = $root . '/src/config.example.php';
}
if (!is_file($cfg)) {
  fwrite(STDERR, "No existe src/config.php ni src/config.example.php\n");
  exit(1);
}

require_once $cfg;

/**
 * Obtiene un PDO:
 *  1) usa $GLOBALS['pdo'] si está definido
 *  2) si no, crea una conexión desde constantes DB_*
 *  3) último recurso: getPDO() si existe (ojo: tu getPDO() puede die() ante error)
 */
function flus_get_pdo_or_fail(): PDO
{
  // 1) Compat: si el proyecto dejó un $pdo global
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    return $GLOBALS['pdo'];
  }

  // 2) Construir desde constantes DB_*
  if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
    if (!extension_loaded('pdo')) {
      throw new RuntimeException("Extensión PDO no cargada (php.ini).");
    }
    if (!extension_loaded('pdo_mysql')) {
      throw new RuntimeException("Extensión pdo_mysql no cargada (habilitá extension=pdo_mysql en php.ini).");
    }

    $host = (string)DB_HOST;
    $name = (string)DB_NAME;
    $user = (string)DB_USER;
    $pass = (string)DB_PASS;

    $port = defined('DB_PORT') ? (string)DB_PORT : '3306';
    $charset = defined('DB_CHARSET') ? (string)DB_CHARSET : 'utf8mb4';

    $dsn = sprintf(
      "mysql:host=%s;port=%s;dbname=%s;charset=%s",
      $host,
      $port,
      $name,
      $charset
    );

    $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      // Importante: en runtime normal podés querer false; para migraciones lo cambiamos luego a true.
      PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
      return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
      throw new RuntimeException("Error conectando a BD con PDO: " . $e->getMessage(), 0, $e);
    }
  }

  // 3) Último recurso: usar getPDO()
  if (function_exists('getPDO')) {
    $pdo = getPDO();
    if ($pdo instanceof PDO) return $pdo;
  }

  throw new RuntimeException("No pude inicializar PDO. Revisá constantes DB_* en src/config.php.");
}

// Obtener PDO
try {
  $pdo = flus_get_pdo_or_fail();

  // ✅ FIX 1295:
  // Para migraciones, TRIGGER/VIEW y algunos comandos no soportan prepared statements del servidor.
  // Emular prepares hace que PDO mande el SQL directo y evita el error:
  // "This command is not supported in the prepared statement protocol yet"
  $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

} catch (Throwable $e) {
  fwrite(STDERR, "ERROR PDO: " . $e->getMessage() . "\n");
  exit(1);
}

require_once $root . '/src/migrations_runner.php';

$migrationsDir = $root . '/migrations';
if (!is_dir($migrationsDir)) {
  fwrite(STDERR, "No existe carpeta migrations/\n");
  exit(1);
}

try {
  $res = flus_apply_migrations($pdo, $migrationsDir, true);

  echo "OK - Migraciones aplicadas\n";

  if (!empty($res['baseline'])) {
    echo "Baseline: " . implode(', ', $res['baseline']) . "\n";
  }

  if (!empty($res['sequence_collisions'])) {
    foreach ($res['sequence_collisions'] as $sequence => $files) {
      echo 'AVISO - numeracion historica duplicada '
        . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT)
        . ': ' . implode(', ', $files) . "\n";
    }
  }

  if (!empty($res['applied'])) {
    foreach ($res['applied'] as $a) {
      echo " - " . $a['file'] . " (" . $a['statements'] . " statements)\n";
    }
  } else {
    echo " - Nada para aplicar\n";
  }

} catch (Throwable $e) {
  fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
  exit(1);
}
