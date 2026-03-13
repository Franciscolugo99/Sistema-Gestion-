<?php
// public/api/system_api.php
declare(strict_types=1);

/**
 * FLUS System API
 * API unificada para: diagnósticos, backups, inventario físico, precios, reposición
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/_bootstrap.php';
require_once FLUS_ROOT . '/src/diagnostics_lib.php';
require_once FLUS_ROOT . '/src/backup_enhanced.php';
require_once FLUS_ROOT . '/src/audit_events.php';

// Cargar módulos opcionales
$inventarioFisico = FLUS_ROOT . '/src/inventario_fisico.php';
$precioHistorial = FLUS_ROOT . '/src/precio_historial.php';
$reposicionSugerida = FLUS_ROOT . '/src/reposicion_sugerida.php';

if (is_file($inventarioFisico)) require_once $inventarioFisico;
if (is_file($precioHistorial)) require_once $precioHistorial;
if (is_file($reposicionSugerida)) require_once $reposicionSugerida;

// Auth
require_once FLUS_ROOT . '/public/auth.php';
require_once FLUS_ROOT . '/public/lib/csrf.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$canReposicionView = static function (): bool {
    return function_exists('user_has_permission')
        && (user_has_permission('ver_reportes') || user_has_permission('ver_stock') || user_has_permission('editar_stock'));
};

$canReposicionConfig = static function (): bool {
    return function_exists('user_has_permission')
        && (user_has_permission('gestionar_stock') || user_has_permission('editar_stock'));
};

try {
    switch ($action) {
        
        // ============================================
        // DIAGNÓSTICOS Y HEALTH CHECK
        // ============================================
        
        case 'health':
        case 'health_check': {
            require_login_json();
            
            $health = flus_health_check();
            json_ok($health);
        }
        
        case 'health_detailed': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            $health = flus_health_check();
            $health['schema'] = flus_get_schema_summary();
            $health['config'] = flus_get_sanitized_config();
            
            json_ok($health);
        }
        
        case 'diagnostico_generar': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            $filepath = flus_generate_diagnostic_package();
            
            if (!$filepath) {
                json_fail('No se pudo generar el paquete de diagnóstico');
            }
            
            // Registrar auditoría
            audit_event('DIAGNOSTIC_EXPORT', 'SYSTEM', null, [
                'file' => basename($filepath),
            ]);
            
            json_ok([
                'file' => basename($filepath),
                'download_url' => 'diagnostico_download.php?f=' . urlencode(basename($filepath)),
            ]);
        }
        
        case 'logs_recientes': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            $lines = (int)($_GET['lines'] ?? 100);
            $logs = flus_get_recent_logs(min($lines, 500));
            
            json_ok(['logs' => $logs]);
        }
        
        case 'schema_summary': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            $schema = flus_get_schema_summary();
            json_ok($schema);
        }
        
        // ============================================
        // BACKUPS MEJORADOS
        // ============================================
        
        case 'backup_create': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            $body = api_read_json();
            $includeStorage = !empty($body['include_storage']);
            
            $err = null;
            $file = flus_backup_create_full($includeStorage, $err);
            
            if (!$file) {
                json_fail($err ?: 'Error al crear backup', 500);
            }
            
            audit_backup(AuditEvents::BACKUP_CREATE, [
                'file' => $file,
                'include_storage' => $includeStorage,
            ]);
            
            json_ok([
                'file' => $file,
                'message' => 'Backup creado exitosamente',
            ]);
        }
        
        case 'backup_list': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            $items = flus_backup_list_enhanced();
            
            json_ok([
                'items' => $items,
                'count' => count($items),
            ]);
        }
        
        case 'backup_validate': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            $file = $_GET['file'] ?? '';
            if (!$file) json_fail('Archivo requerido', 400);
            
            $err = null;
            $validation = flus_backup_validate($file, $err);
            
            if ($err) {
                json_fail($err, 400);
            }
            
            json_ok($validation);
        }
        
        case 'backup_info': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            $file = $_GET['file'] ?? '';
            if (!$file) json_fail('Archivo requerido', 400);
            
            $info = flus_backup_info($file);
            json_ok($info);
        }
        
        case 'backup_restore': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            $body = api_read_json();
            $file = $body['file'] ?? '';
            
            if (!$file) json_fail('Archivo requerido', 400);
            
            $err = null;
            $result = flus_backup_restore_safe($file, $err);
            
            if (!$result) {
                json_fail($err ?: 'Error al restaurar backup', 500);
            }
            
            json_ok([
                'message' => 'Backup restaurado exitosamente',
                'file' => $file,
            ]);
        }
        
        // ============================================
        // AUDITORÍA
        // ============================================
        
        case 'audit_list': {
            require_login_json();
            require_perm_json('ver_auditoria');
            
            $filters = [];
            if (!empty($_GET['action'])) $filters['action'] = $_GET['action'];
            if (!empty($_GET['entity'])) $filters['entity'] = $_GET['entity'];
            if (!empty($_GET['user_id'])) $filters['user_id'] = (int)$_GET['user_id'];
            if (!empty($_GET['from_date'])) $filters['from_date'] = $_GET['from_date'];
            if (!empty($_GET['to_date'])) $filters['to_date'] = $_GET['to_date'];
            
            $limit = min((int)($_GET['limit'] ?? 50), 200);
            $offset = (int)($_GET['offset'] ?? 0);
            
            $events = audit_query($filters, $limit, $offset);
            $total = audit_count($filters);
            
            json_ok([
                'events' => $events,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        }
        
        case 'audit_summary': {
            require_login_json();
            require_perm_json('ver_auditoria');
            
            $days = (int)($_GET['days'] ?? 7);
            $summary = audit_summary($days);
            
            json_ok($summary);
        }
        
        case 'audit_rotate': {
            require_login_json();
            require_perm_json('gestionar_backups');
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            $body = api_read_json();
            $days = (int)($body['days'] ?? 90);
            
            $deleted = audit_rotate($days);
            
            json_ok([
                'deleted' => $deleted,
                'retention_days' => $days,
            ]);
        }
        
        // ============================================
        // INVENTARIO FÍSICO
        // ============================================
        
        case 'inventario_session_create': {
            require_login_json();
            require_perm_json('editar_stock');
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            if (!function_exists('inventario_session_create')) {
                json_fail('Módulo de inventario físico no disponible', 503);
            }
            
            $body = api_read_json();
            $nombre = trim($body['nombre'] ?? '');
            $descripcion = trim($body['descripcion'] ?? '') ?: null;
            
            if (!$nombre) json_fail('Nombre requerido', 400);
            
            $userId = (int)($_SESSION['user_id'] ?? 0);

            // Compatibilidad de firma: (nombre, descripcion) o (nombre, descripcion, userId)
            try {
                $ref = new ReflectionFunction('inventario_session_create');
                $argc = $ref->getNumberOfParameters();
                $sessionId = ($argc >= 3)
                    ? inventario_session_create($nombre, $descripcion, $userId)
                    : inventario_session_create($nombre, $descripcion);
            } catch (Throwable $e) {
                try {
                    $sessionId = inventario_session_create($nombre, $descripcion);
                } catch (Throwable $e2) {
                    $sessionId = inventario_session_create($nombre, $descripcion, $userId);
                }
            }
            
            if (!$sessionId) {
                json_fail('Error al crear sesión de inventario', 500);
            }
            
            json_ok([
                'session_id' => $sessionId,
                'message' => 'Sesión de inventario creada',
            ]);
        }
        
        case 'inventario_session_list': {
            require_login_json();
            require_perm_json('editar_stock');
            
            if (!function_exists('inventario_session_list')) {
                json_fail('Módulo de inventario físico no disponible', 503);
            }
            
            $estado = $_GET['estado'] ?? null;
            $sessions = inventario_session_list($estado);
            
            json_ok(['sessions' => $sessions]);
        }
        
        case 'inventario_session_get': {
            require_login_json();
            require_perm_json('editar_stock');
            
            if (!function_exists('inventario_session_get')) {
                json_fail('Módulo de inventario físico no disponible', 503);
            }
            
            $sessionId = (int)($_GET['id'] ?? 0);
            if (!$sessionId) json_fail('ID de sesión requerido', 400);
            
            $session = inventario_session_get($sessionId);
            
            if (!$session) {
                json_fail('Sesión no encontrada', 404);
            }
            
            json_ok(['session' => $session]);
        }
        
        case 'inventario_conteo_registrar': {
            require_login_json();
            require_perm_json('editar_stock');
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            if (!function_exists('inventario_registrar_conteo')) {
                json_fail('Módulo de inventario físico no disponible', 503);
            }
            
            $body = api_read_json();
            $sessionId = (int)($body['session_id'] ?? 0);
            $productoId = (int)($body['producto_id'] ?? 0);
            $cantidad = (float)($body['cantidad'] ?? 0);
            $ubicacion = $body['ubicacion'] ?? null;
            $notas = $body['notas'] ?? null;
            
            if (!$sessionId || !$productoId) {
                json_fail('session_id y producto_id requeridos', 400);
            }
            
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $errMsg = null;

            // Compatibilidad de firma: (..., ubicacion, notas) o (..., ubicacion, notas, userId)
            try {
                $ref = new ReflectionFunction('inventario_registrar_conteo');
                $argc = $ref->getNumberOfParameters();
                if ($argc >= 7) {
                    $conteoId = inventario_registrar_conteo($sessionId, $productoId, $cantidad, $ubicacion, $notas, $userId, $errMsg);
                } elseif ($argc >= 6) {
                    $conteoId = inventario_registrar_conteo($sessionId, $productoId, $cantidad, $ubicacion, $notas, $userId);
                } else {
                    $conteoId = inventario_registrar_conteo($sessionId, $productoId, $cantidad, $ubicacion, $notas);
                }
            } catch (Throwable $e) {
                try {
                    $conteoId = inventario_registrar_conteo($sessionId, $productoId, $cantidad, $ubicacion, $notas);
                } catch (Throwable $e2) {
                    $conteoId = inventario_registrar_conteo($sessionId, $productoId, $cantidad, $ubicacion, $notas, $userId);
                }
            }
            
            if (!$conteoId) {
                json_fail($errMsg ?: 'Error al registrar conteo', 500);
            }
            
            json_ok([
                'conteo_id' => $conteoId,
                'message' => 'Conteo registrado',
            ]);
        }
        
        case 'inventario_conteos_get': {
            require_login_json();
            require_perm_json('editar_stock');
            
            if (!function_exists('inventario_get_conteos')) {
                json_fail('Módulo de inventario físico no disponible', 503);
            }
            
            $sessionId = (int)($_GET['session_id'] ?? 0);
            $soloConDiferencia = !empty($_GET['solo_diferencias']);
            
            if (!$sessionId) json_fail('session_id requerido', 400);
            
            $conteos = inventario_get_conteos($sessionId, $soloConDiferencia);
            
            json_ok(['conteos' => $conteos]);
        }
        
        case 'inventario_resumen': {
            require_login_json();
            require_perm_json('editar_stock');
            
            if (!function_exists('inventario_get_resumen_diferencias')) {
                json_fail('Módulo de inventario físico no disponible', 503);
            }
            
            $sessionId = (int)($_GET['session_id'] ?? 0);
            if (!$sessionId) json_fail('session_id requerido', 400);
            
            $resumen = inventario_get_resumen_diferencias($sessionId);
            
            json_ok($resumen);
        }
        
        case 'inventario_aplicar_ajustes': {
            require_login_json();
            require_perm_json('editar_stock');
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            if (!function_exists('inventario_aplicar_ajustes')) {
                json_fail('Módulo de inventario físico no disponible', 503);
            }
            
            $body = api_read_json();
            $sessionId = (int)($body['session_id'] ?? 0);
            $motivo = $body['motivo'] ?? null;
            
            if (!$sessionId) json_fail('session_id requerido', 400);
            
            $result = inventario_aplicar_ajustes($sessionId, $motivo);
            
            if (!$result['success']) {
                json_fail(implode(', ', $result['errores']), 500);
            }
            
            json_ok($result);
        }
        
        case 'inventario_buscar_producto': {
            require_login_json();
            require_perm_json('editar_stock');
            
            if (!function_exists('inventario_buscar_producto')) {
                json_fail('Módulo de inventario físico no disponible', 503);
            }
            
            $termino = trim($_GET['q'] ?? '');
            if (!$termino) json_fail('Término de búsqueda requerido', 400);
            
            $categoriaId = (int)($_GET['categoria_id'] ?? 0);
            $categoriaNombre = trim((string)($_GET['categoria_nombre'] ?? ''));
            $productos = inventario_buscar_producto(
                $termino,
                12,
                $categoriaId > 0 ? $categoriaId : null,
                $categoriaNombre !== '' ? $categoriaNombre : null
            );
            
            json_ok(['productos'=>$productos,'data'=>$productos,'success'=>true]);
        }
        
        // ============================================
        // HISTORIAL DE PRECIOS
        // ============================================
        
        case 'precio_historial': {
            require_login_json();
            require_perm_json('editar_productos');
            
            if (!function_exists('precio_get_historial')) {
                json_fail('Módulo de historial de precios no disponible', 503);
            }
            
            $productoId = (int)($_GET['producto_id'] ?? 0);
            $tipo = $_GET['tipo'] ?? null;
            
            if (!$productoId) json_fail('producto_id requerido', 400);
            
            $historial = precio_get_historial($productoId, $tipo);
            
            json_ok(['historial' => $historial]);
        }
        
        case 'precio_actualizar': {
            require_login_json();
            require_perm_json('editar_productos');
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            if (!function_exists('precio_actualizar')) {
                json_fail('Módulo de historial de precios no disponible', 503);
            }
            
            $body = api_read_json();
            $productoId = (int)($body['producto_id'] ?? 0);
            $precio = (float)($body['precio'] ?? 0);
            $tipo = $body['tipo'] ?? 'VENTA';
            $motivo = $body['motivo'] ?? null;
            
            if (!$productoId || $precio <= 0) {
                json_fail('producto_id y precio válido requeridos', 400);
            }
            
            $result = precio_actualizar($productoId, $precio, $tipo, $motivo);
            
            if (!$result) {
                json_fail('Error al actualizar precio', 500);
            }
            
            json_ok(['message' => 'Precio actualizado']);
        }
        
        case 'precio_ajuste_masivo': {
            require_login_json();
            require_perm_json('editar_productos');
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            if (!function_exists('precio_ajuste_masivo_porcentaje')) {
                json_fail('Módulo de historial de precios no disponible', 503);
            }
            
            $body = api_read_json();
            $productoIds = $body['producto_ids'] ?? [];
            $porcentaje = (float)($body['porcentaje'] ?? 0);
            $tipo = $body['tipo'] ?? 'VENTA';
            $redondeo = $body['redondeo'] ?? 'NINGUNO';
            $motivo = $body['motivo'] ?? null;
            
            if (empty($productoIds)) {
                json_fail('producto_ids requerido', 400);
            }
            
            $result = precio_ajuste_masivo_porcentaje($productoIds, $porcentaje, $tipo, $redondeo, $motivo);
            
            json_ok($result);
        }
        
        case 'precio_estadisticas_margenes': {
            require_login_json();
            require_perm_json('ver_reportes');
            
            if (!function_exists('precio_estadisticas_margenes')) {
                json_fail('Módulo de historial de precios no disponible', 503);
            }
            
            $stats = precio_estadisticas_margenes();
            
            json_ok($stats);
        }
        
        case 'precio_margen_bajo': {
            require_login_json();
            require_perm_json('ver_reportes');
            
            if (!function_exists('precio_productos_margen_bajo')) {
                json_fail('Módulo de historial de precios no disponible', 503);
            }
            
            $margenMinimo = (float)($_GET['margen_minimo'] ?? 20);
            $productos = precio_productos_margen_bajo($margenMinimo);
            
            json_ok(['productos' => $productos]);
        }
        
        // ============================================
        // REPOSICIÓN SUGERIDA
        // ============================================
        
        case 'reposicion_stock_bajo': {
            require_login_json();
            if (!$canReposicionView()) {
                json_fail('FORBIDDEN', 403, ['perm' => 'ver_reportes|ver_stock|editar_stock']);
            }
            
            if (!function_exists('reposicion_get_stock_bajo')) {
                json_fail('Módulo de reposición no disponible', 503);
            }
            
            $items = reposicion_get_stock_bajo();
            $conteos = function_exists('reposicion_conteo_estados') ? reposicion_conteo_estados() : [];
            
            json_ok([
                'items' => $items,
                'conteos' => $conteos,
            ]);
        }
        
        case 'reposicion_lista': {
            require_login_json();
            if (!$canReposicionView()) {
                json_fail('FORBIDDEN', 403, ['perm' => 'ver_reportes|ver_stock|editar_stock']);
            }
            
            if (!function_exists('reposicion_generar_lista')) {
                json_fail('Modulo de reposicion no disponible', 503);
            }
            
            $proveedorId = (array_key_exists('proveedor_id', $_GET) && $_GET['proveedor_id'] !== '')
                ? (int)$_GET['proveedor_id']
                : null;
            $items = reposicion_generar_lista($proveedorId);
            
            json_ok(['items' => $items]);
        }
        
        case 'reposicion_por_proveedor': {
            require_login_json();
            if (!$canReposicionView()) {
                json_fail('FORBIDDEN', 403, ['perm' => 'ver_reportes|ver_stock|editar_stock']);
            }
            
            if (!function_exists('reposicion_lista_por_proveedor')) {
                json_fail('Módulo de reposición no disponible', 503);
            }
            
            $data = reposicion_lista_por_proveedor();
            
            json_ok(['proveedores' => $data]);
        }
        
        case 'reposicion_cantidad_optima': {
            require_login_json();
            if (!$canReposicionView()) {
                json_fail('FORBIDDEN', 403, ['perm' => 'ver_reportes|ver_stock|editar_stock']);
            }
            
            if (!function_exists('reposicion_cantidad_optima')) {
                json_fail('Módulo de reposición no disponible', 503);
            }
            
            $productoId = (int)($_GET['producto_id'] ?? 0);
            $dias = (int)($_GET['dias'] ?? 30);
            
            if (!$productoId) json_fail('producto_id requerido', 400);
            
            $data = reposicion_cantidad_optima($productoId, $dias);
            
            json_ok($data);
        }
        
        
        case 'reposicion_config_list': {
            require_login_json();
            if (!$canReposicionConfig()) {
                json_fail('FORBIDDEN', 403, ['perm' => 'gestionar_stock|editar_stock']);
            }

            if (!function_exists('reposicion_listar_configuracion')) {
                json_fail('Modulo de reposicion no disponible', 503);
            }

            $q = trim((string)($_GET['q'] ?? ''));
            $proveedorId = null;
            if (!empty($_GET['sin_proveedor'])) {
                $proveedorId = 0;
            } elseif (array_key_exists('proveedor_id', $_GET) && $_GET['proveedor_id'] !== '') {
                $proveedorId = (int)$_GET['proveedor_id'];
            }

            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(10, (int)($_GET['limit'] ?? 30)));

            $data = reposicion_listar_configuracion($q, $proveedorId, $page, $limit);
            json_ok($data);
        }

        case 'reposicion_config_set': {
            require_login_json();
            if (!$canReposicionConfig()) {
                json_fail('FORBIDDEN', 403, ['perm' => 'gestionar_stock|editar_stock']);
            }
            
            if ($method !== 'POST') json_fail('Método no permitido', 405);
            require_csrf_json();
            
            if (!function_exists('reposicion_set_config')) {
                json_fail('Módulo de reposición no disponible', 503);
            }
            
            $body = api_read_json();
            $productoId = (int)($body['producto_id'] ?? 0);
            
            if (!$productoId) json_fail('producto_id requerido', 400);
            
            $result = reposicion_set_config(
                $productoId,
                isset($body['stock_minimo']) ? (float)$body['stock_minimo'] : null,
                isset($body['stock_maximo']) ? (float)$body['stock_maximo'] : null,
                isset($body['punto_reorden']) ? (float)$body['punto_reorden'] : null,
                isset($body['proveedor_id']) ? (int)$body['proveedor_id'] : null
            );
            
            if (!$result) {
                json_fail('Error al guardar configuración', 500);
            }
            
            json_ok(['message' => 'Configuración guardada']);
        }
        
        case 'reposicion_exportar_csv': {
            require_login_json();
            if (!$canReposicionView()) {
                json_fail('FORBIDDEN', 403, ['perm' => 'ver_reportes|ver_stock|editar_stock']);
            }
            
            if (!function_exists('reposicion_exportar_csv')) {
                json_fail('Modulo de reposicion no disponible', 503);
            }
            
            $proveedorId = (array_key_exists('proveedor_id', $_GET) && $_GET['proveedor_id'] !== '')
                ? (int)$_GET['proveedor_id']
                : null;
            $csv = reposicion_exportar_csv($proveedorId);
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="reposicion_' . date('Ymd') . '.csv"');
            echo "\xEF\xBB\xBF"; // BOM para Excel
            echo $csv;
            exit;
        }
        
        default:
            json_fail('Acción no reconocida: ' . $action, 404);
    }
    
} catch (Throwable $e) {
    flus_log_error('system_api error', [
        'action' => $action,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    json_fail('Error interno', 500);
}
