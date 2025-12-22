<?php
// public/clientes.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('ver_clientes'); // Ver listado

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php'; // h(), (posible) csrf_field/csrf_verify, etc.

$pdo = getPDO();

/* =========================================================
   CSRF (no redeclare)
   - Si ya existen en helpers.php, las usa.
   - Si no existen, define fallback sin pisar nada.
========================================================= */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
  }
}
if (!function_exists('csrf_field')) {
  function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $sess  = (string)($_SESSION['csrf_token'] ?? '');
    $token = (string)($token ?? '');
    return $token !== '' && $sess !== '' && hash_equals($sess, $token);
  }
}

/* =========================================================
   Permisos
========================================================= */
$canEditClientes = function_exists('user_has_permission') && user_has_permission('editar_clientes');

/* =========================================================
   Util URL (mantener filtros)
========================================================= */
function urlWithCli(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  return 'clientes.php' . (empty($q) ? '' : '?' . http_build_query($q));
}

/* =========================================================
   Flags / mensajes
========================================================= */
$savedFlag = (string)($_GET['saved'] ?? '');
$errores   = [];

/* =========================================================
   Opciones de condición IVA
========================================================= */
$condIvaOptions = [
  ''   => 'Sin especificar',
  'CF' => 'Consumidor final',
  'RI' => 'Responsable inscripto',
  'MT' => 'Monotributo',
  'EX' => 'Exento',
];

/* =========================================================
   ACCIÓN: activar / desactivar
========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['accion'] ?? '') === 'toggle_activo') {
  if (!$canEditClientes) {
    http_response_code(403);
    die('No tenés permisos para modificar clientes.');
  }

  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    header('Location: ' . urlWithCli(['saved' => 'csrf']));
    exit;
  }

  $id    = (int)($_POST['id'] ?? 0);
  $valor = (int)($_POST['valor'] ?? 0); // 1 activar, 0 desactivar

  if ($id > 0) {
    $st = $pdo->prepare("UPDATE clientes SET activo = :v WHERE id = :id");
    $st->execute([':v' => ($valor ? 1 : 0), ':id' => $id]);

    header('Location: ' . urlWithCli([
      'saved' => ($valor ? 'activated' : 'deactivated'),
      'page'  => $_GET['page'] ?? 1
    ]));
    exit;
  }

  header('Location: ' . urlWithCli(['saved' => 'error']));
  exit;
}

/* =========================================================
   ALTA / EDICIÓN (POST)
========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && empty($_POST['accion'])) {
  if (!$canEditClientes) {
    http_response_code(403);
    die('No tenés permisos para modificar clientes.');
  }

  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $errores[] = 'Token inválido (CSRF). Recargá la página e intentá de nuevo.';
  }

  $id        = (isset($_POST['id']) && $_POST['id'] !== '') ? (int)$_POST['id'] : null;
  $nombre    = trim((string)($_POST['nombre'] ?? ''));
  $cuit      = trim((string)($_POST['cuit'] ?? ''));
  $condIva   = trim((string)($_POST['cond_iva'] ?? ''));
  $direccion = trim((string)($_POST['direccion'] ?? ''));
  $email     = trim((string)($_POST['email'] ?? ''));
  $telefono  = trim((string)($_POST['telefono'] ?? ''));
  $activo    = isset($_POST['activo']) ? 1 : 0;

  if ($nombre === '') $errores[] = 'El nombre es obligatorio.';
  if ($cuit !== '' && strlen($cuit) > 20) $errores[] = 'El CUIT/CUIL es demasiado largo.';
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'El email no tiene un formato válido.';
  if (!array_key_exists($condIva, $condIvaOptions)) $condIva = '';

  if (empty($errores)) {
    if ($id) {
      $st = $pdo->prepare("
        UPDATE clientes SET
          nombre = ?, cuit = ?, cond_iva = ?, direccion = ?,
          email = ?, telefono = ?, activo = ?
        WHERE id = ?
      ");
      $st->execute([
        $nombre,
        $cuit !== '' ? $cuit : null,
        $condIva !== '' ? $condIva : null,
        $direccion !== '' ? $direccion : null,
        $email !== '' ? $email : null,
        $telefono !== '' ? $telefono : null,
        $activo,
        $id
      ]);

      header('Location: ' . urlWithCli(['saved' => 'updated', 'editar' => null, 'new' => null]));
      exit;
    }

    $st = $pdo->prepare("
      INSERT INTO clientes (nombre, cuit, cond_iva, direccion, email, telefono, activo)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
      $nombre,
      $cuit !== '' ? $cuit : null,
      $condIva !== '' ? $condIva : null,
      $direccion !== '' ? $direccion : null,
      $email !== '' ? $email : null,
      $telefono !== '' ? $telefono : null,
      $activo
    ]);

    header('Location: ' . urlWithCli(['saved' => 'created', 'editar' => null, 'new' => null]));
    exit;
  }
}

/* =========================================================
   Cargar cliente para edición
========================================================= */
$editCliente = null;
$editId = (int)($_GET['editar'] ?? 0);

if ($editId > 0) {
  if (!$canEditClientes) {
    http_response_code(403);
    die('No tenés permisos para editar clientes.');
  }
  $st = $pdo->prepare("SELECT * FROM clientes WHERE id = ? LIMIT 1");
  $st->execute([$editId]);
  $editCliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* =========================================================
   Filtros listado
========================================================= */
$q       = trim((string)($_GET['q'] ?? ''));
if (strlen($q) > 120) $q = substr($q, 0, 120);

$estado  = (string)($_GET['estado'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = ['1=1'];
$params = [];

if ($q !== '') {
  $where[] = '(nombre LIKE :q OR cuit LIKE :q OR email LIKE :q)';
  $params[':q'] = '%' . $q . '%';
}
if ($estado === 'activos')   $where[] = 'activo = 1';
if ($estado === 'inactivos') $where[] = 'activo = 0';

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* =========================================================
   Paginación
========================================================= */
$st = $pdo->prepare("SELECT COUNT(*) FROM clientes {$whereSql}");
$st->execute($params);
$totalRows  = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/* =========================================================
   Listado
========================================================= */
$sqlList = "
  SELECT *
  FROM clientes
  {$whereSql}
  ORDER BY nombre ASC
  LIMIT :limit OFFSET :offset
";
$st = $pdo->prepare($sqlList);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset,  PDO::PARAM_INT);
$st->execute();
$clientes = $st->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   Drawer open?
========================================================= */
$isNew = ((string)($_GET['new'] ?? '') === '1');
$drawerOpen = $canEditClientes && (
  $isNew ||
  !empty($editCliente) ||
  !empty($errores)
);

/* =========================================================
   Header
========================================================= */
$pageTitle      = 'Clientes';
$currentSection = 'clientes';
$extraCss       = ['assets/css/clientes.css']; // si querés agregar drawer css, metelo acá
$extraJs        = ['assets/js/clientes.js'];   // o agregamos uno nuevo luego

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap clientes-page">

  <div class="panel cli-panel">
    <header class="page-header">
      <div>
        <h1 class="page-title">Clientes</h1>
        <p class="page-sub">ABM de clientes para facturación y referencias en ventas.</p>
      </div>

      <?php if ($canEditClientes): ?>
        <a class="btn btn-primary" href="<?= h(urlWithCli(['new' => 1, 'editar' => null])) ?>">
          + Nuevo cliente
        </a>
      <?php else: ?>
        <span class="tag tag-muted">Solo lectura</span>
      <?php endif; ?>
    </header>
  </div>

  <div class="panel cli-list-panel">
    <h2 class="sub-title-page">Listado</h2>

    <form method="get" class="filters">
      <div class="filters-left">
        <input type="text" name="q" placeholder="Buscar por nombre, CUIT o email..." value="<?= h($q) ?>">
      </div>

      <div class="filters-right">
        <select name="estado">
          <option value="">Todos</option>
          <option value="activos"   <?= $estado === 'activos' ? 'selected' : '' ?>>Solo activos</option>
          <option value="inactivos" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Solo inactivos</option>
        </select>

        <select name="per_page">
          <?php foreach ([20, 50, 100] as $n): ?>
            <option value="<?= (int)$n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= (int)$n ?></option>
          <?php endforeach; ?>
        </select>

        <input type="hidden" name="page" value="1">
        <button class="btn btn-filter" type="submit">Aplicar</button>

        <?php if ($q !== '' || $estado !== '' || $perPage !== 50): ?>
          <a href="clientes.php" class="btn btn-secondary">Limpiar</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="table-wrapper">
      <table class="mov-table clientes-table">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>CUIT / CUIL</th>
            <th>Cond. IVA</th>
            <th>Contacto</th>
            <th>Estado</th>
            <th class="center">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$clientes): ?>
            <tr>
              <td colspan="6" class="empty-cell">No se encontraron clientes con los filtros actuales.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($clientes as $c): ?>
              <?php
                $cond = (string)($c['cond_iva'] ?? '');
                $condLabel = $condIvaOptions[$cond] ?? $cond;
              ?>
              <tr>
                <td><?= h($c['nombre'] ?? '') ?></td>
                <td><?= h($c['cuit'] ?? '') ?></td>
                <td><?= h($condLabel) ?></td>
                <td>
                  <?php if (!empty($c['email'])): ?><div><?= h($c['email']) ?></div><?php endif; ?>
                  <?php if (!empty($c['telefono'])): ?><div class="muted"><?= h($c['telefono']) ?></div><?php endif; ?>
                </td>
                <td>
                  <?php if ((int)($c['activo'] ?? 0) === 1): ?>
                    <span class="tag tag-ok">Activo</span>
                  <?php else: ?>
                    <span class="tag tag-inactivo">Inactivo</span>
                  <?php endif; ?>
                </td>
                <td class="center">
                  <?php if ($canEditClientes): ?>
                    <a class="btn-mini" href="<?= h(urlWithCli(['editar' => (int)$c['id'], 'new' => null])) ?>">Editar</a>

                    <?php if ((int)($c['activo'] ?? 0) === 1): ?>
                      <form method="post" style="display:inline" onsubmit="return confirm('¿Desactivar este cliente?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="toggle_activo">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input type="hidden" name="valor" value="0">
                        <button type="submit" class="btn-mini btn-mini-ghost">Desactivar</button>
                      </form>
                    <?php else: ?>
                      <form method="post" style="display:inline" onsubmit="return confirm('¿Activar este cliente?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="toggle_activo">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input type="hidden" name="valor" value="1">
                        <button type="submit" class="btn-mini btn-mini-ok">Activar</button>
                      </form>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pager">
        <a class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>"
           href="<?= $page <= 1 ? '#' : h(urlWithCli(['page' => $page - 1])) ?>">←</a>

        <div class="pager-mid">Página <?= (int)$page ?> / <?= (int)$totalPages ?></div>

        <a class="pager-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
           href="<?= $page >= $totalPages ? '#' : h(urlWithCli(['page' => $page + 1])) ?>">→</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($canEditClientes): ?>
  <!-- Overlay -->
  <div id="cliDrawerOverlay" class="drawer-overlay<?= $drawerOpen ? ' is-open' : '' ?>"></div>

  <!-- Drawer -->
  <aside id="cliDrawer" class="drawer<?= $drawerOpen ? ' is-open' : '' ?>" aria-label="Cliente">
    <div class="drawer-header">
      <h3 class="drawer-title"><?= !empty($editCliente) ? 'Editar cliente' : 'Nuevo cliente' ?></h3>
      <a class="drawer-close" href="<?= h(urlWithCli(['editar' => null, 'new' => null])) ?>" title="Cerrar">✕</a>
    </div>

    <div class="drawer-body">
      <form method="post" class="clientes-form">
        <?= csrf_field() ?>

        <?php if (!empty($editCliente)): ?>
          <input type="hidden" name="id" value="<?= (int)$editCliente['id'] ?>">
        <?php endif; ?>

        <div class="cli-grid">
          <div class="cli-field cli-field-wide">
            <label>Nombre / razón social</label>
            <input name="nombre" required
                   value="<?= h($editCliente['nombre'] ?? ($_POST['nombre'] ?? '')) ?>">
          </div>

          <div class="cli-field">
            <label>CUIT / CUIL</label>
            <input name="cuit" value="<?= h($editCliente['cuit'] ?? ($_POST['cuit'] ?? '')) ?>">
          </div>

          <div class="cli-field">
            <label>Condición IVA</label>
            <select name="cond_iva">
              <?php
                $condActual = (string)($editCliente['cond_iva'] ?? ($_POST['cond_iva'] ?? ''));
                foreach ($condIvaOptions as $val => $label):
              ?>
                <option value="<?= h($val) ?>" <?= ($condActual === $val) ? 'selected' : '' ?>>
                  <?= h($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="cli-field cli-field-wide">
            <label>Dirección</label>
            <input name="direccion" value="<?= h($editCliente['direccion'] ?? ($_POST['direccion'] ?? '')) ?>">
          </div>

          <div class="cli-field">
            <label>Email</label>
            <input type="email" name="email" value="<?= h($editCliente['email'] ?? ($_POST['email'] ?? '')) ?>">
          </div>

          <div class="cli-field">
            <label>Teléfono</label>
            <input name="telefono" value="<?= h($editCliente['telefono'] ?? ($_POST['telefono'] ?? '')) ?>">
          </div>

          <div class="cli-field cli-field-status">
            <label class="cli-status-label">Estado del cliente</label>
            <label class="edit-switch">
              <?php $activoForm = $editCliente['activo'] ?? ($_POST['activo'] ?? 1); ?>
              <input type="checkbox" name="activo" <?= ((int)$activoForm) ? 'checked' : '' ?>>
              <span class="edit-switch-slider"></span>
              <span class="edit-switch-text">Activo</span>
            </label>
          </div>
        </div>

        <div class="cli-actions">
          <button type="submit" class="btn btn-primary">Guardar cliente</button>
          <a class="btn btn-secondary" href="<?= h(urlWithCli(['editar' => null, 'new' => null])) ?>">Cancelar</a>
        </div>

        <?php if (!empty($errores)): ?>
          <div class="msg msg-visible msg-error" style="margin-top:12px;">
            <ul>
              <?php foreach ($errores as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </aside>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php if (!empty($savedFlag)): ?>
<?php
  // Mensaje toast sin quilombo de paréntesis
  $toastMsg = 'Listo.';
  if ($savedFlag === 'created')       $toastMsg = 'Cliente creado correctamente.';
  elseif ($savedFlag === 'updated')   $toastMsg = 'Cliente actualizado correctamente.';
  elseif ($savedFlag === 'activated') $toastMsg = 'Cliente activado.';
  elseif ($savedFlag === 'deactivated') $toastMsg = 'Cliente desactivado.';
  elseif ($savedFlag === 'csrf')      $toastMsg = 'Acción bloqueada: token inválido. Recargá e intentá de nuevo.';
?>
<script>
  if (window.showToast) {
    window.showToast(<?= json_encode($toastMsg, JSON_UNESCAPED_UNICODE) ?>);
  }
</script>
<?php endif; ?>

