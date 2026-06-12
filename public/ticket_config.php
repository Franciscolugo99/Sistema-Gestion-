<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/csrf.php';
require_once FLUS_ROOT . '/src/ticket_config_lib.php';
require_once FLUS_ROOT . '/src/upload_helpers.php';

require_login();
require_permission('administrar_config');

$pdo = getPDO();
$csrfToken = csrf_token();
$message = '';
$error = '';

function ticketcfg_stage_logo(array $file): ?array
{
    return flus_upload_stage_image(
        $file,
        __DIR__ . '/uploads/logos',
        'logo',
        2 * 1024 * 1024,
        ['png', 'jpg', 'webp', 'gif'],
        ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
        'el logo'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'La sesion vencio. Recarga la pagina e intenta nuevamente.';
    } else {
        $paper = flus_ticket_normalize_paper((string)($_POST['ticket_paper'] ?? '80'));
        $mode = flus_ticket_normalize_mode((string)($_POST['ticket_mode'] ?? 'autoprint'));
        $footer = trim((string)($_POST['ticket_footer'] ?? ''));
        $showLogo = isset($_POST['ticket_show_logo']);
        $showRegister = isset($_POST['ticket_show_register']);
        $showCashier = isset($_POST['ticket_show_cashier']);
        $removeLogo = isset($_POST['remove_logo']);
        $oldLogoUrl = trim((string)config_get(
            $pdo,
            'ticket_logo_url',
            config_get($pdo, 'business_logo_url', '')
        ));
        $logoUrl = $oldLogoUrl;
        $logoUpload = null;

        if (mb_strlen($footer, 'UTF-8') > 180) {
            $error = 'El texto final no puede superar los 180 caracteres.';
        } else {
            try {
                $logoUpload = ticketcfg_stage_logo($_FILES['logo_file'] ?? []);
                if ($removeLogo) {
                    $logoUrl = '';
                    $showLogo = false;
                } elseif (is_array($logoUpload)) {
                    flus_upload_promote($logoUpload);
                    $logoUrl = 'uploads/logos/' . (string)$logoUpload['filename'];
                }

                $pdo->beginTransaction();
                $saved = [
                    config_set($pdo, 'print_ticket_paper', $paper),
                    config_set($pdo, 'print_ticket_mode', $mode),
                    config_set($pdo, 'ticket_footer', $footer),
                    config_set($pdo, 'ticket_logo_url', $logoUrl),
                    config_set($pdo, 'ticket_show_logo', $showLogo && $logoUrl !== '' ? '1' : '0'),
                    config_set($pdo, 'ticket_show_register', $showRegister ? '1' : '0'),
                    config_set($pdo, 'ticket_show_cashier', $showCashier ? '1' : '0'),
                ];
                if (in_array(false, $saved, true)) {
                    throw new RuntimeException('No se pudo guardar la configuracion.');
                }
                $pdo->commit();

                if ($oldLogoUrl !== '' && $oldLogoUrl !== $logoUrl) {
                    flus_upload_delete_file_if_exists(flus_ticket_logo_local_path($oldLogoUrl));
                }
                $message = 'Configuracion de tickets guardada.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flus_upload_cleanup($logoUpload);
                $error = 'No se pudo guardar el logo: ' . $e->getMessage();
            }
        }
    }
}

$config = flus_ticket_global_config($pdo);
$latestSaleId = 0;
try {
    $latestSaleId = (int)$pdo->query('SELECT id FROM ventas ORDER BY id DESC LIMIT 1')->fetchColumn();
} catch (Throwable $e) {
    $latestSaleId = 0;
}

$pageTitle = 'Tickets e impresion';
$currentSection = 'configuracion';
$bodyClass = trim(($bodyClass ?? '') . ' ticket-config-page');
$extraCss = array_merge($extraCss ?? [], [
    'assets/css/ticket_config.css?v=' . filemtime(__DIR__ . '/assets/css/ticket_config.css'),
]);
$extraJs = array_merge($extraJs ?? [], [
    'assets/js/ticket_config.js?v=' . filemtime(__DIR__ . '/assets/js/ticket_config.js'),
]);

require __DIR__ . '/partials/header.php';
?>

<div class="ticket-config-shell">
  <header class="ticket-config-header">
    <div>
      <span class="module-eyebrow">Comprobantes de venta</span>
      <h1 class="page-title">Tickets e impresion</h1>
      <p class="page-sub">Un unico perfil para Caja, Ventas y reimpresiones. Las excepciones por caja se administran desde Terminales.</p>
    </div>
    <a class="btn btn-secondary" href="terminales.php">Excepciones por terminal</a>
  </header>

  <?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= h($message) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="ticket-config-layout">
    <form method="post" enctype="multipart/form-data" class="ticket-config-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

      <section class="ticket-config-section">
        <div class="ticket-config-section__head">
          <div>
            <span class="ticket-config-step">1</span>
            <h2>Perfil global</h2>
          </div>
          <p>Se aplica cuando una terminal no tiene una excepcion propia.</p>
        </div>

        <div class="ticket-config-fields">
          <label class="ticket-config-field">
            <span>Ancho de papel</span>
            <select id="ticketPaper" name="ticket_paper">
              <option value="58" <?= $config['paper'] === '58' ? 'selected' : '' ?>>58 mm, impresora termica compacta</option>
              <option value="80" <?= $config['paper'] === '80' ? 'selected' : '' ?>>80 mm, impresora termica estandar</option>
            </select>
            <small>La Gadnic IT1050 usa 58 mm con un area util aproximada de 46 a 48 mm.</small>
          </label>

          <label class="ticket-config-field">
            <span>Despues de cobrar</span>
            <select name="ticket_mode">
              <option value="autoprint" <?= $config['mode'] === 'autoprint' ? 'selected' : '' ?>>Abrir dialogo de impresion</option>
              <option value="preview" <?= $config['mode'] === 'preview' ? 'selected' : '' ?>>Mostrar vista previa en FLUS</option>
              <option value="none" <?= $config['mode'] === 'none' ? 'selected' : '' ?>>No abrir el ticket</option>
            </select>
            <small>La reimpresion manual siempre queda disponible desde Caja y Ventas.</small>
          </label>

          <label class="ticket-config-field ticket-config-field--wide">
            <span>Texto final</span>
            <input type="text" name="ticket_footer" maxlength="180" value="<?= h($config['footer']) ?>" placeholder="Gracias por su compra">
          </label>

          <div class="ticket-config-field ticket-config-field--wide">
            <span>Datos operativos</span>
            <div class="ticket-config-options">
              <label class="ticket-config-check">
                <input type="checkbox" name="ticket_show_register" value="1" <?= $config['show_register'] ? 'checked' : '' ?>>
                <span>
                  Mostrar caja
                  <small>Imprime el nombre de la terminal, por ejemplo Caja 1.</small>
                </span>
              </label>
              <label class="ticket-config-check">
                <input type="checkbox" name="ticket_show_cashier" value="1" <?= $config['show_cashier'] ? 'checked' : '' ?>>
                <span>
                  Mostrar cajero
                  <small>Imprime el usuario que registro la venta.</small>
                </span>
              </label>
            </div>
          </div>

          <div class="ticket-config-field ticket-config-field--wide">
            <span>Logo del comercio</span>
            <div class="ticket-config-logo">
              <div class="ticket-config-logo__preview">
                <?php if ($config['logo_url'] !== ''): ?>
                  <img src="<?= h(flus_ticket_logo_src($config['logo_url'])) ?>" alt="Logo actual del comercio">
                <?php else: ?>
                  <span>Sin logo</span>
                <?php endif; ?>
              </div>
              <div class="ticket-config-logo__controls">
                <input
                  type="file"
                  name="logo_file"
                  accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif"
                >
                <small>Para impresoras termicas funciona mejor un logo negro, simple y con fondo blanco o transparente. Maximo 2 MB.</small>
                <label class="ticket-config-check">
                  <input type="checkbox" name="ticket_show_logo" value="1" <?= $config['show_logo'] ? 'checked' : '' ?>>
                  <span>Mostrar el logo en tickets impresos y compartidos</span>
                </label>
                <?php if ($config['logo_url'] !== ''): ?>
                  <label class="ticket-config-check ticket-config-check--danger">
                    <input type="checkbox" name="remove_logo" value="1">
                    <span>Quitar el logo actual del comercio</span>
                  </label>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="ticket-config-actions">
          <button class="btn btn-primary" type="submit">Guardar configuracion</button>
          <a class="btn btn-secondary" href="configuracion.php">Datos del comercio</a>
        </div>
      </section>
    </form>

    <section class="ticket-config-preview" data-sale-id="<?= $latestSaleId ?>">
      <div class="ticket-config-section__head">
        <div>
          <span class="ticket-config-step">2</span>
          <h2>Prueba de impresion</h2>
        </div>
        <p><?= $latestSaleId > 0 ? 'Usa la ultima venta registrada, sin modificarla.' : 'Todavia no hay ventas para usar como ejemplo.' ?></p>
      </div>

      <?php if ($latestSaleId > 0): ?>
        <div class="ticket-config-preview__toolbar">
          <strong>Venta #<?= $latestSaleId ?></strong>
          <div>
            <a id="ticketPreviewOpen" class="btn btn-secondary btn-sm" href="ticket.php?id=<?= $latestSaleId ?>&paper=<?= h($config['paper']) ?>" target="_blank" rel="noopener">Abrir aparte</a>
            <button id="ticketPreviewPrint" class="btn btn-primary btn-sm" type="button">Imprimir prueba</button>
          </div>
        </div>
        <div class="ticket-config-frame">
          <iframe
            id="ticketPreviewFrame"
            title="Vista previa del ticket"
            src="ticket.php?id=<?= $latestSaleId ?>&paper=<?= h($config['paper']) ?>"
          ></iframe>
        </div>
        <p class="ticket-config-driver-note">
          Para 58 mm: papel 58(48) x 210 mm, margenes Ninguno, escala 100 y encabezados desactivados.
        </p>
      <?php else: ?>
        <div class="ticket-config-empty">
          <strong>No hay un ticket para previsualizar.</strong>
          <span>Registra una venta y vuelve a esta pantalla.</span>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
