<?php
// public/install.php
declare(strict_types=1);

require_once __DIR__ . '/lib/session.php';
flus_session_start();
require_once __DIR__ . '/lib/csrf.php';
csrf_init();
require_once FLUS_ROOT . '/src/db_schema.php';

$cfgFile = FLUS_ROOT . '/src/config.php';
$example = FLUS_ROOT . '/src/config.example.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$err = '';

$schemaReady = false;
$schemaMsg = '';
$schemaErr = '';
$schemaLog = [];
$schemaDbName = 'kiosco';

$defaults = [
  'db_host' => '127.0.0.1',
  'db_port' => '3306',
  'db_name' => 'kiosco',
  'db_user' => 'root',
  'db_pass' => '',
];

$vals = $defaults;

if (is_file($cfgFile)) {
  $msg = 'Instalacion ya configurada. FLUS no reconfigura la base desde esta pantalla.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    $err = 'Token CSRF inválido. Recargá la pantalla e intentá de nuevo.';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_file($cfgFile) && $err === '') {
  $vals['db_host'] = trim((string)($_POST['db_host'] ?? $defaults['db_host']));
  $vals['db_port'] = trim((string)($_POST['db_port'] ?? $defaults['db_port']));
  $vals['db_name'] = trim((string)($_POST['db_name'] ?? $defaults['db_name']));
  $vals['db_user'] = trim((string)($_POST['db_user'] ?? $defaults['db_user']));
  $vals['db_pass'] = (string)($_POST['db_pass'] ?? $defaults['db_pass']);

  $host = $vals['db_host'];
  $port = (int)($vals['db_port'] !== '' ? $vals['db_port'] : '3306');
  $name = $vals['db_name'];
  $user = $vals['db_user'];
  $pass = $vals['db_pass'];

  if ($host === '' || $name === '' || $user === '') {
    $err = 'Completá host / db / user.';
  } else {
    try {
      $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
      $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      $pdo->query('SELECT 1');

      // Generar APP_SECRET fuerte (para tickets públicos, tokens, etc.)
      $appSecret = '';
      try {
        $appSecret = bin2hex(random_bytes(32));
      } catch (Throwable $e) {
        if (function_exists('openssl_random_pseudo_bytes')) {
          $appSecret = bin2hex(openssl_random_pseudo_bytes(32));
        } else {
          $appSecret = bin2hex((string)microtime(true) . (string)mt_rand());
        }
      }
      if (strlen($appSecret) < 32) { $appSecret = str_pad($appSecret, 64, '0'); }


      // Crear config.php (con CONSTANTES + SINGLETON - compatible con auth.php)
      $config = "<?php\n".
        "// src/config.php (generado por install.php)\n".
        "declare(strict_types=1);\n\n".
        "date_default_timezone_set('America/Argentina/Buenos_Aires');\n\n".
        "// ============================================\n".
        "// CONFIGURACIÓN DE BASE DE DATOS\n".
        "// ============================================\n".
        "define('DB_HOST', " . var_export($host, true) . ");\n".
        "define('DB_PORT', " . (int)$port . ");\n".
        "define('DB_NAME', " . var_export($name, true) . ");\n".
        "define('DB_USER', " . var_export($user, true) . ");\n".
        "define('DB_PASS', " . var_export($pass, true) . ");\n".
        "define('DB_CHARSET', 'utf8mb4');\n\n".
        "// ============================================\n".
        "// CONFIGURACIÓN DE APLICACIÓN\n".
        "// ============================================\n".
        "require_once __DIR__ . '/version.php';\n".
        "define('APP_DEBUG', false);  // true para desarrollo\n".
        "define('APP_NAME', 'FLUS');\n".
        "defined('APP_VERSION') || define('APP_VERSION', defined('FLUS_VERSION') ? FLUS_VERSION : 'dev');\n".
        "defined('APP_BUILD')   || define('APP_BUILD',   defined('FLUS_BUILD') ? FLUS_BUILD : '');\n".
        "define('APP_SECRET', " . var_export($appSecret, true) . ");\n\n".
        "// ============================================\n".
        "// CONEXION PDO\n".
        "// ============================================\n".
        "function flus_pdo_dsn(int \$connectTimeout = 0): string {\n".
        "    \$dsn = sprintf(\n".
        "        \"mysql:host=%s;port=%d;dbname=%s;charset=%s\",\n".
        "        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET\n".
        "    );\n\n".
        "    if (\$connectTimeout > 0) {\n".
        "        \$dsn .= ';connect_timeout=' . \$connectTimeout;\n".
        "    }\n\n".
        "    return \$dsn;\n".
        "}\n\n".
        "function flus_pdo_options(int \$timeout = 0): array {\n".
        "    \$options = [\n".
        "        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n".
        "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n".
        "        PDO::ATTR_EMULATE_PREPARES   => false,\n".
        "        PDO::ATTR_PERSISTENT         => false,\n".
        "    ];\n\n".
        "    if (\$timeout > 0) {\n".
        "        \$options[PDO::ATTR_TIMEOUT] = \$timeout;\n".
        "    }\n\n".
        "    return \$options;\n".
        "}\n\n".
        "function flus_pdo_exception_is_connection_lost(Throwable \$e): bool {\n".
        "    if (!\$e instanceof PDOException) {\n".
        "        return false;\n".
        "    }\n\n".
        "    \$message = \$e->getMessage();\n".
        "    return strpos(\$message, '2006') !== false\n".
        "        || strpos(\$message, '2013') !== false\n".
        "        || stripos(\$message, 'server has gone away') !== false\n".
        "        || stripos(\$message, 'lost connection') !== false;\n".
        "}\n\n".
        "function flus_pdo_exception_is_connectivity(Throwable \$e): bool {\n".
        "    if (!\$e instanceof PDOException) {\n".
        "        return false;\n".
        "    }\n\n".
        "    \$message = \$e->getMessage();\n".
        "    return strpos(\$message, '2002') !== false\n".
        "        || strpos(\$message, '(10061)') !== false\n".
        "        || strpos(\$message, '(10060)') !== false\n".
        "        || stripos(\$message, \"can't connect\") !== false\n".
        "        || flus_pdo_exception_is_connection_lost(\$e);\n".
        "}\n\n".
        "function flus_pdo_fresh(int \$timeout = 3): PDO {\n".
        "    \$pdo = new PDO(flus_pdo_dsn(\$timeout), DB_USER, DB_PASS, flus_pdo_options(\$timeout));\n".
        "    \$pdo->exec(\"SET time_zone = '-03:00'\");\n".
        "    return \$pdo;\n".
        "}\n\n".
        "function getPDO(): PDO {\n".
        "    static \$pdo = null;\n".
        "    static \$lastPingAt = 0.0;\n\n".
        "    \$now = microtime(true);\n".
        "    if (\$pdo instanceof PDO) {\n".
        "        if ((\$now - \$lastPingAt) < 2.0) {\n".
        "            return \$pdo;\n".
        "        }\n\n".
        "        try {\n".
        "            \$pdo->query('SELECT 1')->fetchColumn();\n".
        "            \$lastPingAt = \$now;\n".
        "            return \$pdo;\n".
        "        } catch (PDOException \$e) {\n".
        "            if (!flus_pdo_exception_is_connectivity(\$e)) {\n".
        "                throw \$e;\n".
        "            }\n".
        "            \$pdo = null;\n".
        "        }\n".
        "    }\n\n".
        "    \$pdo = flus_pdo_fresh();\n".
        "    \$lastPingAt = microtime(true);\n".
        "    return \$pdo;\n".
        "}\n";

      if (!is_dir(FLUS_ROOT . '/src')) {
        throw new RuntimeException('No existe /src');
      }

      file_put_contents($cfgFile, $config);

      // Crear carpetas básicas
      @mkdir(FLUS_ROOT . '/tmp', 0775, true);
      @mkdir(__DIR__ . '/img/productos', 0775, true);

      header('Location: install.php?step=schema');
      exit;

    } catch (Throwable $e) {
      error_log('[FLUS install] config failed: ' . $e->getMessage());
      $err = 'No se pudo crear la configuracion. Verifica host, base de datos, usuario, permisos de escritura y volve a intentar.';
    }
  }
}


// =============================
// PASO 2: crear estructura DB
// =============================
$step = trim((string)($_GET['step'] ?? ''));

if (is_file($cfgFile)) {
  try {
    // Cargar credenciales + PDO desde config.php
    require_once $cfgFile;
    if (defined('DB_NAME')) {
      $schemaDbName = (string)DB_NAME;
    }
    $pdo = null;
    if (function_exists('getPDO')) {
      $pdo = getPDO();
    }
    if (!$pdo instanceof PDO && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
      $pdo = $GLOBALS['pdo'];
    }
    if (!$pdo instanceof PDO) throw new RuntimeException('No se pudo inicializar PDO. Revisá src/config.php (getPDO).');

    $coreTables = ['users', 'productos', 'ventas'];
    $detectedTables = [];
    foreach ($coreTables as $table) {
      if (flus_table_exists($pdo, $table)) {
        $detectedTables[] = $table;
      }
    }

    $schemaReady = in_array('ventas', $detectedTables, true);

    if ($detectedTables === []) {
      $schemaMsg = 'Config OK. No se detecto el esquema base todavia. Instalacion limpia: importa install.sql y luego corre scripts/migrate.php.';
    } elseif ($schemaReady) {
      $schemaMsg = 'Estructura detectada. Ya podes entrar a FLUS.';
    } else {
      $schemaErr = 'La base de datos no esta vacia pero no se detecto el esquema esperado. Revisa DB_NAME o usa scripts/migrate.php desde consola.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_schema') {
      // En releases actuales no inicializamos schema desde el instalador web.
      // El baseline real es install.sql (import) + luego migraciones por CLI.
      $schemaErr = 'El instalador web no crea ni modifica tablas. Importa install.sql y luego ejecuta php scripts/migrate.php.';
    }

  } catch (Throwable $e) {
    error_log('[FLUS install] schema check failed: ' . $e->getMessage());
    $schemaErr = 'No se pudo validar la instalacion. Revisa la configuracion de base de datos y ejecuta scripts/migrate.php si corresponde.';
  }
}

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>FLUS - Instalacion</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;margin:32px;max-width:920px;color:#0f172a;background:#f5f7fb}
    .card{border:1px solid #d9e2ef;border-radius:12px;padding:18px;background:#fff;box-shadow:0 12px 36px rgba(15,23,42,.08)}
    label{display:block;margin-top:10px;font-weight:600}
    input{width:100%;padding:10px;border:1px solid #ccc;border-radius:10px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .btn{margin-top:14px;padding:10px 14px;border-radius:10px;border:0;background:#0ea5e9;color:#fff;font-weight:700;cursor:pointer}
    .msg{margin:12px 0;padding:10px;border-radius:10px}
    .ok{background:#ecfdf5;border:1px solid #10b981}
    .err{background:#fef2f2;border:1px solid #ef4444}
    .muted{color:#64748b}
    .steps{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:14px;margin-top:14px}
    .steps li{margin:6px 0}
    .split{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
    @media (max-width:760px){body{margin:18px}.row,.split{grid-template-columns:1fr}}
    code{background:#f4f4f4;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <h1>FLUS - Instalacion</h1>

  <?php if ($msg): ?>
    <div class="msg ok"><?=h($msg)?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="msg err"><?=h($err)?></div>
  <?php endif; ?>

  <div class="card">
    <p>Este asistente crea <code>src/config.php</code> y prueba conexion a la base de datos. El esquema se instala por consola para evitar cambios accidentales desde el navegador.</p>

    <?php if (!is_file($cfgFile)): ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="row">
          <div>
            <label>DB Host</label>
            <input name="db_host" value="<?=h($vals['db_host'])?>">
          </div>
          <div>
            <label>DB Port</label>
            <input name="db_port" value="<?=h($vals['db_port'])?>">
          </div>
        </div>

        <label>DB Name</label>
        <input name="db_name" value="<?=h($vals['db_name'])?>">

        <div class="row">
          <div>
            <label>DB User</label>
            <input name="db_user" value="<?=h($vals['db_user'])?>">
          </div>
          <div>
            <label>DB Pass</label>
            <input name="db_pass" value="<?=h($vals['db_pass'])?>" type="password">
          </div>
        </div>

        <button class="btn" type="submit">Crear config.php</button>
      </form>
    <?php else: ?>

      <?php if ($schemaErr): ?>
        <div class="msg err"><?=h($schemaErr)?></div>
      <?php endif; ?>
      <?php if ($schemaMsg): ?>
        <div class="msg ok"><?=h($schemaMsg)?></div>
      <?php endif; ?>

      <?php if (!empty($schemaLog)): ?>
        <div class="msg ok">
          <strong>Detalle:</strong><br>
          <?php foreach ($schemaLog as $l): ?>
            • <?=h((string)$l)?><br>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!$schemaReady): ?>
        <div class="steps">
          <strong>Instalacion limpia recomendada</strong>
          <ol>
            <li>Importa <code>install.sql</code> en la base <code><?=h($schemaDbName)?></code>.</li>
            <li>Ejecuta <code>php scripts/migrate.php</code> desde la carpeta del proyecto.</li>
            <li>Recarga esta pantalla para confirmar que el esquema quedo detectado.</li>
          </ol>
        </div>

      <?php else: ?>
        <div class="split">
          <div class="steps">
            <strong>Primer ingreso</strong>
            <p>Usuario inicial: <code>admin</code></p>
            <p>Clave provisoria: <code>flusadmin123</code></p>
            <p class="muted">Cambiala antes de cargar productos, ventas o datos reales del comercio.</p>
          </div>
          <div class="steps">
            <strong>Operacion segura</strong>
            <p>El instalador queda en modo solo lectura mientras exista <code>src/config.php</code>.</p>
            <p><a class="btn" href="login.php">Ir a login</a></p>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>

  <p class="muted" style="margin-top:18px">
    Archivo esperado: <code>src/config.php</code><br>
    Ejemplo: <code>src/config.example.php</code>
  </p>
</body>
</html>

