<?php
// api/ventas_api.php - Endpoints FLUS v4.1 (Corregido)
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/secure_actions_guard.php';
require_once __DIR__ . '/../../src/venta_anulaciones_lib.php';

/* =========================
   Helpers de consistencia
========================= */

/**
 * Condición SQL para ventas activas.
 * Unifica el criterio: solo ANULADA queda afuera.
 */
function whereEmitida(string $alias = 'v'): string {
  return "({$alias}.estado IS NULL OR {$alias}.estado <> 'ANULADA')";
}

/**
 * Detectar si existe la tabla venta_pagos
 */
function hasVentaPagos(PDO $pdo): bool {
  static $cache = null;
  if ($cache === null) {
    if (function_exists('flus_table_exists')) {
      $cache = (bool)flus_table_exists($pdo, 'venta_pagos');
    } elseif (function_exists('has_table')) {
      $cache = (bool)has_table($pdo, 'venta_pagos');
    } else {
      $cache = false;
    }
  }
  return $cache;
}

function flus_ventas_api_anulaciones_join(PDO $pdo, string $ventaAlias = 'v', string $joinAlias = 'vaa'): string {
  return flus_venta_anulaciones_totales_join_sql($pdo, $ventaAlias, $joinAlias);
}

function flus_ventas_api_monto_anulado_expr(string $anulacionesJoin): string {
  return $anulacionesJoin !== '' ? 'COALESCE(vaa.monto_anulado_total, 0)' : '0';
}

function flus_ventas_api_importe_vigente_expr(string $anulacionesJoin): string {
  return flus_venta_importe_vigente_expr_sql('v.total', flus_ventas_api_monto_anulado_expr($anulacionesJoin));
}

function flus_ventas_api_ratio_vigente_expr(string $anulacionesJoin): string {
  return $anulacionesJoin !== ''
    ? flus_venta_ratio_vigente_expr_sql('v.total', flus_ventas_api_monto_anulado_expr($anulacionesJoin))
    : '1';
}
require_once __DIR__ . '/kpis_categoria_helper.php';

$categoria  = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? trim((string)$_GET['categoria']) : null;
$prodCatCol = flus_first_existing_column($pdo, 'productos', ['categoria','rubro','familia']);
$catFilter  = kpis_categoria_condition($pdo, $categoria, $prodCatCol);

/**
 * Generar token seguro para tickets públicos
 */
/**
 * Obtener APP_SECRET de forma segura (obligatorio)
 */
function getAppSecret(): string {
  if (!defined('APP_SECRET')) {
    throw new RuntimeException('APP_SECRET no está definido. Configurá un secreto fuerte para habilitar tickets públicos.');
  }
  $secret = (string)APP_SECRET;
  // Bloquear secretos débiles o el placeholder conocido
  if (strlen($secret) < 16 || $secret === 'flus-default-secret-change-me' || strpos($secret, 'change-me') !== false) {
    throw new RuntimeException('APP_SECRET es débil o es un placeholder. Configurá un secreto fuerte (>= 16 chars) para habilitar tickets públicos.');
  }
  return $secret;
}

/**
 * TTL del token de ticket (segundos)
 * Default: 7 días
 */
function ticketTokenTtlSeconds(): int {
  if (defined('TICKET_TOKEN_TTL_SECONDS')) {
    $v = (int)TICKET_TOKEN_TTL_SECONDS;
    return $v > 0 ? $v : 7 * 24 * 60 * 60;
  }
  return 7 * 24 * 60 * 60;
}

/**
 * Generar token seguro para tickets públicos (con timestamp)
 */
function generateTicketToken(int $ventaId, int $ts, string $secret = ''): string {
  if ($ts <= 0) {
    throw new InvalidArgumentException('Timestamp inválido para token.');
  }
  if (!$secret) {
    $secret = getAppSecret();
  }
  return substr(hash_hmac('sha256', "ticket-{$ventaId}-{$ts}", $secret), 0, 32);
}

/**
 * Validar token de ticket (con timestamp + TTL)
 */
function validateTicketToken(int $ventaId, int $ts, string $token): bool {
  if ($ventaId <= 0 || $ts <= 0 || $token === '') return false;

  $now = time();
  // No permitir timestamps en el futuro (con tolerancia de 5 minutos)
  if ($ts > ($now + 300)) return false;

  $ttl = ticketTokenTtlSeconds();
  if (($now - $ts) > $ttl) return false;

  try {
    // Compat: aceptar tokens viejos (16 hex) y nuevos (32 hex)
    $expected32 = generateTicketToken($ventaId, $ts);
    $expected16 = substr($expected32, 0, 16);
  } catch (Throwable $e) {
    return false;
  }
  return hash_equals($expected32, $token) || hash_equals($expected16, $token);
}

function flus_public_base_url(PDO $pdo): string {
  $candidates = [];

  foreach (['APP_URL', 'PUBLIC_BASE_URL'] as $const) {
    if (defined($const)) {
      $candidates[] = (string)constant($const);
    }
  }

  if (function_exists('config_get')) {
    foreach (['public_base_url', 'app_base_url', 'site_url'] as $key) {
      $value = config_get($pdo, $key, '');
      if (is_string($value) && trim($value) !== '') {
        $candidates[] = $value;
      }
    }
  }

  foreach ($candidates as $candidate) {
    $candidate = trim((string)$candidate);
    if ($candidate === '') continue;

    $parts = parse_url($candidate);
    if (!is_array($parts)) continue;

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') continue;

    return rtrim($candidate, '/');
  }

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = trim((string)($_SERVER['SERVER_NAME'] ?? $_SERVER['SERVER_ADDR'] ?? 'localhost'));
  if (!preg_match('/^(localhost|[a-z0-9.-]+|\[[0-9a-f:]+\])$/i', $host)) {
    $host = 'localhost';
  }

  $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
  $portPart = '';
  if ($port > 0 && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
    $portPart = ':' . $port;
  }

  $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/ventas_api.php'))), '/');
  if (str_ends_with($basePath, '/api')) {
    $basePath = substr($basePath, 0, -4);
  }

  return $scheme . '://' . $host . $portPart . $basePath;
}

function flus_ticket_public_url(PDO $pdo, int $ventaId, int $ts, string $token): string {
  return flus_public_base_url($pdo) . '/ticket_publico.php?id=' . $ventaId . '&ts=' . $ts . '&token=' . urlencode($token);
}

/**
 * Sanitizar número de teléfono para WhatsApp
 */
function sanitizePhone(string $phone): string {
  // Remover todo excepto dígitos
  $clean = preg_replace('/[^0-9]/', '', $phone);
  
  // Si empieza con 0, removerlo (código local)
  if (strlen($clean) > 10 && $clean[0] === '0') {
    $clean = substr($clean, 1);
  }
  
  // Si no tiene código de país, asumir Argentina (+54)
  if (strlen($clean) === 10) {
    $clean = '54' . $clean;
  }
  
  return $clean;
}

function flus_mail_header_safe_value(string $value): string {
  $clean = preg_replace('/[\x00-\x1F\x7F\r\n]+/u', ' ', $value) ?? '';
  $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
  return $clean;
}

/**
 * Obtener config de email desde app_config o usar default
 */
function getEmailConfig(PDO $pdo): array {
  $defaults = [
    'from_email' => 'noreply@localhost',
    'from_name' => 'FLUS POS',
    'business_name' => 'Mi Negocio'
  ];
  
  try {
    $st = $pdo->query("SELECT k, v FROM app_config WHERE k IN ('business_email', 'business_name')");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      if ($row['k'] === 'business_email' && $row['v']) {
        $candidate = trim((string)$row['v']);
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
          $defaults['from_email'] = $candidate;
        }
      }
      if ($row['k'] === 'business_name' && $row['v']) {
        $safeName = flus_mail_header_safe_value((string)$row['v']);
        if ($safeName !== '') {
          $defaults['from_name'] = $safeName;
          $defaults['business_name'] = $safeName;
        }
      }
    }
  } catch (Exception $e) {}
  
  return $defaults;
}

function flus_ventas_api_policies(): array {
  return [
    'buscar_clientes' => [
      'any_permissions' => ['ver_clientes', 'realizar_ventas', 'ver_reportes'],
    ],
    'venta_preview' => [
      'any_permissions' => ['ver_reportes', 'realizar_ventas'],
    ],
    'stats_ventas_por_dia' => [
      'permissions' => ['ver_reportes'],
    ],
    'stats_ventas_por_medio' => [
      'permissions' => ['ver_reportes'],
    ],
    'stats_top_productos' => [
      'permissions' => ['ver_reportes'],
    ],
    'stats_comparativa' => [
      'permissions' => ['ver_reportes'],
    ],
    'stats_ventas_heatmap' => [
      'permissions' => ['ver_reportes'],
    ],
    'get_ticket_link' => [
      'permissions' => ['realizar_ventas'],
    ],
    'send_ticket_whatsapp' => [
      'methods' => ['POST'],
      'permissions' => ['realizar_ventas'],
    ],
    'send_ticket_email' => [
      'methods' => ['POST'],
      'permissions' => ['realizar_ventas'],
    ],
  ];
}

function flus_ventas_api_enforce_guard(string $action): void {
  $policies = flus_ventas_api_policies();
  $policy = $policies[$action] ?? null;
  if (!is_array($policy)) {
    return;
  }

  require_login_json();

  if (!empty($policy['methods']) && is_array($policy['methods'])) {
    require_method_json($policy['methods']);
  }

  if (!empty($policy['permissions']) && is_array($policy['permissions'])) {
    foreach ($policy['permissions'] as $permission) {
      require_perm_json((string)$permission);
    }
  }

  if (!empty($policy['any_permissions']) && is_array($policy['any_permissions'])) {
    require_any_perm_json($policy['any_permissions']);
  }
}

/* =========================
   Router
========================= */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
flus_ventas_api_enforce_guard((string)$action);

try {
  switch ($action) {

    // =============================================
    // Búsqueda de clientes con autocomplete
    // =============================================
    case 'buscar_clientes':
      require_login();
      
      $q = trim((string)($_GET['q'] ?? ''));
      if (strlen($q) < 2) {
        json_response(['success' => true, 'clientes' => []]);
      }

      // Escapar caracteres especiales de LIKE
      $qSafe = str_replace(['%', '_'], ['\%', '\_'], $q);

      $stmt = $pdo->prepare("
        SELECT id, nombre, documento, email, telefono
        FROM clientes
        WHERE 
          nombre LIKE :q ESCAPE '\\'
          OR documento LIKE :q ESCAPE '\\'
          OR email LIKE :q ESCAPE '\\'
        ORDER BY nombre ASC
        LIMIT 20
      ");
      
      $stmt->execute([':q' => "%{$qSafe}%"]);
      $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

      json_response([
        'success' => true,
        'clientes' => $clientes,
      ]);
      break;

    // =============================================
    // Preview rápido de venta
    // =============================================
    case 'venta_preview':
      require_login();

      $id = (int)($_GET['id'] ?? 0);
      if ($id <= 0) {
        success_fail('ID inválido', 400);
      }

      // ====== Robustez de esquema (instalaciones viejas) ======
      // Columnas posibles en ventas
      $vTotalCol  = function_exists('flus_first_existing_column') ? (flus_first_existing_column($pdo, 'ventas', ['total','total_final','monto_total','importe_total']) ?? 'total') : 'total';
      $vFechaCol  = function_exists('flus_first_existing_column') ? (flus_first_existing_column($pdo, 'ventas', ['fecha','fecha_hora','created_at','fecha_venta']) ?? 'fecha') : 'fecha';
      $vEstadoCol = function_exists('flus_first_existing_column') ? (flus_first_existing_column($pdo, 'ventas', ['estado','status']) ?? 'estado') : 'estado';
      $vMedioCol  = function_exists('flus_first_existing_column') ? (flus_first_existing_column($pdo, 'ventas', ['medio_pago','metodo_pago','forma_pago','pago']) ?? 'medio_pago') : 'medio_pago';
      $vCliIdCol  = function_exists('flus_first_existing_column') ? (flus_first_existing_column($pdo, 'ventas', ['cliente_id','id_cliente','clienteID']) ?: null) : null;

      // Helper local: columnas de una tabla
      $colsOf = function (string $table) use ($pdo): array {
        try {
          if (function_exists('flus_table_columns')) {
            $cols = flus_table_columns($pdo, $table);
            return array_map('strval', $cols ?: []);
          }
          return [];
        } catch (Throwable $e) { return []; }
      };

      $tableExists = function (string $table) use ($pdo): bool {
        try {
          if (function_exists('flus_table_exists')) return (bool)flus_table_exists($pdo, $table);
          if (function_exists('has_table')) return (bool)has_table($pdo, $table);
          return false;
        } catch (Throwable $e) { return false; }
      };

      // Venta (sin JOIN para no romper si falta clientes)
      $stmt = $pdo->prepare("SELECT v.* FROM ventas v WHERE v.id = ? LIMIT 1");
      $stmt->execute([$id]);
      $venta = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$venta) {
        success_fail('Venta no encontrada', 404);
      }

      $montoAnulado = 0.0;
      $anulacionesCount = 0;
      try {
        if ($tableExists('venta_anulaciones')) {
          $anulacionesWhere = flus_venta_anulaciones_confirmadas_where_sql($pdo, 'va');
          $stAn = $pdo->prepare("
            SELECT COALESCE(SUM(monto_total), 0) AS monto_anulado, COUNT(*) AS anulaciones_count
            FROM venta_anulaciones va
            WHERE va.venta_id = ? AND {$anulacionesWhere}
          ");
          $stAn->execute([$id]);
          $anData = $stAn->fetch(PDO::FETCH_ASSOC) ?: [];
          $montoAnulado = round((float)($anData['monto_anulado'] ?? 0), 2);
          $anulacionesCount = (int)($anData['anulaciones_count'] ?? 0);
        }
      } catch (Throwable $e) {
        $montoAnulado = 0.0;
        $anulacionesCount = 0;
      }

      // Cliente (opcional)
      $clienteNombre = 'Consumidor Final';
      try {
        $cid = ($vCliIdCol && isset($venta[$vCliIdCol])) ? (int)$venta[$vCliIdCol] : 0;
        if ($cid > 0 && $tableExists('clientes')) {
          $cNameCol = function_exists('flus_first_existing_column')
            ? (flus_first_existing_column($pdo, 'clientes', ['nombre','razon_social','nombre_completo','apellido_nombre']) ?: null)
            : 'nombre';
          if ($cNameCol) {
            $stc = $pdo->prepare("SELECT `{$cNameCol}` FROM clientes WHERE id = ? LIMIT 1");
            $stc->execute([$cid]);
            $n = $stc->fetchColumn();
            if (is_string($n) && trim($n) !== '') $clienteNombre = $n;
          }
        }
      } catch (Throwable $e) {
        // no romper preview por cliente
      }

      // Items (tolerante a columnas)
      $items = [];
      try {
        $viCols = $colsOf('venta_items');
        $pick = function(array $cands, ?string $fallback = null) use ($viCols): ?string {
          foreach ($cands as $c) if (in_array($c, $viCols, true)) return $c;
          return $fallback;
        };

        $viVentaCol = $pick(['venta_id','id_venta','ventaId'], 'venta_id');
        $viProdCol  = $pick(['producto_id','id_producto','productoId'], null);
        $viQtyCol   = $pick(['cantidad','qty','cant','unidades'], 'cantidad');
        $viPriceCol = $pick(['precio','precio_unitario','precioUnitario','unit_price'], 'precio');
        $viSubCol   = $pick(['subtotal','importe','total','monto'], 'subtotal');
        $viNameCol  = $pick(['nombre','producto_nombre','descripcion'], null);
        $viIdCol    = $pick(['id','item_id','id_item'], null);

        $orderSql = $viIdCol ? " ORDER BY vi.`{$viIdCol}` ASC" : "";

        if ($viProdCol && $tableExists('productos')) {
          $nameExpr = $viNameCol ? "COALESCE(p.nombre, vi.`{$viNameCol}`)" : "p.nombre";
          $sql = "
            SELECT
              vi.`{$viQtyCol}` AS cantidad,
              vi.`{$viPriceCol}` AS precio,
              vi.`{$viSubCol}` AS subtotal,
              {$nameExpr} AS nombre
            FROM venta_items vi
            LEFT JOIN productos p ON p.id = vi.`{$viProdCol}`
            WHERE vi.`{$viVentaCol}` = ?
            {$orderSql}
          ";
          $sti = $pdo->prepare($sql);
          $sti->execute([$id]);
          $items = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
          // Sin join a productos
          $nameExpr = $viNameCol ? "vi.`{$viNameCol}`" : "''";
          $sql = "
            SELECT
              vi.`{$viQtyCol}` AS cantidad,
              vi.`{$viPriceCol}` AS precio,
              vi.`{$viSubCol}` AS subtotal,
              {$nameExpr} AS nombre
            FROM venta_items vi
            WHERE vi.`{$viVentaCol}` = ?
            {$orderSql}
          ";
          $sti = $pdo->prepare($sql);
          $sti->execute([$id]);
          $items = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
      } catch (Throwable $e) {
        $items = []; // no romper preview por items
      }

      // Medio de pago real (si hay venta_pagos)
      $medioPago = $venta[$vMedioCol] ?? ($venta['medio_pago'] ?? 'N/A');
      try {
        if (hasVentaPagos($pdo)) {
          // detectar columna posible en venta_pagos
          $vpCols = $colsOf('venta_pagos');
          $mpCol = null;
          foreach (['medio_pago','metodo_pago','forma_pago'] as $c) {
            if (in_array($c, $vpCols, true)) { $mpCol = $c; break; }
          }
          if ($mpCol) {
            $stPagos = $pdo->prepare("
              SELECT GROUP_CONCAT(DISTINCT UPPER(`{$mpCol}`) SEPARATOR '+') as medios
              FROM venta_pagos WHERE venta_id = ?
            ");
            $stPagos->execute([$id]);
            $medios = $stPagos->fetchColumn();
            if ($medios) $medioPago = $medios;
          }
        }
      } catch (Throwable $e) {
        // no romper preview por pagos
      }

      // Campos principales con fallback
      $fecha  = $venta[$vFechaCol]  ?? ($venta['fecha'] ?? '');
      $total  = isset($venta[$vTotalCol]) ? (float)$venta[$vTotalCol] : (float)($venta['total'] ?? 0);
      $netoVigente = max(0, round($total - $montoAnulado, 2));
      $estado = function_exists('flus_normalize_sale_status')
        ? flus_normalize_sale_status($venta[$vEstadoCol] ?? ($venta['estado'] ?? null))
        : (string)($venta[$vEstadoCol] ?? ($venta['estado'] ?? 'EMITIDA'));

      json_response([
        'success' => true,
        'venta' => [
          'id' => (int)$venta['id'],
          'fecha' => $fecha,
          'cliente' => $clienteNombre,
          'total' => $total,
          'monto_anulado' => $montoAnulado,
          'neto_vigente' => $netoVigente,
          'anulaciones_count' => $anulacionesCount,
          'medio_pago' => $medioPago,
          'estado' => $estado,
          'items' => $items,
        ],
      ]);
      break;

    // =============================================
    // Estadísticas: Ventas por día (últimos 30 días)
    // =============================================
    case 'stats_ventas_por_dia':
      require_login();
      $anulacionesJoin = flus_ventas_api_anulaciones_join($pdo, 'v', 'vaa');
      $importeVigenteExpr = flus_ventas_api_importe_vigente_expr($anulacionesJoin);
      $whereEmitida = whereEmitida('');
      // Nota: sin alias porque la tabla es directa
      $stmt = $pdo->query("
        SELECT 
          DATE(v.fecha) AS fecha,
          COUNT(*) AS cantidad,
          SUM($importeVigenteExpr) AS total
        FROM ventas v
        $anulacionesJoin
        WHERE 
          v.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND " . whereEmitida('v') . "
        GROUP BY DATE(v.fecha)
        ORDER BY fecha ASC
      ");

      $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

      json_response([
        'success' => true,
        'stats' => $stats,
      ]);
      break;

    // =============================================
    // Estadísticas: Ventas por medio de pago
    // (Adaptado para venta_pagos si existe)
    // =============================================
    case 'stats_ventas_por_medio':
      require_login();
      
      $desde = $_GET['desde'] ?? null;
      $hasta = $_GET['hasta'] ?? null;
      $anulacionesJoin = flus_ventas_api_anulaciones_join($pdo, 'v', 'vaa');
      $importeVigenteExpr = flus_ventas_api_importe_vigente_expr($anulacionesJoin);
      $ratioVigenteExpr = flus_ventas_api_ratio_vigente_expr($anulacionesJoin);

      $params = [];
      $whereParts = [whereEmitida('v')];

      if ($desde) {
        $whereParts[] = "v.fecha >= :desde";
        $params[':desde'] = $desde . ' 00:00:00';
      }

      if ($hasta) {
        $whereParts[] = "v.fecha <= :hasta";
        $params[':hasta'] = $hasta . ' 23:59:59';
      }

      $whereSQL = implode(' AND ', $whereParts);

      // Usar venta_pagos si existe (para pagos mixtos)
      if (hasVentaPagos($pdo)) {
        $stmt = $pdo->prepare("
          SELECT 
            UPPER(vp.medio_pago) AS medio_pago,
            COUNT(DISTINCT vp.venta_id) AS cantidad,
            SUM(vp.monto * $ratioVigenteExpr) AS total
          FROM venta_pagos vp
          JOIN ventas v ON v.id = vp.venta_id
          $anulacionesJoin
          WHERE {$whereSQL}
          GROUP BY UPPER(vp.medio_pago)
          ORDER BY total DESC
        ");
      } else {
        $stmt = $pdo->prepare("
          SELECT 
            UPPER(v.medio_pago) AS medio_pago,
            COUNT(*) AS cantidad,
            SUM($importeVigenteExpr) AS total
          FROM ventas v
          $anulacionesJoin
          WHERE {$whereSQL}
          GROUP BY UPPER(v.medio_pago)
          ORDER BY total DESC
        ");
      }

      $stmt->execute($params);
      $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

      json_response([
        'success' => true,
        'stats' => $stats,
      ]);
      break;

    // =============================================
    // Estadísticas: Top productos vendidos
    // =============================================
    case 'stats_top_productos':
      require_login();
      
      $limit = min(50, max(5, (int)($_GET['limit'] ?? 10)));
      $desde = $_GET['desde'] ?? null;
      $hasta = $_GET['hasta'] ?? null;
      $anulacionesItemsJoin = flus_venta_items_anulados_join_sql($pdo, 'vi', 'vaix');
      $cantidadAnuladaExpr = $anulacionesItemsJoin !== '' ? 'COALESCE(vaix.cantidad_anulada_total, 0)' : '0';
      $cantidadVigenteExpr = flus_venta_cantidad_vigente_expr_sql('vi.cantidad', $cantidadAnuladaExpr);
      $subtotalVigenteExpr = flus_venta_item_subtotal_vigente_expr_sql('vi.subtotal', 'vi.cantidad', $cantidadAnuladaExpr);

      $whereParts = [whereEmitida('v')];
      $params = [];

      if ($desde) {
        $whereParts[] = "v.fecha >= :desde";
        $params[':desde'] = $desde . ' 00:00:00';
      }

      if ($hasta) {
        $whereParts[] = "v.fecha <= :hasta";
        $params[':hasta'] = $hasta . ' 23:59:59';
      }

      $whereSQL = implode(' AND ', $whereParts);

      $stmt = $pdo->prepare("
        SELECT 
          p.nombre,
          SUM($cantidadVigenteExpr) AS unidades,
          SUM($subtotalVigenteExpr) AS total,
          COUNT(DISTINCT v.id) AS num_ventas
        FROM venta_items vi
        JOIN productos p ON p.id = vi.producto_id
        JOIN ventas v ON v.id = vi.venta_id
        $anulacionesItemsJoin
        WHERE {$whereSQL}
        GROUP BY vi.producto_id
        ORDER BY total DESC
        LIMIT :limit
      ");

      foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
      }
      $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
      $stmt->execute();

      $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

      json_response([
        'success' => true,
        'productos' => $productos,
      ]);
      break;

    // =============================================
    // Estadísticas: Comparativa con período anterior
    // (Corregido: diff inclusivo)
    // =============================================
    case 'stats_comparativa':
      require_login();
      
      $desde = $_GET['desde'] ?? null;
      $hasta = $_GET['hasta'] ?? null;
      $anulacionesJoin = flus_ventas_api_anulaciones_join($pdo, 'v', 'vaa');
      $importeVigenteExpr = flus_ventas_api_importe_vigente_expr($anulacionesJoin);

      if (!$desde || !$hasta) {
        success_fail('Faltan parámetros desde/hasta', 400);
      }

      $desde_dt = new DateTime($desde);
      $hasta_dt = new DateTime($hasta);
      
      // FIX: Incluir el día final en el cálculo (+1 día)
      $diff = $desde_dt->diff($hasta_dt)->days + 1;

      // Período anterior: misma cantidad de días hacia atrás
      $desde_prev = (clone $desde_dt)->modify("-{$diff} days")->format('Y-m-d');
      $hasta_prev = (clone $desde_dt)->modify('-1 day')->format('Y-m-d');

      // Período actual
      $stmt = $pdo->prepare("
        SELECT 
          COUNT(*) AS cantidad,
          COALESCE(SUM($importeVigenteExpr), 0) AS total,
          COALESCE(AVG($importeVigenteExpr), 0) AS promedio
        FROM ventas v
        $anulacionesJoin
        WHERE 
          " . whereEmitida('v') . "
          AND v.fecha >= :desde
          AND v.fecha <= :hasta
      ");
      $stmt->execute([
        ':desde' => $desde . ' 00:00:00',
        ':hasta' => $hasta . ' 23:59:59',
      ]);
      $actual = $stmt->fetch(PDO::FETCH_ASSOC);

      // Período anterior
      $stmt->execute([
        ':desde' => $desde_prev . ' 00:00:00',
        ':hasta' => $hasta_prev . ' 23:59:59',
      ]);
      $anterior = $stmt->fetch(PDO::FETCH_ASSOC);

      // Calcular variaciones
      $variacion_cantidad = 0;
      $variacion_total = 0;
      
      if ((int)$anterior['cantidad'] > 0) {
        $variacion_cantidad = round((((int)$actual['cantidad'] - (int)$anterior['cantidad']) / (int)$anterior['cantidad']) * 100, 1);
      }
      if ((float)$anterior['total'] > 0) {
        $variacion_total = round((((float)$actual['total'] - (float)$anterior['total']) / (float)$anterior['total']) * 100, 1);
      }

      json_response([
        'success' => true,
        'stats' => [
          'actual' => $actual,
          'anterior' => $anterior,
          'variacion' => [
            'cantidad' => $variacion_cantidad,
            'total' => $variacion_total,
          ],
          'periodos' => [
            'actual' => ['desde' => $desde, 'hasta' => $hasta, 'dias' => $diff],
            'anterior' => ['desde' => $desde_prev, 'hasta' => $hasta_prev, 'dias' => $diff],
          ]
        ],
      ]);
      break;

    // =============================================
    // Heatmap: Ventas por día de semana y hora
    // =============================================
    case 'stats_ventas_heatmap':
      require_login();
      
      $stmt = $pdo->query("
        SELECT 
          DAYOFWEEK(fecha) - 1 AS dia_semana,
          HOUR(fecha) AS hora,
          COUNT(*) AS cantidad
        FROM ventas
        WHERE 
          (estado IS NULL OR estado <> 'ANULADA')
          AND fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        GROUP BY dia_semana, hora
        ORDER BY dia_semana, hora
      ");

      $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Crear matriz 7x24
      $heatmap = array_fill(0, 7, array_fill(0, 24, 0));

      foreach ($data as $row) {
        $dia = (int)$row['dia_semana'];
        $hora = (int)$row['hora'];
        $cantidad = (int)$row['cantidad'];

        if ($dia >= 0 && $dia < 7 && $hora >= 0 && $hora < 24) {
          $heatmap[$dia][$hora] = $cantidad;
        }
      }

      json_response([
        'success' => true,
        'heatmap' => $heatmap,
      ]);
      break;

    // =============================================
    // Generar link seguro para ticket público
    // =============================================
    case 'get_ticket_link':
      require_login();
      
      $venta_id = (int)($_GET['venta_id'] ?? 0);
      if ($venta_id <= 0) {
        success_fail('ID inválido', 400);
      }

      // Verificar que la venta existe
      $st = $pdo->prepare("SELECT id FROM ventas WHERE id = ? LIMIT 1");
      $st->execute([$venta_id]);
      if (!$st->fetchColumn()) {
        success_fail('Venta no encontrada', 404);
      }

      // Generar token seguro
      $ts = time();
      $token = generateTicketToken($venta_id, $ts);
      
      // Construir URL segura
      $ticket_url = flus_ticket_public_url($pdo, $venta_id, $ts, $token);

      json_response([
        'success' => true,
        'url' => $ticket_url,
        'token' => $token,
        'ts' => $ts,
      ]);
      break;

    // =============================================
    // Enviar ticket por WhatsApp (mejorado)
    // =============================================
    case 'send_ticket_whatsapp':
      require_login();
      require_method('POST');
      
      $input = json_decode(file_get_contents('php://input'), true);
      $venta_id = (int)($input['venta_id'] ?? 0);
      $phone = trim((string)($input['phone'] ?? ''));

      if ($venta_id <= 0) {
        success_fail('ID de venta inválido', 400);
      }

      if ($phone === '') {
        success_fail('Número de teléfono requerido', 400);
      }

      // Verificar que la venta existe
      $st = $pdo->prepare("SELECT id, total FROM ventas WHERE id = ? LIMIT 1");
      $st->execute([$venta_id]);
      $venta = $st->fetch(PDO::FETCH_ASSOC);
      
      if (!$venta) {
        success_fail('Venta no encontrada', 404);
      }

      // Sanitizar teléfono
      $phoneSanitized = sanitizePhone($phone);
      
      if (strlen($phoneSanitized) < 10) {
        success_fail('Número de teléfono inválido', 400);
      }

      // Generar link seguro con token
      $ts = time();
      $token = generateTicketToken($venta_id, $ts);
      $ticket_url = flus_ticket_public_url($pdo, $venta_id, $ts, $token);

      // Obtener nombre del negocio
      $emailConfig = getEmailConfig($pdo);
      $businessName = $emailConfig['business_name'];

      // Texto del mensaje
      $message = "🧾 *{$businessName}*\n\nTu ticket de compra #" . str_pad((string)$venta_id, 6, '0', STR_PAD_LEFT) . "\n";
      $message .= "Total: $" . number_format((float)$venta['total'], 2, ',', '.') . "\n\n";
      $message .= "📄 Ver ticket: {$ticket_url}";

      // Construir URL de WhatsApp
      $whatsapp_url = "https://wa.me/{$phoneSanitized}?text=" . urlencode($message);

      json_response([
        'success' => true,
        'url' => $whatsapp_url,
        'phone_sanitized' => $phoneSanitized,
        'message' => 'Abrí el link para enviar por WhatsApp',
      ]);
      break;

    // =============================================
    // Enviar ticket por Email (mejorado)
    // =============================================
    case 'send_ticket_email':
      require_login();
      require_method('POST');
      
      $input = json_decode(file_get_contents('php://input'), true);
      $venta_id = (int)($input['venta_id'] ?? 0);
      $email = trim((string)($input['email'] ?? ''));

      if ($venta_id <= 0) {
        success_fail('ID de venta inválido', 400);
      }

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        success_fail('Email inválido', 400);
      }

      // Obtener datos de la venta
      $stmt = $pdo->prepare("
        SELECT v.*, COALESCE(c.nombre, 'Consumidor Final') as cliente_nombre
        FROM ventas v
        LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.id = ? LIMIT 1
      ");
      $stmt->execute([$venta_id]);
      $venta = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$venta) {
        success_fail('Venta no encontrada', 404);
      }

      // Obtener items
      $stItems = $pdo->prepare("
        SELECT vi.cantidad, vi.precio, vi.subtotal, p.nombre
        FROM venta_items vi
        JOIN productos p ON p.id = vi.producto_id
        WHERE vi.venta_id = ?
      ");
      $stItems->execute([$venta_id]);
      $items = $stItems->fetchAll(PDO::FETCH_ASSOC);

      // Generar link seguro con token
      $ts = time();
      $token = generateTicketToken($venta_id, $ts);
      $ticket_url = flus_ticket_public_url($pdo, $venta_id, $ts, $token);

      // Config de email desde BD
      $emailConfig = getEmailConfig($pdo);

      // Construir HTML del email
      $ticketNum = str_pad((string)$venta_id, 6, '0', STR_PAD_LEFT);
      $fecha = date('d/m/Y H:i', strtotime($venta['fecha']));
      $total = '$' . number_format((float)$venta['total'], 2, ',', '.');

      $itemsHtml = '';
      foreach ($items as $item) {
        $cant = number_format((float)$item['cantidad'], $item['cantidad'] == (int)$item['cantidad'] ? 0 : 3);
        $sub = '$' . number_format((float)$item['subtotal'], 2, ',', '.');
        $itemsHtml .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;'>{$cant}x " . htmlspecialchars($item['nombre']) . "</td><td style='padding:8px;border-bottom:1px solid #eee;text-align:right;'>{$sub}</td></tr>";
      }

      $subject = "Tu ticket de compra #{$ticketNum} - {$emailConfig['business_name']}";
      
      $message = "
<!DOCTYPE html>
<html>
<head><meta charset='utf-8'></head>
<body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;'>
  <div style='background:#0ea5e9;color:white;padding:20px;text-align:center;border-radius:8px 8px 0 0;'>
    <h1 style='margin:0;font-size:24px;'>" . htmlspecialchars($emailConfig['business_name']) . "</h1>
    <p style='margin:10px 0 0;opacity:0.9;'>Ticket #{$ticketNum}</p>
  </div>
  
  <div style='background:#f8fafc;padding:20px;border:1px solid #e2e8f0;'>
    <p><strong>Fecha:</strong> {$fecha}</p>
    <p><strong>Cliente:</strong> " . htmlspecialchars($venta['cliente_nombre']) . "</p>
    
    <table style='width:100%;border-collapse:collapse;margin:20px 0;'>
      <thead>
        <tr style='background:#e2e8f0;'>
          <th style='padding:10px;text-align:left;'>Producto</th>
          <th style='padding:10px;text-align:right;'>Subtotal</th>
        </tr>
      </thead>
      <tbody>
        {$itemsHtml}
      </tbody>
      <tfoot>
        <tr style='background:#0ea5e9;color:white;'>
          <td style='padding:12px;font-weight:bold;'>TOTAL</td>
          <td style='padding:12px;text-align:right;font-weight:bold;font-size:18px;'>{$total}</td>
        </tr>
      </tfoot>
    </table>
    
    <div style='text-align:center;margin-top:20px;'>
      <a href='{$ticket_url}' style='display:inline-block;background:#0ea5e9;color:white;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;'>Ver Ticket Completo</a>
    </div>
  </div>
  
  <div style='text-align:center;padding:15px;color:#64748b;font-size:12px;'>
    <p>Gracias por tu compra</p>
    <p style='margin:5px 0;'>" . htmlspecialchars($emailConfig['business_name']) . "</p>
  </div>
</body>
</html>";

      $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        'From: ' . $emailConfig['from_name'] . ' <' . $emailConfig['from_email'] . '>',
        'Reply-To: ' . $emailConfig['from_email'],
        'X-Mailer: FLUS-POS'
      ];

      $sent = @mail($email, $subject, $message, implode("\r\n", $headers));
      
      if ($sent) {
        json_response([
          'success' => true,
          'message' => 'Ticket enviado correctamente a ' . $email,
        ]);
      } else {
        // Si mail() falla, dar info útil
        json_response([
          'success' => false,
          'error' => 'Error al enviar email. Verificá la configuración del servidor de correo.',
          'fallback_url' => $ticket_url,
        ]);
      }
      break;

    // =============================================
    // Validar token de ticket (para ticket_publico.php)
    // =============================================
    case 'validate_ticket_token':
      // No requiere login - es para acceso público
      $venta_id = (int)($_GET['venta_id'] ?? 0);
      $token = trim((string)($_GET['token'] ?? ''));
      $ts = (int)($_GET['ts'] ?? 0);

      if ($venta_id <= 0 || $ts <= 0 || $token === '') {
        json_response(['success' => false, 'valid' => false]);
      }

      $valid = validateTicketToken($venta_id, $ts, $token);

      json_response([
        'success' => true,
        'valid' => $valid,
      ]);
      break;

    default:
      success_fail('Acción no válida', 400);
  }

} catch (Exception $e) {
  error_log("API Error: " . $e->getMessage());
  success_fail('Error interno del servidor', 500);
}
