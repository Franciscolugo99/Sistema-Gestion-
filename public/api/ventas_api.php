<?php
// api/ventas_api.php - Endpoints FLUS v4.1 (Corregido)
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/secure_actions_guard.php';

/* =========================
   Helpers de consistencia
========================= */

/**
 * Condición SQL para ventas emitidas (NO anuladas)
 * Unifica el criterio: NULL o 'EMITIDA' = emitida
 */
function whereEmitida(string $alias = 'v'): string {
  return "({$alias}.estado IS NULL OR {$alias}.estado = 'EMITIDA')";
}

/**
 * Detectar si existe la tabla venta_pagos
 */
function hasVentaPagos(PDO $pdo): bool {
  static $cache = null;
  if ($cache === null) {
    $st = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_pagos' LIMIT 1");
    $cache = (bool)$st->fetchColumn();
  }
  return $cache;
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
        $defaults['from_email'] = $row['v'];
      }
      if ($row['k'] === 'business_name' && $row['v']) {
        $defaults['from_name'] = $row['v'];
        $defaults['business_name'] = $row['v'];
      }
    }
  } catch (Exception $e) {}
  
  return $defaults;
}

/* =========================
   Router
========================= */
$action = $_GET['action'] ?? $_POST['action'] ?? '';

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

      // Venta
      $stmt = $pdo->prepare("
        SELECT 
          v.*,
          COALESCE(c.nombre, 'Consumidor Final') AS cliente_nombre
        FROM ventas v
        LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.id = ?
        LIMIT 1
      ");
      $stmt->execute([$id]);
      $venta = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$venta) {
        success_fail('Venta no encontrada', 404);
      }

      // Items
      $stmt = $pdo->prepare("
        SELECT 
          vi.cantidad,
          vi.precio,
          vi.subtotal,
          p.nombre
        FROM venta_items vi
        JOIN productos p ON p.id = vi.producto_id
        WHERE vi.venta_id = ?
        ORDER BY vi.id ASC
      ");
      $stmt->execute([$id]);
      $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Medio de pago real (si hay venta_pagos)
      $medioPago = $venta['medio_pago'] ?? 'N/A';
      if (hasVentaPagos($pdo)) {
        $stPagos = $pdo->prepare("
          SELECT GROUP_CONCAT(DISTINCT UPPER(medio_pago) SEPARATOR '+') as medios
          FROM venta_pagos WHERE venta_id = ?
        ");
        $stPagos->execute([$id]);
        $medios = $stPagos->fetchColumn();
        if ($medios) $medioPago = $medios;
      }

      json_response([
        'success' => true,
        'venta' => [
          'id' => (int)$venta['id'],
          'fecha' => $venta['fecha'],
          'cliente' => $venta['cliente_nombre'],
          'total' => (float)$venta['total'],
          'medio_pago' => $medioPago,
          'estado' => $venta['estado'] ?? 'EMITIDA',
          'items' => $items,
        ],
      ]);
      break;

    // =============================================
    // Estadísticas: Ventas por día (últimos 30 días)
    // =============================================
    case 'stats_ventas_por_dia':
      require_login();
      
      $whereEmitida = whereEmitida('');
      // Nota: sin alias porque la tabla es directa
      $stmt = $pdo->query("
        SELECT 
          DATE(fecha) AS fecha,
          COUNT(*) AS cantidad,
          SUM(total) AS total
        FROM ventas
        WHERE 
          fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND (estado IS NULL OR estado = 'EMITIDA')
        GROUP BY DATE(fecha)
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

      $params = [];
      $whereParts = ["(v.estado IS NULL OR v.estado = 'EMITIDA')"];

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
            SUM(vp.monto) AS total
          FROM venta_pagos vp
          JOIN ventas v ON v.id = vp.venta_id
          WHERE {$whereSQL}
          GROUP BY UPPER(vp.medio_pago)
          ORDER BY total DESC
        ");
      } else {
        $stmt = $pdo->prepare("
          SELECT 
            UPPER(v.medio_pago) AS medio_pago,
            COUNT(*) AS cantidad,
            SUM(v.total) AS total
          FROM ventas v
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

      $whereParts = ["(v.estado IS NULL OR v.estado = 'EMITIDA')"];
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
          SUM(vi.cantidad) AS unidades,
          SUM(vi.subtotal) AS total,
          COUNT(DISTINCT v.id) AS num_ventas
        FROM venta_items vi
        JOIN productos p ON p.id = vi.producto_id
        JOIN ventas v ON v.id = vi.venta_id
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
          COALESCE(SUM(total), 0) AS total,
          COALESCE(AVG(total), 0) AS promedio
        FROM ventas
        WHERE 
          (estado IS NULL OR estado = 'EMITIDA')
          AND fecha >= :desde
          AND fecha <= :hasta
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
          (estado IS NULL OR estado = 'EMITIDA')
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
      $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $basePath = dirname($_SERVER['SCRIPT_NAME']);
      $basePath = rtrim(str_replace('/api', '', $basePath), '/');
      
      $ticket_url = "{$protocol}://{$host}{$basePath}/ticket_publico.php?id={$venta_id}&ts={$ts}&token={$token}";

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
      $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $basePath = dirname($_SERVER['SCRIPT_NAME']);
      $basePath = rtrim(str_replace('/api', '', $basePath), '/');
      
      $ticket_url = "{$protocol}://{$host}{$basePath}/ticket_publico.php?id={$venta_id}&ts={$ts}&token={$token}";

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
      $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $basePath = dirname($_SERVER['SCRIPT_NAME']);
      $basePath = rtrim(str_replace('/api', '', $basePath), '/');
      
      $ticket_url = "{$protocol}://{$host}{$basePath}/ticket_publico.php?id={$venta_id}&ts={$ts}&token={$token}";

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