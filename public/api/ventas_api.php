<?php
// api/ventas_api.php - Endpoints para las nuevas funcionalidades
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

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

      $stmt = $pdo->prepare("
        SELECT id, nombre, documento, email, telefono
        FROM clientes
        WHERE 
          nombre LIKE :q 
          OR documento LIKE :q 
          OR email LIKE :q
        ORDER BY nombre ASC
        LIMIT 20
      ");
      
      $stmt->execute([':q' => "%{$q}%"]);
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

      json_response([
        'success' => true,
        'venta' => [
          'id' => (int)$venta['id'],
          'fecha' => $venta['fecha'],
          'cliente' => $venta['cliente_nombre'],
          'total' => (float)$venta['total'],
          'items' => $items,
        ],
      ]);
      break;

    // =============================================
    // Estadísticas: Ventas por día (últimos 30 días)
    // =============================================
    case 'stats_ventas_por_dia':
      require_login();
      
      $stmt = $pdo->query("
        SELECT 
          DATE(fecha) AS fecha,
          COUNT(*) AS cantidad,
          SUM(total) AS total
        FROM ventas
        WHERE 
          fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND estado = 'EMITIDA'
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
    // =============================================
    case 'stats_ventas_por_medio':
      require_login();
      
      $desde = $_GET['desde'] ?? null;
      $hasta = $_GET['hasta'] ?? null;

      $where = "WHERE estado = 'EMITIDA'";
      $params = [];

      if ($desde) {
        $where .= " AND fecha >= :desde";
        $params[':desde'] = $desde . ' 00:00:00';
      }

      if ($hasta) {
        $where .= " AND fecha <= :hasta";
        $params[':hasta'] = $hasta . ' 23:59:59';
      }

      $stmt = $pdo->prepare("
        SELECT 
          medio_pago,
          COUNT(*) AS cantidad,
          SUM(total) AS total
        FROM ventas
        {$where}
        GROUP BY medio_pago
        ORDER BY total DESC
      ");

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

      $where = "WHERE v.estado = 'EMITIDA'";
      $params = [];

      if ($desde) {
        $where .= " AND v.fecha >= :desde";
        $params[':desde'] = $desde . ' 00:00:00';
      }

      if ($hasta) {
        $where .= " AND v.fecha <= :hasta";
        $params[':hasta'] = $hasta . ' 23:59:59';
      }

      $stmt = $pdo->prepare("
        SELECT 
          p.nombre,
          SUM(vi.cantidad) AS unidades,
          SUM(vi.subtotal) AS total,
          COUNT(DISTINCT v.id) AS num_ventas
        FROM venta_items vi
        JOIN productos p ON p.id = vi.producto_id
        JOIN ventas v ON v.id = vi.venta_id
        {$where}
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
      $diff = $desde_dt->diff($hasta_dt)->days;

      $desde_prev = (clone $desde_dt)->sub(new DateInterval("P{$diff}D"))->format('Y-m-d');
      $hasta_prev = (clone $hasta_dt)->sub(new DateInterval("P{$diff}D"))->format('Y-m-d');

      // Período actual
      $stmt = $pdo->prepare("
        SELECT 
          COUNT(*) AS cantidad,
          COALESCE(SUM(total), 0) AS total,
          COALESCE(AVG(total), 0) AS promedio
        FROM ventas
        WHERE 
          estado = 'EMITIDA'
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

      json_response([
        'success' => true,
        'stats' => [
          'actual' => $actual,
          'anterior' => $anterior,
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
          estado = 'EMITIDA'
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
    // Enviar ticket por WhatsApp
    // =============================================
    case 'send_ticket_whatsapp':
      require_login();
      require_method('POST');
      
      $input = json_decode(file_get_contents('php://input'), true);
      $venta_id = (int)($input['venta_id'] ?? 0);
      $phone = trim((string)($input['phone'] ?? ''));

      if ($venta_id <= 0 || $phone === '') {
        success_fail('Datos inválidos', 400);
      }

      // Generar link de ticket
      $ticket_url = "https://{$_SERVER['HTTP_HOST']}/ticket.php?id={$venta_id}";

      // Texto del mensaje
      $message = urlencode("Tu ticket de compra: {$ticket_url}");

      // Construir URL de WhatsApp
      $whatsapp_url = "https://wa.me/{$phone}?text={$message}";

      json_response([
        'success' => true,
        'url' => $whatsapp_url,
        'message' => 'Abrí el link para enviar por WhatsApp',
      ]);
      break;
      // =============================================
      // Preview rápido de venta (CON TRACKING)
      // =============================================
      case 'venta_preview':
        require_login();
        
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
          success_fail('ID inválido', 400);
        }

        // ⭐ NUEVO: Actualizar ultima_visualizacion
        try {
          $pdo->prepare("UPDATE ventas SET ultima_visualizacion = NOW() WHERE id = ?")->execute([$id]);
        } catch (Exception $e) {
          // Si la columna no existe, seguir sin error
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

        json_response([
          'success' => true,
          'venta' => [
            'id' => (int)$venta['id'],
            'fecha' => $venta['fecha'],
            'cliente' => $venta['cliente_nombre'],
            'total' => (float)$venta['total'],
            'items' => $items,
          ],
        ]);
        break;
    // =============================================
    // Enviar ticket por Email
    // =============================================
    case 'send_ticket_email':
      require_login();
      require_method('POST');
      
      $input = json_decode(file_get_contents('php://input'), true);
      $venta_id = (int)($input['venta_id'] ?? 0);
      $email = trim((string)($input['email'] ?? ''));

      if ($venta_id <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        success_fail('Datos inválidos', 400);
      }

      // Obtener datos de la venta
      $stmt = $pdo->prepare("SELECT * FROM ventas WHERE id = ? LIMIT 1");
      $stmt->execute([$venta_id]);
      $venta = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$venta) {
        success_fail('Venta no encontrada', 404);
      }

      // Generar ticket HTML
      $ticket_url = "https://{$_SERVER['HTTP_HOST']}/ticket.php?id={$venta_id}";

      $subject = "Tu ticket de compra #{$venta_id}";
      $message = "
        <html>
          <body>
            <h2>Gracias por tu compra</h2>
            <p>Tu ticket está disponible en: <a href='{$ticket_url}'>{$ticket_url}</a></p>
            <p>Total: " . money($venta['total']) . "</p>
          </body>
        </html>
      ";

      $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        'From: ventas@tuempresa.com',
      ];

      if (mail($email, $subject, $message, implode("\r\n", $headers))) {
        json_response([
          'success' => true,
          'message' => 'Ticket enviado correctamente',
        ]);
      } else {
        success_fail('Error al enviar email', 500);
      }
      break;

    default:
      success_fail('Acción no válida', 400);
  }

} catch (Exception $e) {
  error_log("API Error: " . $e->getMessage());
  success_fail('Error interno del servidor', 500);
}

