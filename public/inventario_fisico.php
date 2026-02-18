<?php
// public/inventario_fisico.php - Inventario Físico FLUS v2.0
// Refactorizado con: Stepper, Progreso, Modo Rápido, Filtros, Modo Ciego, Exportar
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
$invTablesErr = null;
$invTablesOk = inventario_ensure_tables($invTablesErr);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Inventario Físico - FLUS';
$currentSection = 'inventario_fisico';

$extraCss = ['assets/css/inventario_fisico.css?v=2.0'];
$extraJs  = ['assets/js/inventario_fisico.js?v=2.0'];

$info = null;
$error = null;
if (isset($invTablesOk) && !$invTablesOk) {
    $error = 'Inventario físico: no se pudieron preparar las tablas. ' . ($invTablesErr ?: '');
}

// Vista actual
$vista = (string)($_GET['v'] ?? 'sesiones');
$sessionId = (int)($_GET['sid'] ?? 0);

// Filtros
$filtroCategoria = (int)($_GET['cat'] ?? 0);
$modoCiego = (bool)($_GET['ciego'] ?? false);
$modoRapido = (bool)($_GET['rapido'] ?? false);

// Cargar sesión actual
$currentSession = null;
if ($sessionId > 0) {
    $currentSession = inventario_session_get($sessionId);
    if (!$currentSession) {
        $error = $error ?: "La sesión #{$sessionId} no existe o no se pudo leer de la BD.";
        $sessionId = 0;
        $vista = 'sesiones';
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// EXPORTAR CSV
// ══════════════════════════════════════════════════════════════════════════════
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $sessionId > 0) {
    $conteos = inventario_get_conteos($sessionId, false);
    $sesion = inventario_session_get($sessionId);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventario_' . $sessionId . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    
    // Header
    fputcsv($output, ['Código', 'Producto', 'Stock Sistema', 'Contado', 'Diferencia', 'Valor Dif.', 'Ubicación', 'Notas']);
    
    foreach ($conteos as $c) {
        $dif = (float)($c['diferencia'] ?? 0);
        $costo = (float)($c['costo'] ?? 0);
        $valorDif = $dif * $costo;
        
        fputcsv($output, [
            $c['codigo'] ?? '',
            $c['nombre'] ?? '',
            number_format((float)($c['cantidad_sistema'] ?? 0), 2, ',', ''),
            number_format((float)($c['cantidad_contada'] ?? 0), 2, ',', ''),
            ($dif >= 0 ? '+' : '') . number_format($dif, 2, ',', ''),
            ($valorDif >= 0 ? '+' : '') . number_format($valorDif, 2, ',', ''),
            $c['ubicacion'] ?? '',
            $c['notas'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACCIONES POST
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Token CSRF inválido.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'crear_sesion') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $descripcion = trim((string)($_POST['descripcion'] ?? ''));
            $categoriaId = (int)($_POST['categoria_id'] ?? 0);

            if ($nombre === '') {
                $error = 'El nombre de la sesión es requerido.';
            } else {
                $errMsg = null;
                $newId = inventario_session_create($nombre, $descripcion, (int)($_SESSION['user_id'] ?? 0), $categoriaId > 0 ? $categoriaId : null, $errMsg);
                if ($newId) {
                    $currentSession = inventario_session_get($newId);
                    if ($currentSession) {
                        $info = 'Sesión de inventario creada.';
                        $sessionId = $newId;
                        $vista = 'conteo';
                        header("Location: ?v=conteo&sid={$newId}");
                        exit;
                    }
                    $error = 'Se creó un ID de sesión, pero no se pudo leer la sesión desde la BD. (Tablas/permisos/rollback)';
                } else {
                    $error = 'Error al crear la sesión: ' . ($errMsg ?: 'Error desconocido');
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
                    
                    // Si es AJAX, responder JSON
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['ok' => true, 'conteo_id' => $conteoId, 'mensaje' => 'Conteo registrado']);
                        exit;
                    }
                } else {
                    $error = 'Error al registrar conteo.';
                }
            }

        } elseif ($accion === 'cerrar_sesion' && $sessionId > 0) {
            $motivo = trim((string)($_POST['motivo'] ?? 'Cerrado manualmente'));
            if (inventario_session_close($sessionId, $motivo, (int)($_SESSION['user_id'] ?? 0))) {
                $info = 'Sesión cerrada.';
                $currentSession = inventario_session_get($sessionId);
                $vista = 'resumen';
                header("Location: ?v=resumen&sid={$sessionId}");
                exit;
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

// ══════════════════════════════════════════════════════════════════════════════
// CARGAR DATOS
// ══════════════════════════════════════════════════════════════════════════════
$sesiones = inventario_session_list();
$sesionesAbiertas = array_values(array_filter($sesiones, static fn($s) => ($s['estado'] ?? '') === 'ABIERTA'));

$conteos = [];
$resumen = null;
$productosYaContados = [];

if ($sessionId > 0) {
    $conteos = inventario_get_conteos($sessionId);
    $resumen = inventario_get_resumen_diferencias($sessionId);
    
    // Crear mapa de productos ya contados para JS
    foreach ($conteos as $c) {
        $productosYaContados[(int)$c['producto_id']] = [
            'cantidad' => (float)$c['cantidad_contada'],
            'ubicacion' => $c['ubicacion'] ?? null
        ];
    }
}

// Cargar categorías para filtro
$categorias = [];
try {
    $pdo = getPDO();
    $st = $pdo->query("SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre");
    $categorias = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Silenciar error
}

// Calcular estadísticas de progreso
$totalProductos = 0;
$productosContados = count($conteos);
$productosConDiferencia = (int)($resumen['productos_con_diferencia'] ?? 0);
$valorDiferencia = (float)($resumen['valor_diferencia'] ?? 0);

if ($filtroCategoria > 0) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE activo = 1 AND categoria_id = ?");
        $st->execute([$filtroCategoria]);
        $totalProductos = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
} else {
    try {
        $totalProductos = (int)$pdo->query("SELECT COUNT(*) FROM productos WHERE activo = 1")->fetchColumn();
    } catch (Throwable $e) {}
}

// Determinar paso actual del stepper
$pasoActual = 1;
if ($currentSession) {
    $estado = $currentSession['estado'] ?? '';
    if ($estado === 'ABIERTA' && $productosContados > 0) {
        $pasoActual = 2;
    } elseif ($estado === 'CERRADA') {
        $pasoActual = 3;
    } elseif ($estado === 'APLICADA') {
        $pasoActual = 4;
    }
}

require __DIR__ . '/partials/header.php';
?>

<div class="panel invf" data-productos-contados='<?= json_encode($productosYaContados) ?>'>
    
    <!-- ═══════════════════════════════════════════════════════════════════════
         HEADER + STEPPER
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="panel-head">
        <div>
            <h1>Inventario Físico</h1>
            <p class="panel-subtitle">Conteo y ajuste de stock real vs sistema</p>
        </div>
        <?php if ($vista === 'sesiones'): ?>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('modalNuevaSesion').showModal()">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Nueva Sesión
        </button>
        <?php endif; ?>
    </div>

    <!-- Stepper Visual -->
    <?php if ($currentSession): ?>
    <div class="inv-stepper">
        <div class="inv-step <?= $pasoActual >= 1 ? 'completed' : '' ?> <?= $pasoActual === 1 ? 'active' : '' ?>">
            <div class="inv-step-number">1</div>
            <div class="inv-step-label">Crear Sesión</div>
        </div>
        <div class="inv-step-line <?= $pasoActual >= 2 ? 'completed' : '' ?>"></div>
        <div class="inv-step <?= $pasoActual >= 2 ? 'completed' : '' ?> <?= $pasoActual === 2 ? 'active' : '' ?>">
            <div class="inv-step-number">2</div>
            <div class="inv-step-label">Contar</div>
        </div>
        <div class="inv-step-line <?= $pasoActual >= 3 ? 'completed' : '' ?>"></div>
        <div class="inv-step <?= $pasoActual >= 3 ? 'completed' : '' ?> <?= $pasoActual === 3 ? 'active' : '' ?>">
            <div class="inv-step-number">3</div>
            <div class="inv-step-label">Cerrar</div>
        </div>
        <div class="inv-step-line <?= $pasoActual >= 4 ? 'completed' : '' ?>"></div>
        <div class="inv-step <?= $pasoActual >= 4 ? 'completed' : '' ?> <?= $pasoActual === 4 ? 'active' : '' ?>">
            <div class="inv-step-number">4</div>
            <div class="inv-step-label">Aplicar</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Alertas -->
    <?php if ($info): ?>
        <div class="alert alert-ok">
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <span><?= h($info) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-err">
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span><?= h($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="inv-tabs">
        <a href="?v=sesiones" class="inv-tab <?= $vista === 'sesiones' ? 'active' : '' ?>">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            Sesiones
            <?php if (count($sesionesAbiertas) > 0): ?>
                <span class="badge badge-success"><?= count($sesionesAbiertas) ?></span>
            <?php endif; ?>
        </a>
        <?php if ($currentSession): ?>
        <a href="?v=conteo&sid=<?= (int)$sessionId ?>" class="inv-tab <?= $vista === 'conteo' ? 'active' : '' ?>">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Conteo
            <span class="badge"><?= $productosContados ?></span>
        </a>
        <a href="?v=resumen&sid=<?= (int)$sessionId ?>" class="inv-tab <?= $vista === 'resumen' ? 'active' : '' ?>">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
            Resumen
        </a>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         VISTA: SESIONES
    ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($vista === 'sesiones'): ?>
    
    <?php if (empty($sesiones)): ?>
        <div class="inv-empty inv-empty--lg">
            <div class="inv-empty-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                </svg>
            </div>
            <p class="inv-empty-title">No hay sesiones de inventario</p>
            <p class="inv-empty-text">Creá una nueva sesión para empezar a contar productos</p>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('modalNuevaSesion').showModal()">
                Crear primera sesión
            </button>
        </div>
    <?php else: ?>
        <div class="sessions-grid">
        <?php foreach ($sesiones as $sesion): ?>
        <?php
            $sid = (int)($sesion['id'] ?? 0);
            $prodContados = (int)($sesion['productos_contados'] ?? 0);
            $estadoSesion = strtolower((string)($sesion['estado'] ?? ''));
        ?>
        <a href="?v=conteo&sid=<?= $sid ?>" class="session-card <?= $sid === $sessionId ? 'active' : '' ?>">
            <div class="session-header">
                <div class="session-info">
                    <div class="session-name"><?= h((string)($sesion['nombre'] ?? '')) ?></div>
                    <div class="session-meta">
                        <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <?= h(date('d/m/Y H:i', strtotime((string)($sesion['created_at'] ?? 'now')))) ?>
                    </div>
                </div>
                <span class="session-status <?= $estadoSesion ?>"><?= h((string)($sesion['estado'] ?? '')) ?></span>
            </div>
            
            <?php if (!empty($sesion['descripcion'])): ?>
            <div class="session-desc"><?= h((string)$sesion['descripcion']) ?></div>
            <?php endif; ?>
            
            <div class="session-stats">
                <div class="session-stat">
                    <div class="session-stat-value"><?= $prodContados ?></div>
                    <div class="session-stat-label">Productos</div>
                </div>
                <?php if ($estadoSesion === 'aplicada'): ?>
                <div class="session-stat">
                    <svg class="icon session-stat-icon success" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <div class="session-stat-label">Aplicado</div>
                </div>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         VISTA: CONTEO
    ═══════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($vista === 'conteo' && $currentSession): ?>
    
    <!-- Panel de Progreso -->
    <div class="inv-progress-panel">
        <div class="inv-progress-stats">
            <div class="inv-progress-stat">
                <div class="inv-progress-value"><?= $productosContados ?></div>
                <div class="inv-progress-label">Contados</div>
            </div>
            <div class="inv-progress-stat">
                <div class="inv-progress-value <?= $productosConDiferencia > 0 ? 'warning' : '' ?>"><?= $productosConDiferencia ?></div>
                <div class="inv-progress-label">Con diferencia</div>
            </div>
            <div class="inv-progress-stat">
                <div class="inv-progress-value <?= $valorDiferencia < 0 ? 'negative' : ($valorDiferencia > 0 ? 'positive' : '') ?>">
                    $<?= number_format(abs($valorDiferencia), 0, ',', '.') ?>
                </div>
                <div class="inv-progress-label"><?= $valorDiferencia < 0 ? 'Faltante' : ($valorDiferencia > 0 ? 'Sobrante' : 'Diferencia') ?></div>
            </div>
        </div>
        
        <?php if ($totalProductos > 0): ?>
        <div class="inv-progress-bar-wrap">
            <div class="inv-progress-bar">
                <div class="inv-progress-fill" style="width: <?= min(100, round($productosContados / $totalProductos * 100)) ?>%"></div>
            </div>
            <div class="inv-progress-text"><?= $productosContados ?> de <?= $totalProductos ?> productos (<?= round($productosContados / $totalProductos * 100) ?>%)</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Info de Sesión -->
    <div class="session-card active session-card-static">
        <div class="session-header">
            <div class="session-info">
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
            <a class="btn btn-ghost btn-sm" href="?v=resumen&sid=<?= (int)$sessionId ?>">
                <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
                </svg>
                Ver resumen
            </a>
            <?php if (($currentSession['estado'] ?? '') === 'ABIERTA'): ?>
            <form method="post" class="inv-inline-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="cerrar_sesion">
                <input type="hidden" name="motivo" value="Cerrado para aplicar ajustes">
                <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('¿Cerrar la sesión? Luego podrás aplicar ajustes desde Resumen.');">
                    <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    Cerrar sesión
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (($currentSession['estado'] ?? '') === 'ABIERTA'): ?>
    <!-- Controles de Modo -->
    <div class="inv-mode-controls">
        <label class="inv-mode-toggle">
            <input type="checkbox" id="toggleModoCiego" <?= $modoCiego ? 'checked' : '' ?>>
            <span class="inv-mode-toggle-slider"></span>
            <span class="inv-mode-toggle-label">
                <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
                Modo ciego
            </span>
        </label>
        
        <label class="inv-mode-toggle">
            <input type="checkbox" id="toggleModoRapido" <?= $modoRapido ? 'checked' : '' ?>>
            <span class="inv-mode-toggle-slider"></span>
            <span class="inv-mode-toggle-label">
                <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                </svg>
                Modo rápido
            </span>
        </label>
        
        <div class="inv-mode-help">
            <button type="button" class="btn-help" id="btnAyuda" title="Ayuda">
                <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Formulario de Conteo -->
    <div class="conteo-form">
        <h3>
            <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
            Registrar Conteo
        </h3>

        <!-- Buscador -->
        <div class="search-producto">
            <div class="search-input-wrap">
                <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" id="buscarProducto" class="form-control" placeholder="Escaneá o buscá por código/nombre..." autocomplete="off" autofocus>
                <kbd class="search-kbd">F5</kbd>
            </div>
            <div class="search-results" id="searchResults" aria-live="polite"></div>
        </div>

        <!-- Formulario (oculto hasta seleccionar producto) -->
        <form method="post" id="formConteo" class="is-hidden">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="registrar_conteo">
            <input type="hidden" name="producto_id" id="productoId">

            <!-- Producto Seleccionado -->
            <div class="selected-producto" id="productoSeleccionado">
                <div class="selected-producto-main">
                    <div class="selected-producto-info">
                        <div class="producto-codigo" id="selCodigo"></div>
                        <div class="producto-nombre" id="selNombre"></div>
                    </div>
                    <button type="button" class="btn-clear-selection" onclick="window.cancelarSeleccion && window.cancelarSeleccion()" title="Cambiar producto">
                        <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                
                <div class="selected-producto-stock" id="stockSistemaWrap">
                    <div class="stock-value" id="selStock">0</div>
                    <div class="stock-label">Stock Sistema</div>
                </div>
                
                <!-- Badge "Ya contado" -->
                <div class="ya-contado-badge is-hidden" id="yaContadoBadge">
                    <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <span>Ya contado: <strong id="yaContadoCantidad">0</strong></span>
                </div>
            </div>

            <!-- Inputs de Conteo -->
            <div class="conteo-inputs">
                <div class="form-group form-group-cantidad">
                    <label for="cantidadContada">
                        Cantidad Contada *
                        <span class="label-hint" id="labelHintDif"></span>
                    </label>
                    <input type="number" name="cantidad" id="cantidadContada" min="0" step="0.01" required class="form-control form-control-lg" inputmode="decimal">
                </div>
                <div class="form-group">
                    <label for="ubicacionInput">Ubicación</label>
                    <input type="text" name="ubicacion" id="ubicacionInput" placeholder="Ej: Estante A3" class="form-control" list="ubicacionesList">
                    <datalist id="ubicacionesList">
                        <option value="Estante A">
                        <option value="Estante B">
                        <option value="Estante C">
                        <option value="Góndola 1">
                        <option value="Góndola 2">
                        <option value="Heladera">
                        <option value="Freezer">
                        <option value="Depósito">
                    </datalist>
                </div>
                <div class="form-group">
                    <label for="notasInput">Notas</label>
                    <input type="text" name="notas" id="notasInput" placeholder="Observaciones..." class="form-control">
                </div>
            </div>

            <!-- Preview de diferencia -->
            <div class="conteo-preview is-hidden" id="conteoPreview">
                <div class="conteo-preview-label">Diferencia:</div>
                <div class="conteo-preview-value" id="previewDiferencia">0</div>
            </div>

            <!-- Botones -->
            <div class="inv-actions-row">
                <button type="submit" class="btn btn-primary btn-lg" id="btnRegistrar">
                    <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Registrar Conteo
                    <kbd>Enter</kbd>
                </button>
                <button type="button" class="btn btn-ghost" onclick="window.cancelarSeleccion && window.cancelarSeleccion()">Cancelar</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Lista de Conteos -->
    <?php if (!empty($conteos)): ?>
    <div class="conteos-header">
        <h3 class="inv-subtitle">
            <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            Conteos Registrados (<?= count($conteos) ?>)
        </h3>
        <div class="conteos-actions">
            <input type="text" id="filtrarConteos" class="form-control form-control-sm" placeholder="Filtrar...">
        </div>
    </div>
    
    <div class="table-wrap">
        <table class="table" id="tablaConteos">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="t-right col-stock">Stock Sistema</th>
                    <th class="t-right col-contado">Contado</th>
                    <th class="t-right col-dif">Diferencia</th>
                    <th class="col-ubicacion">Ubicación</th>
                    <th class="col-notas">Notas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conteos as $conteo): ?>
                <tr data-producto="<?= h(strtolower(($conteo['codigo'] ?? '') . ' ' . ($conteo['nombre'] ?? ''))) ?>">
                    <td>
                        <div class="producto-codigo"><?= h((string)($conteo['codigo'] ?? $conteo['producto_id'] ?? '')) ?></div>
                        <div class="producto-nombre"><?= h((string)($conteo['nombre'] ?? '')) ?></div>
                    </td>
                    <td class="t-right"><?= number_format((float)($conteo['cantidad_sistema'] ?? 0), 2) ?></td>
                    <td class="t-right"><strong><?= number_format((float)($conteo['cantidad_contada'] ?? 0), 2) ?></strong></td>
                    <td class="t-right">
                        <?php
                        $dif = (float)($conteo['diferencia'] ?? 0);
                        $clase = $dif < -0.001 ? 'faltante' : ($dif > 0.001 ? 'sobrante' : 'ok');
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
    <?php elseif (($currentSession['estado'] ?? '') === 'ABIERTA'): ?>
    <div class="inv-empty inv-empty--md">
        <svg class="icon inv-empty-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
        </svg>
        <p class="inv-empty-text">No hay conteos registrados</p>
        <p class="inv-empty-subtext">Buscá un producto arriba para empezar a contar</p>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         VISTA: RESUMEN
    ═══════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($vista === 'resumen' && $currentSession): ?>
    
    <!-- Cards de Resumen -->
    <div class="resumen-grid">
        <div class="resumen-card">
            <div class="resumen-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                </svg>
            </div>
            <div class="resumen-value"><?= (int)($resumen['productos_contados'] ?? 0) ?></div>
            <div class="resumen-label">Productos Contados</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-icon warning">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                </svg>
            </div>
            <div class="resumen-value"><?= (int)($resumen['productos_con_diferencia'] ?? 0) ?></div>
            <div class="resumen-label">Con Diferencia</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-icon danger">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>
                </svg>
            </div>
            <div class="resumen-value negative"><?= (int)($resumen['productos_faltantes'] ?? 0) ?></div>
            <div class="resumen-label">Faltantes</div>
        </div>
        <div class="resumen-card">
            <div class="resumen-icon success">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>
                </svg>
            </div>
            <div class="resumen-value positive"><?= (int)($resumen['productos_sobrantes'] ?? 0) ?></div>
            <div class="resumen-label">Sobrantes</div>
        </div>
        <div class="resumen-card resumen-card-wide">
            <div class="resumen-icon <?= $valorDiferencia < 0 ? 'danger' : 'success' ?>">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </div>
            <div class="resumen-value <?= $valorDiferencia < 0 ? 'negative' : 'positive' ?>">
                <?= $valorDiferencia < 0 ? '-' : '+' ?>$<?= number_format(abs($valorDiferencia), 2, ',', '.') ?>
            </div>
            <div class="resumen-label">Valor Total Diferencia</div>
        </div>
    </div>

    <!-- Acciones de sesión -->
    <?php if (($currentSession['estado'] ?? '') === 'ABIERTA'): ?>
        <div class="inv-actions-panel">
            <div class="inv-hint">
                <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span>Para poder <strong>aplicar ajustes</strong>, primero tenés que <strong>cerrar la sesión</strong>.</span>
            </div>
            <div class="inv-actions-row">
                <a class="btn btn-ghost" href="?v=conteo&sid=<?= (int)$sessionId ?>">
                    <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Volver al conteo
                </a>
                <form method="post" class="inv-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="accion" value="cerrar_sesion">
                    <input type="hidden" name="motivo" value="Cerrado para aplicar ajustes">
                    <button type="submit" class="btn btn-warning" onclick="return confirm('¿Cerrar la sesión? Esto bloquea nuevos conteos.');">
                        <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        Cerrar sesión
                    </button>
                </form>
            </div>
        </div>
    <?php elseif (($currentSession['estado'] ?? '') === 'CERRADA'): ?>
        <div class="inv-actions-panel">
            <div class="inv-actions-row">
                <a class="btn btn-ghost" href="?v=conteo&sid=<?= (int)$sessionId ?>">Ver conteos</a>
                
                <a class="btn btn-secondary" href="?v=resumen&sid=<?= (int)$sessionId ?>&export=csv">
                    <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Exportar CSV
                </a>
                
                <?php if ((int)($resumen['productos_con_diferencia'] ?? 0) > 0): ?>
                <form method="post" class="inv-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="accion" value="aplicar_ajustes">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('¿Aplicar ajustes de stock? Esta acción NO se puede deshacer.\n\nSe ajustarán <?= (int)$resumen['productos_con_diferencia'] ?> productos.');">
                        <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        Aplicar ajustes (<?= (int)$resumen['productos_con_diferencia'] ?> productos)
                    </button>
                </form>
                <?php else: ?>
                <span class="inv-hint-inline">No hay diferencias para aplicar</span>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif (($currentSession['estado'] ?? '') === 'APLICADA'): ?>
        <div class="inv-actions-panel inv-actions-panel--success">
            <div class="inv-success-message">
                <svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <div>
                    <strong>Ajustes aplicados correctamente</strong>
                    <p>Los stocks fueron actualizados el <?= h(date('d/m/Y H:i', strtotime((string)($currentSession['applied_at'] ?? 'now')))) ?></p>
                </div>
            </div>
            <div class="inv-actions-row">
                <a class="btn btn-ghost" href="?v=conteo&sid=<?= (int)$sessionId ?>">Ver conteos</a>
                <a class="btn btn-secondary" href="?v=resumen&sid=<?= (int)$sessionId ?>&export=csv">
                    <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Exportar CSV
                </a>
                <a class="btn btn-primary" href="?v=sesiones">Nueva sesión</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tabla de diferencias -->
    <?php
    $conteosConDif = inventario_get_conteos($sessionId, true);
    if (!empty($conteosConDif)):
    ?>
    <h3 class="inv-subtitle">
        <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        </svg>
        Productos con Diferencia (<?= count($conteosConDif) ?>)
    </h3>
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
                    <td class="t-right"><strong><?= number_format((float)($conteo['cantidad_contada'] ?? 0), 2) ?></strong></td>
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
                            <?= $valorDif < 0 ? '-' : '+' ?>$<?= number_format(abs($valorDif), 2) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif (($currentSession['estado'] ?? '') !== 'ABIERTA'): ?>
    <div class="inv-empty inv-empty--md">
        <svg class="icon inv-empty-icon success" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
        <p class="inv-empty-text">¡Sin diferencias!</p>
        <p class="inv-empty-subtext">El stock del sistema coincide con el conteo físico</p>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: NUEVA SESIÓN
═══════════════════════════════════════════════════════════════════════════ -->
<dialog id="modalNuevaSesion" class="inv-modal">
    <form method="post" class="inv-modal-body">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="crear_sesion">

        <div class="inv-modal-header">
            <h3 class="inv-modal-title">
                <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Nueva Sesión de Inventario
            </h3>
            <button type="button" class="inv-modal-close" onclick="document.getElementById('modalNuevaSesion').close()">
                <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="form-group">
            <label for="sesionNombre">Nombre de la sesión *</label>
            <input type="text" name="nombre" id="sesionNombre" required class="form-control" placeholder="Ej: Inventario Febrero 2026">
        </div>

        <div class="form-group">
            <label for="sesionDesc">Descripción (opcional)</label>
            <textarea name="descripcion" id="sesionDesc" class="form-control" rows="2" placeholder="Notas sobre esta sesión..."></textarea>
        </div>

        <?php if (!empty($categorias)): ?>
        <div class="form-group">
            <label for="sesionCategoria">Filtrar por categoría (opcional)</label>
            <select name="categoria_id" id="sesionCategoria" class="form-control">
                <option value="0">Todos los productos</option>
                <?php foreach ($categorias as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= h($cat['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">Si elegís una categoría, solo podrás contar productos de esa categoría</p>
        </div>
        <?php endif; ?>

        <div class="inv-modal-actions">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalNuevaSesion').close()">Cancelar</button>
            <button type="submit" class="btn btn-primary">
                <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Crear Sesión
            </button>
        </div>
    </form>
</dialog>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: AYUDA
═══════════════════════════════════════════════════════════════════════════ -->
<dialog id="modalAyuda" class="inv-modal inv-modal--lg">
    <div class="inv-modal-body">
        <div class="inv-modal-header">
            <h3 class="inv-modal-title">
                <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                Ayuda - Inventario Físico
            </h3>
            <button type="button" class="inv-modal-close" onclick="document.getElementById('modalAyuda').close()">
                <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="ayuda-content">
            <div class="ayuda-section">
                <h4>📋 ¿Cómo funciona?</h4>
                <ol>
                    <li><strong>Crear sesión:</strong> Iniciá un nuevo inventario con un nombre descriptivo</li>
                    <li><strong>Contar:</strong> Escaneá o buscá productos y registrá la cantidad física</li>
                    <li><strong>Cerrar:</strong> Cuando termines de contar, cerrá la sesión</li>
                    <li><strong>Aplicar:</strong> Revisá las diferencias y aplicá los ajustes al stock</li>
                </ol>
            </div>

            <div class="ayuda-section">
                <h4>⚡ Modos de conteo</h4>
                <dl>
                    <dt>
                        <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                        Modo Ciego
                    </dt>
                    <dd>Oculta el stock del sistema para evitar que influya en tu conteo. Recomendado para mayor precisión.</dd>
                    
                    <dt>
                        <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                        </svg>
                        Modo Rápido
                    </dt>
                    <dd>Optimizado para pistola lectora. Después de registrar un conteo, el foco vuelve automáticamente al buscador.</dd>
                </dl>
            </div>

            <div class="ayuda-section">
                <h4>⌨️ Atajos de teclado</h4>
                <div class="ayuda-shortcuts">
                    <div class="ayuda-shortcut"><kbd>F5</kbd> Ir al buscador</div>
                    <div class="ayuda-shortcut"><kbd>Enter</kbd> Registrar conteo</div>
                    <div class="ayuda-shortcut"><kbd>Esc</kbd> Cancelar / Cerrar modal</div>
                </div>
            </div>

            <div class="ayuda-section">
                <h4>💡 Consejos</h4>
                <ul>
                    <li>Podés recontar un producto las veces que quieras. Solo se usa el último valor.</li>
                    <li>El campo "Ubicación" te ayuda a organizar el conteo por zonas.</li>
                    <li>Exportá a CSV para analizar los resultados en Excel.</li>
                    <li>Los ajustes son irreversibles. Revisá bien antes de aplicar.</li>
                </ul>
            </div>
        </div>

        <div class="inv-modal-actions">
            <button type="button" class="btn btn-primary" onclick="document.getElementById('modalAyuda').close()">Entendido</button>
        </div>
    </div>
</dialog>

<?php require __DIR__ . '/partials/footer.php'; ?>
