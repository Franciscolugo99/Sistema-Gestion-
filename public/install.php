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

      // Crear config.php (simple y compatible)
      $config = "<?php\n".
        "// src/config.php (generado por install.php)\n".
        "declare(strict_types=1);\n".
        "date_default_timezone_set('America/Argentina/Buenos_Aires');\n\n".
        "\$DB_HOST = " . var_export($host, true) . ";\n".
        "\$DB_PORT = " . (int)$port . ";\n".
        "\$DB_NAME = " . var_export($name, true) . ";\n".
        "\$DB_USER = " . var_export($user, true) . ";\n".
        "\$DB_PASS = " . var_export($pass, true) . ";\n\n".
        "function getPDO(): PDO {\n".
        "  global \$DB_HOST, \$DB_PORT, \$DB_NAME, \$DB_USER, \$DB_PASS;\n".
        "  \$dsn = \"mysql:host={\$DB_HOST};port={\$DB_PORT};dbname={\$DB_NAME};charset=utf8mb4\";\n".
        "  \$pdo = new PDO(\$dsn, \$DB_USER, \$DB_PASS, [\n".
        "    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n".
        "    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n".
        "  ]);\n".
        "  \$pdo->exec(\"SET time_zone = '-03:00'\");\n".
        "  return \$pdo;\n".
        "}\n";

      if (!is_dir(FLUS_ROOT . '/src')) {
        throw new RuntimeException('No existe /src');
      }

      file_put_contents($cfgFile, $config);

      // Crear carpetas básicas
      @mkdir(FLUS_ROOT . '/tmp', 0775, true);
      @mkdir(__DIR__ . '/img/productos', 0775, true);

      header('Location: login.php?installed=1');
      exit;

    } catch (Throwable $e) {
      $err = 'No se pudo conectar a la DB o escribir config.php. Detalle: ' . $e->getMessage();
    }
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
      <p>Config encontrado. Ir a <a href="login.php">login</a>.</p>
    <?php endif; ?>
  </div>

  <p style="margin-top:18px;color:#555">
    Archivo esperado: <code><?=h($cfgFile)?></code><br>
    Ejemplo: <code><?=h($example)?></code>
  </p>
</body>
</html>
