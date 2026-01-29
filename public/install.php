<?php
// public/install.php
declare(strict_types=1);

require_once __DIR__ . '/lib/session.php';
flus_session_start();

$cfgFile = FLUS_ROOT . '/src/config.php';
$example = FLUS_ROOT . '/src/config.example.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$err = '';

$schemaReady = false;
$schemaMsg = '';
$schemaErr = '';
$schemaLog = [];

$defaults = [
  'db_host' => '127.0.0.1',
  'db_port' => '3306',
  'db_name' => 'kiosco',
  'db_user' => 'root',
  'db_pass' => '',
];

$vals = $defaults;

if (is_file($cfgFile)) {
  $msg = '✅ Ya existe src/config.php. Si querés reconfigurar, borrá ese archivo primero.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_file($cfgFile)) {
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
        "// CONEXIÓN PDO (singleton)\n".
        "// ============================================\n".
        "function getPDO(): PDO {\n".
        "    static \$pdo = null;\n\n".
        "    if (\$pdo === null) {\n".
        "        \$dsn = sprintf(\n".
        "            \"mysql:host=%s;port=%d;dbname=%s;charset=%s\",\n".
        "            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET\n".
        "        );\n\n".
        "        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [\n".
        "            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n".
        "            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n".
        "            PDO::ATTR_EMULATE_PREPARES   => false,\n".
        "        ]);\n\n".
        "        \$pdo->exec(\"SET time_zone = '-03:00'\");\n".
        "    }\n\n".
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
      $err = 'No se pudo conectar a la DB o escribir config.php. Detalle: ' . $e->getMessage();
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
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) throw new RuntimeException('No se pudo inicializar PDO. Revisá src/config.php.');

    // Detectar si la DB está vacía (sin tablas)
    $dbEmpty = true;
    $st = $pdo->query('SHOW TABLES');
    if ($st) {
      $rows = $st->fetchAll(PDO::FETCH_NUM);
      foreach ($rows as $r) {
        if (!empty($r[0])) { $dbEmpty = false; break; }
      }
    }

    // Detectar schema mínimo (tabla ventas)
    $schemaReady = false;
    if (!$dbEmpty) {
      $chk = $pdo->query("SHOW TABLES LIKE 'ventas'");
      $schemaReady = (bool)($chk && $chk->fetchColumn());
    }

    if ($dbEmpty) {
      $schemaMsg = '✅ Config OK. La base de datos está vacía: podés crear la estructura.';
    } elseif ($schemaReady) {
      $schemaMsg = '✅ Estructura detectada. Ya podés ir a login.';
    } else {
      $schemaErr = '⚠️ La base de datos no está vacía pero no se detectó el esquema esperado. Recomendado: revisar DB_NAME o usar el runner CLI scripts/migrate.php.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_schema') {
      if (!$dbEmpty) {
        throw new RuntimeException('La base de datos no está vacía. No se puede inicializar desde install.php.');
      }

      require_once FLUS_ROOT . '/src/migrations_runner.php';
      $res = flus_apply_migrations($pdo, FLUS_ROOT . '/migrations', false);

      $schemaLog[] = 'Migraciones aplicadas: ' . count($res['applied']);

      // Crear usuario admin (recomendado)
      $adminNombre = trim((string)($_POST['admin_nombre'] ?? 'Administrador'));
      $adminEmail  = trim((string)($_POST['admin_email']  ?? 'admin@local'));
      $adminUser   = trim((string)($_POST['admin_user']   ?? 'admin'));
      $adminPass   = (string)($_POST['admin_pass'] ?? '');

      if ($adminPass === '' || strlen($adminPass) < 6) {
        throw new RuntimeException('La contraseña del admin debe tener al menos 6 caracteres.');
      }

      $hash = password_hash($adminPass, PASSWORD_DEFAULT);

      // Insertar admin si no existe
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
      $stmt->execute([$adminUser]);
      $exists = (int)$stmt->fetchColumn() > 0;

      if (!$exists) {
        $ins = $pdo->prepare("INSERT INTO users (nombre, email, username, password_hash, role_id, activo) VALUES (?,?,?,?,1,1)");
        $ins->execute([$adminNombre, $adminEmail, $adminUser, $hash]);
        $schemaLog[] = "Usuario admin creado: {$adminUser}";
      } else {
        $schemaLog[] = "Usuario admin ya existía: {$adminUser}";
      }

      $schemaReady = true;
      $schemaMsg = '✅ Estructura creada. Ya podés ir a login.';
    }

  } catch (Throwable $e) {
    $schemaErr = $e->getMessage();
  }
}

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>FLUS - Instalación</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;margin:32px;max-width:820px}
    .card{border:1px solid #ddd;border-radius:12px;padding:18px}
    label{display:block;margin-top:10px;font-weight:600}
    input{width:100%;padding:10px;border:1px solid #ccc;border-radius:10px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .btn{margin-top:14px;padding:10px 14px;border-radius:10px;border:0;background:#0ea5e9;color:#fff;font-weight:700;cursor:pointer}
    .msg{margin:12px 0;padding:10px;border-radius:10px}
    .ok{background:#ecfdf5;border:1px solid #10b981}
    .err{background:#fef2f2;border:1px solid #ef4444}
    code{background:#f4f4f4;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <h1>FLUS - Instalación</h1>

  <?php if ($msg): ?>
    <div class="msg ok"><?=h($msg)?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="msg err"><?=h($err)?></div>
  <?php endif; ?>

  <div class="card">
    <p>Este asistente crea <code>src/config.php</code> y prueba conexión a la base de datos.</p>

    <?php if (!is_file($cfgFile)): ?>
      <form method="post" autocomplete="off">
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
        <p>Si esta instalación es nueva, podés crear la estructura ejecutando las migraciones.</p>

        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="create_schema">

          <h3 style="margin:10px 0 0 0">Usuario administrador</h3>

          <label>Nombre</label>
          <input name="admin_nombre" value="Administrador">

          <label>Email</label>
          <input name="admin_email" value="admin@local">

          <div class="row">
            <div>
              <label>Usuario</label>
              <input name="admin_user" value="admin">
            </div>
            <div>
              <label>Contraseña</label>
              <input name="admin_pass" type="password" placeholder="mínimo 6 caracteres">
            </div>
          </div>

          <button class="btn" type="submit">Crear estructura</button>
        </form>

        <p style="margin-top:10px;color:#555">
          Alternativa (CLI): <code>php scripts/migrate.php</code>
        </p>

      <?php else: ?>
        <p>Listo. Ir a <a href="login.php">login</a>.</p>
      <?php endif; ?>

    <?php endif; ?>
  </div>

  <p style="margin-top:18px;color:#555">
    Archivo esperado: <code><?=h($cfgFile)?></code><br>
    Ejemplo: <code><?=h($example)?></code>
  </p>
</body>
</html>
