<?php
// public/precios_historial.php - Historial de Precios FLUS
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

if (!user_has_permission('editar_productos')) {
    http_response_code(403);
    echo 'No tenés permisos para acceder a esta sección.';
    exit;
}

require_once __DIR__ . '/../src/precio_historial.php';

// Asegurar tablas existen
$pdo = getPDO();
precio_ensure_tables($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Historial de Precios - FLUS';
$currentSection = 'precios_historial';
$extraCss = [];
$extraJs = [];

$info = null;
$error = null;

// Vista actual
$vista = (string)($_GET['v'] ?? 'historial'); // historial | herramientas | margenes

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Token CSRF inválido.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        
        if ($accion === 'ajuste_masivo') {
            $porcentaje = (float)($_POST['porcentaje'] ?? 0);
            $tipo = (string)($_POST['tipo_precio'] ?? 'precio');
            $redondeo = (string)($_POST['redondeo'] ?? 'NINGUNO');
            $motivo = trim((string)($_POST['motivo'] ?? 'Ajuste masivo'));
            $productoIds = array_filter(array_map('intval', explode(',', (string)($_POST['producto_ids'] ?? ''))));
            
            if ($porcentaje == 0) {
                $error = 'El porcentaje no puede ser 0.';
            } elseif (empty($productoIds)) {
                $error = 'Seleccioná al menos un producto.';
            } else {
                $result = precio_ajuste_masivo_porcentaje($productoIds, $porcentaje, $tipo, $redondeo, $motivo);
                if ($result['actualizados'] > 0) {
                    $info = "Ajuste aplicado: {$result['actualizados']} productos actualizados.";
                } else {
                    $error = 'No se actualizó ningún producto.';
                }
            }
        } elseif ($accion === 'aplicar_margen') {
            $margen = (float)($_POST['margen'] ?? 0);
            $redondeo = (string)($_POST['redondeo'] ?? 'NINGUNO');
            $motivo = trim((string)($_POST['motivo'] ?? 'Aplicar margen'));
            $productoIds = array_filter(array_map('intval', explode(',', (string)($_POST['producto_ids'] ?? ''))));
            
            if ($margen <= 0) {
                $error = 'El margen debe ser mayor a 0.';
            } elseif (empty($productoIds)) {
                $error = 'Seleccioná al menos un producto.';
            } else {
                $result = precio_aplicar_margen($productoIds, $margen, $redondeo, $motivo);
                $actualizados = (int)($result['actualizados'] ?? 0);
                if ($actualizados > 0) {
                    $info = "Margen aplicado: $actualizados productos actualizados.";
                } else {
                    $error = 'No se actualizó ningún producto.';
                }
            }
        }
    }
}

// Cargar datos según vista
// (PDO ya inicializado arriba)

// Para historial: últimos cambios
$historial = [];
if ($vista === 'historial') {
    $productoId = (int)($_GET['pid'] ?? 0);
    
    if ($productoId > 0) {
        $historial = precio_get_historial($productoId, null, 50);
    } else {
        // Últimos cambios globales
        $stmt = $pdo->query("
            SELECT h.*, p.codigo, p.nombre as producto_nombre, u.nombre as usuario_nombre
            FROM producto_precios_hist h
            LEFT JOIN productos p ON h.producto_id = p.id
            LEFT JOIN users u ON h.user_id = u.id
            ORDER BY h.created_at DESC
            LIMIT 100
        ");
        $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Para herramientas: lista de productos
$productos = [];
if ($vista === 'herramientas') {
    $buscar = trim((string)($_GET['q'] ?? ''));
    $query = "SELECT id, codigo, nombre, precio, costo, stock FROM productos WHERE activo = 1";
    $params = [];
    
    if ($buscar) {
        $query .= " AND (codigo LIKE :q OR nombre LIKE :q)";
        $params['q'] = "%$buscar%";
    }
    
    $query .= " ORDER BY nombre LIMIT 200";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Para márgenes: estadísticas y productos con margen bajo
$estadisticas = null;
$margenBajo = [];
if ($vista === 'margenes') {
    $estadisticas = precio_estadisticas_margenes();
    $umbral = (float)($_GET['umbral'] ?? 15);
    $margenBajo = precio_productos_margen_bajo($umbral, 100);
}

require __DIR__ . '/partials/header.php';
?>

<style>
.precio-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--border-color, #e5e7eb);
}
.precio-tab {
    padding: 0.75rem 1.25rem;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-muted);
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.precio-tab:hover { color: var(--primary); }
.precio-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}

.hist-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1rem;
    border-bottom: 1px solid var(--border-light);
}
.hist-item:last-child { border-bottom: none; }
.hist-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.hist-icon.up { background: #d1fae5; color: #059669; }
.hist-icon.down { background: #fee2e2; color: #dc2626; }
.hist-content { flex: 1; }
.hist-product {
    font-weight: 600;
    font-size: 0.9375rem;
}
.hist-change {
    font-size: 0.875rem;
    margin-top: 0.25rem;
}
.hist-meta {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
}
.hist-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.hist-badge.up { background: #d1fae5; color: #065f46; }
.hist-badge.down { background: #fee2e2; color: #991b1b; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}
.stat-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
    text-align: center;
}
.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
}
.stat-value.danger { color: #dc2626; }
.stat-value.warning { color: #d97706; }
.stat-value.success { color: #059669; }
.stat-label {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
}

.tool-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.tool-card h3 {
    margin: 0 0 1rem 0;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.product-selector {
    border: 1px solid var(--border-color);
    border-radius: 8px;
    max-height: 300px;
    overflow-y: auto;
}
.product-selector-header {
    padding: 0.75rem 1rem;
    background: var(--bg-light);
    border-bottom: 1px solid var(--border-color);
    display: flex;
    gap: 1rem;
    align-items: center;
    position: sticky;
    top: 0;
}
.product-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-light);
    cursor: pointer;
}
.product-item:hover { background: var(--bg-light); }
.product-item:last-child { border-bottom: none; }
.product-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
}
.product-info { flex: 1; }
.product-code { font-weight: 600; font-size: 0.875rem; }
.product-name { font-size: 0.8125rem; color: var(--text-muted); }
.product-prices {
    text-align: right;
    font-size: 0.8125rem;
}
.product-prices .precio { font-weight: 600; color: var(--primary); }
.product-prices .costo { color: var(--text-muted); }

.margen-row {
    display: grid;
    grid-template-columns: 1fr auto auto auto;
    gap: 1rem;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--border-light);
}
.margen-bar {
    height: 8px;
    background: var(--bg-light);
    border-radius: 4px;
    overflow: hidden;
    width: 100px;
}
.margen-bar-fill {
    height: 100%;
    border-radius: 4px;
}
.margen-bar-fill.danger { background: #dc2626; }
.margen-bar-fill.warning { background: #d97706; }
.margen-bar-fill.success { background: #059669; }
</style>

<div class="panel">
    <div class="panel-head">
        <div>
            <h1>Gestión de Precios</h1>
            <p class="panel-subtitle">Historial de cambios y herramientas de actualización masiva</p>
        </div>
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
    <div class="precio-tabs">
        <a href="?v=historial" class="precio-tab <?= $vista === 'historial' ? 'active' : '' ?>">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem; vertical-align: -2px;">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            Historial
        </a>
        <a href="?v=herramientas" class="precio-tab <?= $vista === 'herramientas' ? 'active' : '' ?>">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem; vertical-align: -2px;">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
            </svg>
            Herramientas
        </a>
        <a href="?v=margenes" class="precio-tab <?= $vista === 'margenes' ? 'active' : '' ?>">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem; vertical-align: -2px;">
                <line x1="12" y1="20" x2="12" y2="10"/>
                <line x1="18" y1="20" x2="18" y2="4"/>
                <line x1="6" y1="20" x2="6" y2="16"/>
            </svg>
            Análisis de Márgenes
        </a>
    </div>

    <?php if ($vista === 'historial'): ?>
    <!-- Historial de cambios -->
    <div class="tool-card">
        <?php if (empty($historial)): ?>
            <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                <p>No hay cambios de precio registrados</p>
            </div>
        <?php else: ?>
            <?php foreach ($historial as $h): ?>
            <div class="hist-item">
                <div class="hist-icon <?= (float)$h['diferencia'] >= 0 ? 'up' : 'down' ?>">
                    <?php if ((float)$h['diferencia'] >= 0): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="18 15 12 9 6 15"/>
                        </svg>
                    <?php else: ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div class="hist-content">
                    <div class="hist-product">
                        <?= h($h['codigo'] ?? '') ?> - <?= h($h['producto_nombre'] ?? 'Producto #'.$h['producto_id']) ?>
                    </div>
                    <div class="hist-change">
                        <span style="color: var(--text-muted);"><?= h($h['tipo']) ?>:</span>
                        $<?= number_format((float)$h['precio_anterior'], 2) ?> → 
                        <strong>$<?= number_format((float)$h['precio_nuevo'], 2) ?></strong>
                        <span class="hist-badge <?= (float)$h['diferencia'] >= 0 ? 'up' : 'down' ?>">
                            <?= (float)$h['diferencia'] >= 0 ? '+' : '' ?><?= number_format((float)$h['diferencia_pct'], 1) ?>%
                        </span>
                    </div>
                    <div class="hist-meta">
                        <?= h(date('d/m/Y H:i', strtotime($h['created_at']))) ?>
                        <?php if (!empty($h['usuario_nombre'])): ?>
                            • <?= h($h['usuario_nombre']) ?>
                        <?php endif; ?>
                        <?php if (!empty($h['motivo'])): ?>
                            • <?= h($h['motivo']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php elseif ($vista === 'herramientas'): ?>
    <!-- Herramientas de actualización -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
        <!-- Ajuste por porcentaje -->
        <div class="tool-card">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="5" x2="5" y2="19"/>
                    <circle cx="6.5" cy="6.5" r="2.5"/>
                    <circle cx="17.5" cy="17.5" r="2.5"/>
                </svg>
                Ajuste por Porcentaje
            </h3>
            <form method="post" id="formAjustePorcentaje">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="ajuste_masivo">
                <input type="hidden" name="producto_ids" id="productoIdsAjuste">
                
                <div class="form-group">
                    <label>Porcentaje de ajuste</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="number" name="porcentaje" step="0.1" class="form-control" placeholder="Ej: 10 o -5" required style="width: 120px;">
                        <span>%</span>
                    </div>
                    <small class="text-muted">Positivo para aumentar, negativo para disminuir</small>
                </div>
                
                <div class="form-group">
                    <label>Aplicar a</label>
                    <select name="tipo_precio" class="form-control">
                        <option value="precio">Precio de venta</option>
                        <option value="costo">Costo</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Redondeo</label>
                    <select name="redondeo" class="form-control">
                        <option value="NINGUNO">Sin redondeo</option>
                        <option value="ENTERO">Entero más cercano</option>
                        <option value="5">Múltiplo de 5</option>
                        <option value="10">Múltiplo de 10</option>
                        <option value="50">Múltiplo de 50</option>
                        <option value="100">Múltiplo de 100</option>
                        <option value="990">Psicológico (X90/X99)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Motivo</label>
                    <input type="text" name="motivo" class="form-control" placeholder="Ej: Ajuste inflación enero">
                </div>
                
                <button type="submit" class="btn btn-primary" onclick="return validarSeleccion('productoIdsAjuste')">
                    Aplicar Ajuste
                </button>
            </form>
        </div>

        <!-- Aplicar margen sobre costo -->
        <div class="tool-card">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"/>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
                Aplicar Margen sobre Costo
            </h3>
            <form method="post" id="formAplicarMargen">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="aplicar_margen">
                <input type="hidden" name="producto_ids" id="productoIdsMargen">
                
                <div class="form-group">
                    <label>Margen deseado</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="number" name="margen" step="0.1" min="0" class="form-control" placeholder="Ej: 30" required style="width: 120px;">
                        <span>%</span>
                    </div>
                    <small class="text-muted">Precio = Costo × (1 + Margen/100)</small>
                </div>
                
                <div class="form-group">
                    <label>Redondeo</label>
                    <select name="redondeo" class="form-control">
                        <option value="NINGUNO">Sin redondeo</option>
                        <option value="ENTERO">Entero más cercano</option>
                        <option value="10">Múltiplo de 10</option>
                        <option value="50">Múltiplo de 50</option>
                        <option value="990">Psicológico (X90/X99)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Motivo</label>
                    <input type="text" name="motivo" class="form-control" placeholder="Ej: Normalizar márgenes">
                </div>
                
                <button type="submit" class="btn btn-primary" onclick="return validarSeleccion('productoIdsMargen')">
                    Aplicar Margen
                </button>
            </form>
        </div>
    </div>

    <!-- Selector de productos -->
    <div class="tool-card">
        <h3>Seleccionar Productos</h3>
        
        <form method="get" style="margin-bottom: 1rem;">
            <input type="hidden" name="v" value="herramientas">
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>" class="form-control" placeholder="Buscar por código o nombre..." style="flex: 1;">
                <button type="submit" class="btn btn-ghost">Buscar</button>
            </div>
        </form>
        
        <div class="product-selector">
            <div class="product-selector-header">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                    <span>Seleccionar todos</span>
                </label>
                <span id="selectedCount" style="color: var(--text-muted); font-size: 0.875rem;">0 seleccionados</span>
            </div>
            
            <?php if (empty($productos)): ?>
                <div style="padding: 2rem; text-align: center; color: var(--text-muted);">
                    <?= !empty($_GET['q']) ? 'No se encontraron productos' : 'Ingresá un término de búsqueda' ?>
                </div>
            <?php else: ?>
                <?php foreach ($productos as $p): ?>
                <label class="product-item">
                    <input type="checkbox" class="product-checkbox" value="<?= $p['id'] ?>" onchange="updateSelection()">
                    <div class="product-info">
                        <div class="product-code"><?= h($p['codigo']) ?></div>
                        <div class="product-name"><?= h($p['nombre']) ?></div>
                    </div>
                    <div class="product-prices">
                        <div class="precio">$<?= number_format((float)$p['precio'], 2) ?></div>
                        <div class="costo">Costo: $<?= number_format((float)$p['costo'], 2) ?></div>
                    </div>
                </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function toggleSelectAll() {
        const checked = document.getElementById('selectAll').checked;
        document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = checked);
        updateSelection();
    }
    
    function updateSelection() {
        const selected = [...document.querySelectorAll('.product-checkbox:checked')].map(cb => cb.value);
        document.getElementById('selectedCount').textContent = selected.length + ' seleccionados';
        document.getElementById('productoIdsAjuste').value = selected.join(',');
        document.getElementById('productoIdsMargen').value = selected.join(',');
    }
    
    function validarSeleccion(inputId) {
        const ids = document.getElementById(inputId).value;
        if (!ids) {
            alert('Seleccioná al menos un producto');
            return false;
        }
        return confirm('¿Aplicar cambios a ' + ids.split(',').length + ' productos?');
    }
    </script>

    <?php elseif ($vista === 'margenes'): ?>
    <!-- Análisis de márgenes -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($estadisticas['total_productos'] ?? 0) ?></div>
            <div class="stat-label">Productos Activos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value success"><?= number_format(($estadisticas['margen_promedio'] ?? 0), 1) ?>%</div>
            <div class="stat-label">Margen Promedio</div>
        </div>
        <div class="stat-card">
            <div class="stat-value danger"><?= $estadisticas['con_perdida'] ?? 0 ?></div>
            <div class="stat-label">Con Pérdida</div>
        </div>
        <div class="stat-card">
            <div class="stat-value warning"><?= $estadisticas['margen_bajo'] ?? 0 ?></div>
            <div class="stat-label">Margen Bajo (&lt;15%)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format(($estadisticas['margen_min'] ?? 0), 1) ?>%</div>
            <div class="stat-label">Margen Mínimo</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format(($estadisticas['margen_max'] ?? 0), 1) ?>%</div>
            <div class="stat-label">Margen Máximo</div>
        </div>
    </div>

    <!-- Filtro de umbral -->
    <div class="tool-card">
        <h3>Productos con Margen Bajo</h3>
        
        <form method="get" style="margin-bottom: 1rem;">
            <input type="hidden" name="v" value="margenes">
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <label>Mostrar productos con margen menor a:</label>
                <input type="number" name="umbral" value="<?= h($_GET['umbral'] ?? 15) ?>" step="1" class="form-control" style="width: 80px;">
                <span>%</span>
                <button type="submit" class="btn btn-ghost">Filtrar</button>
            </div>
        </form>
        
        <?php if (empty($margenBajo)): ?>
            <div style="padding: 2rem; text-align: center; color: var(--text-muted);">
                <p>No hay productos con margen inferior al <?= h($_GET['umbral'] ?? 15) ?>%</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="t-right">Costo</th>
                            <th class="t-right">Precio</th>
                            <th class="t-right">Margen</th>
                            <th>Indicador</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($margenBajo as $p): ?>
                        <tr>
                            <td>
                                <div class="product-code"><?= h($p['codigo']) ?></div>
                                <div class="product-name"><?= h($p['nombre']) ?></div>
                            </td>
                            <td class="t-right">$<?= number_format((float)$p['costo'], 2) ?></td>
                            <td class="t-right">$<?= number_format((float)$p['precio'], 2) ?></td>
                            <td class="t-right">
                                <?php 
                                $margen = (float)$p['margen_pct'];
                                $clase = $margen < 0 ? 'danger' : ($margen < 10 ? 'warning' : 'success');
                                ?>
                                <span class="stat-value <?= $clase ?>" style="font-size: 0.875rem;">
                                    <?= number_format($margen, 1) ?>%
                                </span>
                            </td>
                            <td>
                                <div class="margen-bar">
                                    <div class="margen-bar-fill <?= $clase ?>" style="width: <?= max(0, min(100, $margen)) ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
