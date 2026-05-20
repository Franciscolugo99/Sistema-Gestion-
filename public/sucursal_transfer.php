<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once FLUS_ROOT . '/src/sucursal_transfer_lib.php';

require_login();
require_permission('editar_productos');

$pdo = getPDO();
$info = null;
$error = null;
$importStats = null;
csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_verify($token)) {
        $error = 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'exportar_catalogo') {
            $payload = flus_sucursal_transfer_export($pdo, [
                'include_stock' => !empty($_POST['include_stock']),
                'include_inactive' => !empty($_POST['include_inactive']),
                'include_providers' => true,
                'include_reposicion' => true,
            ]);

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                throw new RuntimeException('No se pudo generar el archivo JSON.');
            }

            $filename = 'flus_catalogo_sucursal_' . date('Y-m-d_His') . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');
            echo $json;
            exit;
        }

        if ($accion === 'importar_catalogo') {
            try {
                $file = $_FILES['catalogo_file'] ?? null;
                if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Selecciona un archivo de catalogo JSON valido.');
                }

                $size = (int)($file['size'] ?? 0);
                if ($size <= 0 || $size > 10 * 1024 * 1024) {
                    throw new RuntimeException('El archivo debe pesar menos de 10 MB.');
                }

                $tmp = (string)($file['tmp_name'] ?? '');
                $raw = is_uploaded_file($tmp) ? file_get_contents($tmp) : false;
                if (!is_string($raw) || trim($raw) === '') {
                    throw new RuntimeException('No se pudo leer el archivo subido.');
                }

                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new RuntimeException('El JSON no tiene el formato esperado.');
                }

                $importStats = flus_sucursal_transfer_import($pdo, $payload, [
                    'update_existing' => !empty($_POST['update_existing']),
                    'import_stock' => !empty($_POST['import_stock']),
                    'import_inactive' => !empty($_POST['import_inactive']),
                    'import_providers' => true,
                    'import_reposicion' => true,
                ]);
                $info = 'Catalogo importado correctamente.';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$summary = flus_sucursal_transfer_export($pdo, [
    'include_stock' => false,
    'include_inactive' => false,
    'include_providers' => true,
    'include_reposicion' => true,
])['summary'] ?? [];

$pageTitle = 'Apertura de sucursal - FLUS';
$currentSection = 'sucursal_transfer';
$inlineCss = <<<CSS
.branch-transfer-page { max-width: 1180px; margin: 0 auto; }
.branch-transfer-hero { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color, #d8e2ef); }
.branch-transfer-eyebrow { margin: 0 0 .35rem; color: var(--muted, #64748b); font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.branch-transfer-title { margin: 0; font-size: clamp(1.7rem, 2.4vw, 2.45rem); line-height: 1.05; }
.branch-transfer-sub { margin: .55rem 0 0; max-width: 680px; color: var(--muted, #64748b); }
.branch-transfer-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; align-items: start; }
.branch-card { border: 1px solid var(--border-color, #d8e2ef); border-radius: 8px; background: var(--panel-bg, #fff); box-shadow: var(--shadow-sm, 0 8px 24px rgba(15,23,42,.06)); overflow: hidden; }
.branch-card__head { padding: 1rem 1.1rem; background: #eef6ff; border-bottom: 1px solid var(--border-color, #d8e2ef); }
.branch-card__head h2 { margin: 0; font-size: 1.05rem; }
.branch-card__body { padding: 1.1rem; }
.branch-option { display: flex; gap: .65rem; align-items: flex-start; margin: .85rem 0; color: var(--text, #0f172a); }
.branch-option input { margin-top: .22rem; }
.branch-option strong { display: block; }
.branch-option span { display: block; margin-top: .15rem; color: var(--muted, #64748b); font-size: .92rem; }
.branch-actions { margin-top: 1rem; display: flex; gap: .7rem; flex-wrap: wrap; }
.branch-kpis { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: .7rem; margin: 0 0 1rem; }
.branch-kpi { padding: .8rem; border: 1px solid var(--border-color, #d8e2ef); border-radius: 8px; background: #f8fbff; }
.branch-kpi strong { display: block; font-size: 1.35rem; }
.branch-kpi span { display: block; color: var(--muted, #64748b); font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; }
.branch-result { margin-top: 1rem; border: 1px solid #b7ebc6; background: #f0fdf4; border-radius: 8px; padding: .9rem; }
.branch-result ul { margin: .5rem 0 0; padding-left: 1rem; }
.branch-note { margin-top: 1rem; padding: .85rem; border-radius: 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #7c2d12; }
@media (max-width: 860px) { .branch-transfer-hero, .branch-transfer-grid { display: block; } .branch-card { margin-bottom: 1rem; } .branch-kpis { grid-template-columns: 1fr; } }
CSS;

require __DIR__ . '/partials/header.php';
?>

<main class="branch-transfer-page">
    <section class="branch-transfer-hero">
        <div>
            <p class="branch-transfer-eyebrow">Catalogo portable</p>
            <h1 class="branch-transfer-title">Apertura de sucursal</h1>
            <p class="branch-transfer-sub">Exporta productos, proveedores y reglas de reposicion para iniciar otra sucursal sin cargar todo de nuevo. No mueve ventas, cajas, facturas, cuenta corriente ni historial fiscal.</p>
        </div>
    </section>

    <?php if ($info): ?>
        <div class="alert alert-success"><?= h($info) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="branch-kpis">
        <div class="branch-kpi"><strong><?= (int)($summary['productos'] ?? 0) ?></strong><span>Productos activos</span></div>
        <div class="branch-kpi"><strong><?= (int)($summary['proveedores'] ?? 0) ?></strong><span>Proveedores activos</span></div>
        <div class="branch-kpi"><strong><?= (int)($summary['reposicion'] ?? 0) ?></strong><span>Reglas reposicion</span></div>
    </div>

    <div class="branch-transfer-grid">
        <section class="branch-card">
            <div class="branch-card__head">
                <h2>Exportar desde esta sucursal</h2>
            </div>
            <div class="branch-card__body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="exportar_catalogo">

                    <label class="branch-option">
                        <input type="checkbox" name="include_stock" value="1">
                        <span><strong>Incluir stock actual</strong><span>Usalo cuando la nueva sucursal arranca con el mismo conteo inicial. Si no, exporta stock en cero.</span></span>
                    </label>

                    <label class="branch-option">
                        <input type="checkbox" name="include_inactive" value="1">
                        <span><strong>Incluir productos y proveedores inactivos</strong><span>Conviene dejarlo apagado para abrir una sucursal limpia.</span></span>
                    </label>

                    <div class="branch-note">El archivo JSON incluye catalogo, proveedores y configuracion de reposicion. No copia imagenes de productos.</div>

                    <div class="branch-actions">
                        <button type="submit" class="btn btn-primary">Descargar catalogo</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="branch-card">
            <div class="branch-card__head">
                <h2>Importar en la nueva sucursal</h2>
            </div>
            <div class="branch-card__body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="importar_catalogo">

                    <div class="form-group">
                        <label for="catalogo_file">Archivo de catalogo FLUS</label>
                        <input id="catalogo_file" type="file" name="catalogo_file" accept="application/json,.json" required>
                    </div>

                    <label class="branch-option">
                        <input type="checkbox" name="update_existing" value="1" checked>
                        <span><strong>Actualizar productos existentes</strong><span>Si el codigo ya existe, actualiza precio, costo, categoria, proveedor y datos comerciales.</span></span>
                    </label>

                    <label class="branch-option">
                        <input type="checkbox" name="import_stock" value="1">
                        <span><strong>Importar stock del archivo</strong><span>Dejalo apagado si cada sucursal va a cargar su stock inicial propio.</span></span>
                    </label>

                    <label class="branch-option">
                        <input type="checkbox" name="import_inactive" value="1">
                        <span><strong>Importar inactivos si el archivo los trae</strong><span>Solo necesario si queres conservar productos discontinuados.</span></span>
                    </label>

                    <div class="branch-actions">
                        <button type="submit" class="btn btn-primary">Importar catalogo</button>
                    </div>
                </form>

                <?php if (is_array($importStats)): ?>
                    <div class="branch-result">
                        <strong>Resultado de importacion</strong>
                        <ul>
                            <li>Productos creados: <?= (int)$importStats['productos_creados'] ?></li>
                            <li>Productos actualizados: <?= (int)$importStats['productos_actualizados'] ?></li>
                            <li>Productos omitidos: <?= (int)$importStats['productos_omitidos'] ?></li>
                            <li>Proveedores creados: <?= (int)$importStats['proveedores_creados'] ?></li>
                            <li>Proveedores actualizados: <?= (int)$importStats['proveedores_actualizados'] ?></li>
                            <li>Reposicion actualizada: <?= (int)$importStats['reposicion_actualizada'] ?></li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
