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
$extraCss = [];
$extraJs = [];

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
            
            if (!$nombre) {
                $error = 'El nombre de la sesión es requerido.';
            } else {
                $newId = inventario_session_create($nombre, $descripcion, $_SESSION['user_id']);
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
            $cantidad = (float)($_POST['cantidad'] ?? 0);
            $ubicacion = trim((string)($_POST['ubicacion'] ?? ''));
            $notas = trim((string)($_POST['notas'] ?? ''));
            
            if ($productoId <= 0) {
                $error = 'Producto inválido.';
            } elseif ($cantidad < 0) {
                $error = 'La cantidad no puede ser negativa.';
            } else {
                $conteoId = inventario_registrar_conteo($sessionId, $productoId, $cantidad, $ubicacion, $notas, $_SESSION['user_id']);
                if ($conteoId) {
                    $info = 'Conteo registrado.';
                    $currentSession = inventario_session_get($sessionId);
                } else {
                    $error = 'Error al registrar conteo.';
                }
            }
        } elseif ($accion === 'cerrar_sesion' && $sessionId > 0) {
            $motivo = trim((string)($_POST['motivo'] ?? 'Cerrado manualmente'));
            if (inventario_session_close($sessionId, $motivo)) {
                $info = 'Sesión cerrada.';
                $currentSession = inventario_session_get($sessionId);
            } else {
                $error = 'Error al cerrar sesión.';
            }
        } elseif ($accion === 'aplicar_ajustes' && $sessionId > 0) {
            $errMsg = null;
            $result = inventario_aplicar_ajustes($sessionId, $_SESSION['user_id'], $errMsg);
            if ($result) {
                $info = "Ajustes aplicados: {$result['ajustes_realizados']} productos ajustados.";
                $currentSession = inventario_session_get($sessionId);
            } else {
                $error = 'Error al aplicar ajustes: ' . ($errMsg ?: 'Error desconocido');
            }
        }
    }
}

// Listar sesiones
$sesiones = inventario_session_list();
$sesionesAbiertas = array_filter($sesiones, fn($s) => $s['estado'] === 'ABIERTA');

// Si estamos en conteo, cargar los conteos
$conteos = [];
$resumen = null;
if ($sessionId > 0) {
    $conteos = inventario_get_conteos($sessionId);
    $resumen = inventario_get_resumen_diferencias($sessionId);
}

require __DIR__ . '/partials/header.php';
?>

<style>
.inv-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--border-color, #e5e7eb);
    padding-bottom: 0;
}
.inv-tab {
    padding: 0.75rem 1.25rem;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.2s;
}
.inv-tab:hover {
    color: var(--primary);
}
.inv-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}
.inv-tab .badge {
    margin-left: 0.5rem;
    padding: 0.125rem 0.5rem;
    background: var(--bg-light);
    border-radius: 9999px;
    font-size: 0.75rem;
}

.session-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    cursor: pointer;
    transition: all 0.2s;
}
.session-card:hover {
    border-color: var(--primary);
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.session-card.active {
    border-color: var(--primary);
    background: var(--primary-light, #eff6ff);
}
.session-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}
.session-name {
    font-weight: 600;
    font-size: 1rem;
}
.session-status {
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.session-status.abierta { background: #d1fae5; color: #065f46; }
.session-status.cerrada { background: #fef3c7; color: #92400e; }
.session-status.aplicada { background: #dbeafe; color: #1e40af; }
.session-meta {
    font-size: 0.8125rem;
    color: var(--text-muted);
}
.session-stats {
    display: flex;
    gap: 1.5rem;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border-light);
}
.session-stat {
    text-align: center;
}
.session-stat-value {
    font-size: 1.25rem;
    font-weight: 600;
}
.session-stat-label {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.conteo-form {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.conteo-form h3 {
    margin: 0 0 1rem 0;
    font-size: 1rem;
}
.search-producto {
    position: relative;
}
.search-producto input {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 1rem;
}
.search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 100;
    display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.search-results.show { display: block; }
.search-result-item {
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid var(--border-light);
}
.search-result-item:hover {
    background: var(--bg-light);
}
.search-result-item:last-child {
    border-bottom: none;
}
.producto-codigo {
    font-weight: 600;
    font-size: 0.875rem;
}
.producto-nombre {
    font-size: 0.8125rem;
    color: var(--text-muted);
}
.producto-stock {
    font-size: 0.75rem;
    color: var(--primary);
}

.selected-producto {
    background: var(--bg-light);
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.selected-producto .info {
    flex: 1;
}
.selected-producto .stock-sistema {
    text-align: right;
}
.selected-producto .stock-value {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--primary);
}
.selected-producto .stock-label {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.conteo-inputs {
    display: grid;
    grid-template-columns: 1fr 1fr 2fr;
    gap: 1rem;
    margin-top: 1rem;
}
.conteo-inputs .form-group {
    margin: 0;
}

.diferencia-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.diferencia-badge.faltante { background: #fee2e2; color: #991b1b; }
.diferencia-badge.sobrante { background: #d1fae5; color: #065f46; }
.diferencia-badge.ok { background: #f3f4f6; color: #6b7280; }

.resumen-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.resumen-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1rem;
    text-align: center;
}
.resumen-value {
    font-size: 1.5rem;
    font-weight: 700;
}
.resumen-value.negative { color: #dc2626; }
.resumen-value.positive { color: #059669; }
.resumen-label {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
}
</style>

<div class="panel">
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
        <a href="?v=conteo&sid=<?= $sessionId ?>" class="inv-tab <?= $vista === 'conteo' ? 'active' : '' ?>">
            Conteo
            <span class="badge"><?= count($conteos) ?></span>
        </a>
        <a href="?v=resumen&sid=<?= $sessionId ?>" class="inv-tab <?= $vista === 'resumen' ? 'active' : '' ?>">
            Resumen
        </a>
        <?php endif; ?>
    </div>

    <?php if ($vista === 'sesiones'): ?>
    <!-- Lista de sesiones -->
    <?php if (empty($sesiones)): ?>
        <div class="empty-state" style="padding: 3rem; text-align: center;">
            <svg class="icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem; opacity: 0.5;">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
            </svg>
            <p style="margin: 0; color: var(--text-muted);">No hay sesiones de inventario</p>
            <p style="margin: 0.5rem 0 0; font-size: 0.875rem; color: var(--text-muted);">Creá una nueva sesión para empezar a contar</p>
        </div>
    <?php else: ?>
        <?php foreach ($sesiones as $sesion): ?>
        <a href="?v=conteo&sid=<?= $sesion['id'] ?>" class="session-card <?= $sesion['id'] === $sessionId ? 'active' : '' ?>" style="display: block; text-decoration: none; color: inherit;">
            <div class="session-header">
                <div>
                    <div class="session-name"><?= h($sesion['nombre']) ?></div>
                    <div class="session-meta">
                        Creada <?= h(date('d/m/Y H:i', strtotime($sesion['created_at']))) ?>
                        <?php if ($sesion['descripcion']): ?>
                            • <?= h($sesion['descripcion']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="session-status <?= strtolower($sesion['estado']) ?>"><?= h($sesion['estado']) ?></span>
            </div>
            <div class="session-stats">
                <div class="session-stat">
                    <div class="session-stat-value"><?= (int)($sesion['total_conteos'] ?? 0) ?></div>
                    <div class="session-stat-label">Productos contados</div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php elseif ($vista === 'conteo' && $currentSession): ?>
    <!-- Información de sesión actual -->
    <div class="session-card active" style="cursor: default;">
        <div class="session-header">
            <div>
                <div class="session-name"><?= h($currentSession['nombre']) ?></div>
                <div class="session-meta">
                    <?php if ($currentSession['descripcion']): ?>
                        <?= h($currentSession['descripcion']) ?> • 
                    <?php endif; ?>
                    Creada <?= h(date('d/m/Y H:i', strtotime($currentSession['created_at']))) ?>
                </div>
            </div>
            <span class="session-status <?= strtolower($currentSession['estado']) ?>"><?= h($currentSession['estado']) ?></span>
        </div>
    </div>

    <?php if ($currentSession['estado'] === 'ABIERTA'): ?>
    <!-- Formulario de conteo -->
    <div class="conteo-form">
        <h3>Registrar Conteo</h3>
        
        <div class="search-producto">
            <input type="text" id="buscarProducto" placeholder="Buscá por código o nombre del producto..." autocomplete="off">
            <div class="search-results" id="searchResults"></div>
        </div>

        <form method="post" id="formConteo" style="display: none;">
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

            <div style="margin-top: 1rem; display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-primary">Registrar Conteo</button>
                <button type="button" class="btn btn-ghost" onclick="cancelarSeleccion()">Cancelar</button>
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
                        <div class="producto-codigo"><?= h($conteo['codigo'] ?? $conteo['producto_id']) ?></div>
                        <div class="producto-nombre"><?= h($conteo['nombre'] ?? '') ?></div>
                    </td>
                    <td class="t-right"><?= number_format((float)$conteo['cantidad_sistema'], 2) ?></td>
                    <td class="t-right"><?= number_format((float)$conteo['cantidad_contada'], 2) ?></td>
                    <td class="t-right">
                        <?php 
                        $dif = (float)$conteo['diferencia'];
                        $clase = $dif < 0 ? 'faltante' : ($dif > 0 ? 'sobrante' : 'ok');
                        ?>
                        <span class="diferencia-badge <?= $clase ?>">
                            <?= $dif > 0 ? '+' : '' ?><?= number_format($dif, 2) ?>
                        </span>
                    </td>
                    <td><?= h($conteo['ubicacion'] ?? '-') ?></td>
                    <td><?= h($conteo['notas'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state" style="padding: 2rem; text-align: center; color: var(--text-muted);">
        <p>No hay conteos registrados en esta sesión</p>
    </div>
    <?php endif; ?>

    <?php elseif ($vista === 'resumen' && $currentSession): ?>
    <!-- Resumen de diferencias -->
    <div class="resumen-grid">
        <div class="resumen-card">
            <div class="resumen-value"><?= $resumen['productos_contados'] ?? 0 ?></div>
            <div class="resumen-label">Productos Contados</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-value"><?= $resumen['productos_con_diferencia'] ?? 0 ?></div>
            <div class="resumen-label">Con Diferencia</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-value negative"><?= $resumen['productos_faltantes'] ?? 0 ?></div>
            <div class="resumen-label">Faltantes</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-value positive"><?= $resumen['productos_sobrantes'] ?? 0 ?></div>
            <div class="resumen-label">Sobrantes</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-value <?= ($resumen['valor_diferencia'] ?? 0) < 0 ? 'negative' : 'positive' ?>">
                $<?= number_format(abs($resumen['valor_diferencia'] ?? 0), 2) ?>
            </div>
            <div class="resumen-label">Valor Diferencia</div>
        </div>
    </div>

    <!-- Acciones de sesión -->
    <?php if ($currentSession['estado'] === 'ABIERTA'): ?>
    <div style="display: flex; gap: 1rem; margin-bottom: 2rem;">
        <form method="post" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="cerrar_sesion">
            <input type="hidden" name="motivo" value="Cerrado sin aplicar">
            <button type="submit" class="btn btn-warning" onclick="return confirm('¿Cerrar sesión SIN aplicar ajustes?');">
                Cerrar Sin Aplicar
            </button>
        </form>
        
        <?php if (($resumen['productos_con_diferencia'] ?? 0) > 0): ?>
        <form method="post" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="aplicar_ajustes">
            <button type="submit" class="btn btn-primary" onclick="return confirm('¿Aplicar ajustes de stock? Esta acción NO se puede deshacer.');">
                Aplicar Ajustes (<?= $resumen['productos_con_diferencia'] ?> productos)
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Tabla de diferencias -->
    <?php 
    $conteosConDif = inventario_get_conteos($sessionId, true);
    if (!empty($conteosConDif)): 
    ?>
    <h3 style="margin-bottom: 1rem;">Productos con Diferencia</h3>
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
                        <div class="producto-codigo"><?= h($conteo['codigo'] ?? $conteo['producto_id']) ?></div>
                        <div class="producto-nombre"><?= h($conteo['nombre'] ?? '') ?></div>
                    </td>
                    <td class="t-right"><?= number_format((float)$conteo['cantidad_sistema'], 2) ?></td>
                    <td class="t-right"><?= number_format((float)$conteo['cantidad_contada'], 2) ?></td>
                    <td class="t-right">
                        <?php 
                        $dif = (float)$conteo['diferencia'];
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
<dialog id="modalNuevaSesion" style="border: none; border-radius: 12px; padding: 0; max-width: 400px; width: 100%;">
    <form method="post" style="padding: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="crear_sesion">
        
        <h3 style="margin: 0 0 1rem 0;">Nueva Sesión de Inventario</h3>
        
        <div class="form-group">
            <label>Nombre *</label>
            <input type="text" name="nombre" required class="form-control" placeholder="Ej: Inventario Enero 2026">
        </div>
        
        <div class="form-group">
            <label>Descripción</label>
            <textarea name="descripcion" class="form-control" rows="2" placeholder="Descripción opcional..."></textarea>
        </div>
        
        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalNuevaSesion').close()">Cancelar</button>
            <button type="submit" class="btn btn-primary">Crear Sesión</button>
        </div>
    </form>
</dialog>

<script>
// Búsqueda de productos
let searchTimeout;
const searchInput = document.getElementById('buscarProducto');
const searchResults = document.getElementById('searchResults');
const formConteo = document.getElementById('formConteo');

if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const q = this.value.trim();
        
        if (q.length < 2) {
            searchResults.classList.remove('show');
            return;
        }
        
        searchTimeout = setTimeout(() => {
            fetch('api/system_api.php?action=inventario_buscar_producto&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    const ok = (data.ok === true) || (data.success === true);
                    const arr = data.productos || data.data || [];
                    if (ok && Array.isArray(arr) && arr.length > 0) {
                        searchResults.innerHTML = arr.map(p => `
                            <div class="search-result-item" onclick="seleccionarProducto(${p.id}, '${escapeHtml(p.codigo)}', '${escapeHtml(p.nombre)}', ${p.stock})">
                                <div class="producto-codigo">${escapeHtml(p.codigo)}</div>
                                <div class="producto-nombre">${escapeHtml(p.nombre)}</div>
                                <div class="producto-stock">Stock: ${p.stock}</div>
                            </div>
                        `).join('');
                        searchResults.classList.add('show');
                    } else {
                        searchResults.innerHTML = '<div class="search-result-item">No se encontraron productos</div>';
                        searchResults.classList.add('show');
                    }
                });
        }, 300);
    });
    
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.classList.remove('show');
        }
    });
}

function seleccionarProducto(id, codigo, nombre, stock) {
    document.getElementById('productoId').value = id;
    document.getElementById('selCodigo').textContent = codigo;
    document.getElementById('selNombre').textContent = nombre;
    document.getElementById('selStock').textContent = stock;
    
    searchInput.style.display = 'none';
    searchResults.classList.remove('show');
    formConteo.style.display = 'block';
    document.getElementById('cantidadContada').focus();
}

function cancelarSeleccion() {
    formConteo.style.display = 'none';
    searchInput.style.display = 'block';
    searchInput.value = '';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
