<?php
// public/inventario_fisico.php - Inventario Físico FLUS
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

if (!user_has_permission('editar_stock')) {
    http_response_code(403);
    echo 'No tenés permisos para acceder a esta sección.';
    exit;
}

require_once __DIR__ . '/../src/inventario_fisico.php';

// Asegurar tablas existen
inventario_ensure_tables();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Inventario Físico - FLUS';
$currentSection = 'inventario_fisico';

// CSS/JS del módulo (separados, compatibles con modo oscuro)
$extraCss = ['assets/css/inventario_fisico.css'];
$extraJs  = ['assets/js/inventario_fisico.js'];

$info = null;
$error = null;

// Vista actual
$vista = (string)($_GET['v'] ?? 'sesiones'); // sesiones | conteo | resumen
$sessionId = (int)($_GET['sid'] ?? 0);

// Cargar sesión actual si está seleccionada
$currentSession = null;
if ($sessionId > 0) {
    $currentSession = inventario_session_get($sessionId);
    if (!$currentSession) {
        $sessionId = 0;
        $vista = 'sesiones';
    }
}

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Token CSRF inválido.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'crear_sesion') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $descripcion = trim((string)($_POST['descripcion'] ?? ''));

            if ($nombre === '') {
                $error = 'El nombre de la sesión es requerido.';
            } else {
                $newId = inventario_session_create($nombre, $descripcion, (int)($_SESSION['user_id'] ?? 0));
                if ($newId) {
                    $info = 'Sesión de inventario creada.';
                    $sessionId = $newId;
                    $currentSession = inventario_session_get($newId);
                    $vista = 'conteo';
                } else {
                    $error = 'Error al crear la sesión.';
                }
            }

        } elseif ($accion === 'registrar_conteo' && $sessionId > 0) {
            $productoId = (int)($_POST['producto_id'] ?? 0);
            $cantidad   = (float)($_POST['cantidad'] ?? 0);
            $ubicacion  = trim((string)($_POST['ubicacion'] ?? ''));
            $notas      = trim((string)($_POST['notas'] ?? ''));

            if ($productoId <= 0) {
                $error = 'Producto inválido.';
            } elseif ($cantidad < 0) {
                $error = 'La cantidad no puede ser negativa.';
            } else {
                $conteoId = inventario_registrar_conteo(
                    $sessionId,
                    $productoId,
                    $cantidad,
                    $ubicacion !== '' ? $ubicacion : null,
                    $notas !== '' ? $notas : null,
                    (int)($_SESSION['user_id'] ?? 0)
                );

                if ($conteoId) {
                    $info = 'Conteo registrado.';
                    $currentSession = inventario_session_get($sessionId);
                } else {
                    $error = 'Error al registrar conteo.';
                }
            }

        } elseif ($accion === 'cerrar_sesion' && $sessionId > 0) {
            $motivo = trim((string)($_POST['motivo'] ?? 'Cerrado manualmente'));
            if (inventario_session_close($sessionId, $motivo, (int)($_SESSION['user_id'] ?? 0))) {
                $info = 'Sesión cerrada.';
                $currentSession = inventario_session_get($sessionId);
                // después de cerrar, es común ir directo al resumen
                $vista = 'resumen';
            } else {
                $error = 'Error al cerrar sesión.';
            }

        } elseif ($accion === 'aplicar_ajustes' && $sessionId > 0) {
            $errMsg = null;
            $result = inventario_aplicar_ajustes($sessionId, (int)($_SESSION['user_id'] ?? 0), $errMsg);
            if ($result) {
                $info = "Ajustes aplicados: {$result['ajustes_realizados']} productos ajustados.";
                $currentSession = inventario_session_get($sessionId);
                $vista = 'resumen';
            } else {
                $error = 'Error al aplicar ajustes: ' . ($errMsg ?: 'Error desconocido');
            }
        }
    }
}

// Listar sesiones
$sesiones = inventario_session_list();
$sesionesAbiertas = array_values(array_filter($sesiones, static fn($s) => ($s['estado'] ?? '') === 'ABIERTA'));

// Si estamos en conteo/resumen, cargar conteos y resumen
$conteos = [];
$resumen = null;
if ($sessionId > 0) {
    $conteos = inventario_get_conteos($sessionId);
    $resumen = inventario_get_resumen_diferencias($sessionId);
}

require __DIR__ . '/partials/header.php';
?>

<div class="panel invf">
    <div class="panel-head">
        <div>
            <h1>Inventario Físico</h1>
            <p class="panel-subtitle">Conteo y ajuste de stock real vs sistema</p>
        </div>
        <?php if ($vista === 'sesiones'): ?>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('modalNuevaSesion').showModal()">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Nueva Sesión
        </button>
        <?php endif; ?>
    </div>

    <?php if ($info): ?>
        <div class="alert alert-ok">
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <span><?= h($info) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-err">
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span><?= h($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="inv-tabs">
        <a href="?v=sesiones" class="inv-tab <?= $vista === 'sesiones' ? 'active' : '' ?>">
            Sesiones
            <?php if (count($sesionesAbiertas) > 0): ?>
                <span class="badge"><?= count($sesionesAbiertas) ?> abierta<?= count($sesionesAbiertas) > 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </a>
        <?php if ($currentSession): ?>
        <a href="?v=conteo&sid=<?= (int)$sessionId ?>" class="inv-tab <?= $vista === 'conteo' ? 'active' : '' ?>">
            Conteo
            <span class="badge"><?= is_array($conteos) ? count($conteos) : 0 ?></span>
        </a>
        <a href="?v=resumen&sid=<?= (int)$sessionId ?>" class="inv-tab <?= $vista === 'resumen' ? 'active' : '' ?>">
            Resumen
        </a>
        <?php endif; ?>
    </div>

    <?php if ($vista === 'sesiones'): ?>
    <!-- Lista de sesiones -->
    <?php if (empty($sesiones)): ?>
        <div class="inv-empty inv-empty--lg">
            <svg class="icon inv-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
            </svg>
            <p class="inv-empty-text">No hay sesiones de inventario</p>
            <p class="inv-empty-subtext">Creá una nueva sesión para empezar a contar</p>
        </div>
    <?php else: ?>
        <?php foreach ($sesiones as $sesion): ?>
        <?php
            $sid = (int)($sesion['id'] ?? 0);
            $prodContados = (int)($sesion['productos_contados'] ?? ($sesion['total_conteos'] ?? 0));
        ?>
        <a href="?v=conteo&sid=<?= $sid ?>" class="session-card session-card-link <?= $sid === $sessionId ? 'active' : '' ?>">
            <div class="session-header">
                <div>
                    <div class="session-name"><?= h((string)($sesion['nombre'] ?? '')) ?></div>
                    <div class="session-meta">
                        Creada <?= h(date('d/m/Y H:i', strtotime((string)($sesion['created_at'] ?? 'now')))) ?>
                        <?php if (!empty($sesion['descripcion'])): ?>
                            • <?= h((string)$sesion['descripcion']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="session-status <?= strtolower((string)($sesion['estado'] ?? '')) ?>"><?= h((string)($sesion['estado'] ?? '')) ?></span>
            </div>
            <div class="session-stats">
                <div class="session-stat">
                    <div class="session-stat-value"><?= $prodContados ?></div>
                    <div class="session-stat-label">Productos contados</div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php elseif ($vista === 'conteo' && $currentSession): ?>
    <!-- Información de sesión actual -->
    <div class="session-card active session-card-static">
        <div class="session-header">
            <div>
                <div class="session-name"><?= h((string)($currentSession['nombre'] ?? '')) ?></div>
                <div class="session-meta">
                    <?php if (!empty($currentSession['descripcion'])): ?>
                        <?= h((string)$currentSession['descripcion']) ?> •
                    <?php endif; ?>
                    Creada <?= h(date('d/m/Y H:i', strtotime((string)($currentSession['created_at'] ?? 'now')))) ?>
                </div>
            </div>
            <span class="session-status <?= strtolower((string)($currentSession['estado'] ?? '')) ?>"><?= h((string)($currentSession['estado'] ?? '')) ?></span>
        </div>

        <div class="session-actions">
            <a class="btn btn-ghost btn-sm" href="?v=resumen&sid=<?= (int)$sessionId ?>">Ver resumen</a>
            <?php if (($currentSession['estado'] ?? '') === 'ABIERTA'): ?>
            <form method="post" class="inv-inline-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="cerrar_sesion">
                <input type="hidden" name="motivo" value="Cerrado para aplicar ajustes">
                <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('¿Cerrar la sesión? Luego podrás aplicar ajustes desde Resumen.');">
                    Cerrar sesión
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (($currentSession['estado'] ?? '') === 'ABIERTA'): ?>
    <!-- Formulario de conteo -->
    <div class="conteo-form">
        <h3>Registrar Conteo</h3>

        <div class="search-producto">
            <input type="text" id="buscarProducto" class="form-control" placeholder="Buscá por código o nombre del producto..." autocomplete="off">
            <div class="search-results" id="searchResults" aria-live="polite"></div>
        </div>

        <form method="post" id="formConteo" class="is-hidden">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="registrar_conteo">
            <input type="hidden" name="producto_id" id="productoId">

            <div class="selected-producto" id="productoSeleccionado">
                <div class="info">
                    <div class="producto-codigo" id="selCodigo"></div>
                    <div class="producto-nombre" id="selNombre"></div>
                </div>
                <div class="stock-sistema">
                    <div class="stock-value" id="selStock"></div>
                    <div class="stock-label">Stock Sistema</div>
                </div>
            </div>

            <div class="conteo-inputs">
                <div class="form-group">
                    <label>Cantidad Contada *</label>
                    <input type="number" name="cantidad" id="cantidadContada" min="0" step="0.01" required class="form-control">
                </div>
                <div class="form-group">
                    <label>Ubicación</label>
                    <input type="text" name="ubicacion" placeholder="Ej: Estante A3" class="form-control">
                </div>
                <div class="form-group">
                    <label>Notas</label>
                    <input type="text" name="notas" placeholder="Observaciones..." class="form-control">
                </div>
            </div>

            <div class="inv-actions-row">
                <button type="submit" class="btn btn-primary">Registrar Conteo</button>
                <button type="button" class="btn btn-ghost" onclick="window.cancelarSeleccion && window.cancelarSeleccion()">Cancelar</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Lista de conteos -->
    <?php if (!empty($conteos)): ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="t-right">Stock Sistema</th>
                    <th class="t-right">Contado</th>
                    <th class="t-right">Diferencia</th>
                    <th>Ubicación</th>
                    <th>Notas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conteos as $conteo): ?>
                <tr>
                    <td>
                        <div class="producto-codigo"><?= h((string)($conteo['codigo'] ?? $conteo['producto_id'] ?? '')) ?></div>
                        <div class="producto-nombre"><?= h((string)($conteo['nombre'] ?? '')) ?></div>
                    </td>
                    <td class="t-right"><?= number_format((float)($conteo['cantidad_sistema'] ?? 0), 2) ?></td>
                    <td class="t-right"><?= number_format((float)($conteo['cantidad_contada'] ?? 0), 2) ?></td>
                    <td class="t-right">
                        <?php
                        $dif = (float)($conteo['diferencia'] ?? 0);
                        $clase = $dif < 0 ? 'faltante' : ($dif > 0 ? 'sobrante' : 'ok');
                        ?>
                        <span class="diferencia-badge <?= $clase ?>">
                            <?= $dif > 0 ? '+' : '' ?><?= number_format($dif, 2) ?>
                        </span>
                    </td>
                    <td><?= h((string)($conteo['ubicacion'] ?? '-')) ?></td>
                    <td><?= h((string)($conteo['notas'] ?? '-')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="inv-empty inv-empty--md">
        <p class="inv-empty-text">No hay conteos registrados en esta sesión</p>
    </div>
    <?php endif; ?>

    <?php elseif ($vista === 'resumen' && $currentSession): ?>
    <!-- Resumen de diferencias -->
    <div class="resumen-grid">
        <div class="resumen-card">
            <div class="resumen-value"><?= (int)($resumen['productos_contados'] ?? 0) ?></div>
            <div class="resumen-label">Productos Contados</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-value"><?= (int)($resumen['productos_con_diferencia'] ?? 0) ?></div>
            <div class="resumen-label">Con Diferencia</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-value negative"><?= (int)($resumen['productos_faltantes'] ?? 0) ?></div>
            <div class="resumen-label">Faltantes</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-value positive"><?= (int)($resumen['productos_sobrantes'] ?? 0) ?></div>
            <div class="resumen-label">Sobrantes</div>
        </div>
        <div class="resumen-card">
            <?php $vd = (float)($resumen['valor_diferencia'] ?? 0); ?>
            <div class="resumen-value <?= $vd < 0 ? 'negative' : 'positive' ?>">
                $<?= number_format(abs($vd), 2) ?>
            </div>
            <div class="resumen-label">Valor Diferencia</div>
        </div>
    </div>

    <!-- Acciones de sesión -->
    <?php if (($currentSession['estado'] ?? '') === 'ABIERTA'): ?>
        <div class="inv-actions-row" style="margin-bottom: 1rem;">
            <a class="btn btn-ghost" href="?v=conteo&sid=<?= (int)$sessionId ?>">Volver al conteo</a>
            <form method="post" class="inv-inline-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="cerrar_sesion">
                <input type="hidden" name="motivo" value="Cerrado para aplicar ajustes">
                <button type="submit" class="btn btn-warning" onclick="return confirm('¿Cerrar la sesión? Esto bloquea nuevos conteos.');">
                    Cerrar sesión
                </button>
            </form>
        </div>
        <div class="inv-hint">
            Para poder <strong>aplicar ajustes</strong>, primero tenés que <strong>cerrar la sesión</strong>.
        </div>
    <?php elseif (($currentSession['estado'] ?? '') === 'CERRADA'): ?>
        <div class="inv-actions-row" style="margin-bottom: 1.25rem;">
            <a class="btn btn-ghost" href="?v=conteo&sid=<?= (int)$sessionId ?>">Volver al conteo</a>
            <?php if ((int)($resumen['productos_con_diferencia'] ?? 0) > 0): ?>
            <form method="post" class="inv-inline-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="aplicar_ajustes">
                <button type="submit" class="btn btn-primary" onclick="return confirm('¿Aplicar ajustes de stock? Esta acción NO se puede deshacer.');">
                    Aplicar ajustes (<?= (int)$resumen['productos_con_diferencia'] ?> productos)
                </button>
            </form>
            <?php else: ?>
            <span class="inv-hint">Sesión cerrada. No hay diferencias para aplicar.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Tabla de diferencias -->
    <?php
    $conteosConDif = inventario_get_conteos($sessionId, true);
    if (!empty($conteosConDif)):
    ?>
    <h3 class="inv-subtitle">Productos con Diferencia</h3>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="t-right">Stock Sistema</th>
                    <th class="t-right">Contado</th>
                    <th class="t-right">Diferencia</th>
                    <th class="t-right">Valor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conteosConDif as $conteo): ?>
                <tr>
                    <td>
                        <div class="producto-codigo"><?= h((string)($conteo['codigo'] ?? $conteo['producto_id'] ?? '')) ?></div>
                        <div class="producto-nombre"><?= h((string)($conteo['nombre'] ?? '')) ?></div>
                    </td>
                    <td class="t-right"><?= number_format((float)($conteo['cantidad_sistema'] ?? 0), 2) ?></td>
                    <td class="t-right"><?= number_format((float)($conteo['cantidad_contada'] ?? 0), 2) ?></td>
                    <td class="t-right">
                        <?php
                        $dif = (float)($conteo['diferencia'] ?? 0);
                        $clase = $dif < 0 ? 'faltante' : 'sobrante';
                        ?>
                        <span class="diferencia-badge <?= $clase ?>">
                            <?= $dif > 0 ? '+' : '' ?><?= number_format($dif, 2) ?>
                        </span>
                    </td>
                    <td class="t-right">
                        <?php
                        $costo = (float)($conteo['costo'] ?? 0);
                        $valorDif = $dif * $costo;
                        ?>
                        <span class="<?= $valorDif < 0 ? 'negative' : 'positive' ?>">
                            $<?= number_format(abs($valorDif), 2) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- Modal Nueva Sesión -->
<dialog id="modalNuevaSesion" class="inv-modal">
    <form method="post" class="inv-modal-body">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="crear_sesion">

        <h3 class="inv-modal-title">Nueva Sesión de Inventario</h3>

        <div class="form-group">
            <label>Nombre *</label>
            <input type="text" name="nombre" required class="form-control" placeholder="Ej: Inventario Enero 2026">
        </div>

        <div class="form-group">
            <label>Descripción</label>
            <textarea name="descripcion" class="form-control" rows="2" placeholder="Descripción opcional..."></textarea>
        </div>

        <div class="inv-actions-row inv-modal-actions">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalNuevaSesion').close()">Cancelar</button>
            <button type="submit" class="btn btn-primary">Crear Sesión</button>
        </div>
    </form>
</dialog>

<?php require __DIR__ . '/partials/footer.php'; ?>
