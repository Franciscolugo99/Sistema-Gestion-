<?php
// productos_demo.php
// SOLO DEMO VISUAL – no toca tu sistema real

// mock de productos para la tabla
$productosDemo = [
    ['codigo' => '1001', 'nombre' => 'Coca Cola 1.5L', 'categoria' => 'Bebidas', 'precio' => 1200, 'stock' => 10],
    ['codigo' => '1002', 'nombre' => 'Fernet Branca 750', 'categoria' => 'Bebidas', 'precio' => 3500, 'stock' => 4],
    ['codigo' => '1003', 'nombre' => 'Gomitas Mogul', 'categoria' => 'Golosinas', 'precio' => 800, 'stock' => 25],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Demo layout 80% – Productos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Si querés, podés comentar estas líneas y usar solo el <style> de abajo -->
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/app.css">

  <style>
    /* ============================
       DEMO CONTENEDOR GLOBAL 80%
    ============================ */

    body {
      margin: 0;
      padding: 0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #e5edf7;
    }

    /* contenedor que queremos llevar al sistema real */
    .container-global {
      width: 80%;
      max-width: 1600px;
      margin: 0 auto;
    }

    /* NAV DEMO */
    .top-nav {
      background: #0f172a;
      padding: 10px 0;
      box-shadow: 0 10px 30px rgba(15,23,42,.45);
    }
    .top-nav-inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      color: #e5e7eb;
    }
    .top-nav-left span.logo {
      font-weight: 600;
      letter-spacing: .08em;
    }
    .top-nav-links {
      display: flex;
      gap: 8px;
    }
    .top-nav-links a {
      padding: 6px 12px;
      border-radius: 999px;
      border: 1px solid rgba(148,163,184,.6);
      text-decoration: none;
      font-size: 0.82rem;
      color: #e5e7eb;
    }
    .top-nav-links a.active {
      background: #f9fafb;
      color: #0f172a;
      border-color: transparent;
    }

    /* Panel genérico (similar al tuyo) */
    .panel-demo {
      background: #f9fafb;
      margin-top: 24px;
      margin-bottom: 24px;
      padding: 26px 30px;
      border-radius: 18px;
      box-shadow: 0 22px 60px rgba(0,0,0,.35);
      border: 1px solid rgba(15,23,42,0.04);
    }

    .panel-demo h1 {
      margin: 0 0 6px;
      font-size: 1.4rem;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .sub-title {
      font-size: 0.9rem;
      color: #6b7280;
      margin-bottom: 16px;
    }

    /* fila cabecera form + botón */
    .form-header-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
      margin-top: 10px;
    }

    .btn-pill {
      border-radius: 999px;
      padding: 8px 18px;
      border: 1px solid #cbd5f5;
      background: #f3f6ff;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: .07em;
      cursor: pointer;
    }

    .btn-primary {
      background: linear-gradient(135deg,#0ea5e9,#22d3ee);
      color:#022c22;
      border:none;
      box-shadow:0 14px 35px rgba(34,211,238,.65);
    }

    /* grid del formulario demo */
    .pf-grid-demo {
      display: grid;
      grid-template-columns: repeat(3, minmax(0,1fr));
      gap: 14px 18px;
      margin-top: 6px;
    }
    .pf-field {
      display: flex;
      flex-direction: column;
      gap: 4px;
      font-size: 0.85rem;
    }
    .pf-field label {
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #6b7280;
    }
    .pf-field input,
    .pf-field select {
      padding: 7px 9px;
      border-radius: 8px;
      border: 1px solid #d1d5db;
      font-size: 0.9rem;
    }
    .pf-field-wide {
      grid-column: span 2;
    }

    .pf-actions {
      margin-top: 18px;
      display: flex;
      gap: 10px;
    }

    /* Tabla/listado */
    .panel-table {
      margin-top: 8px;
    }
    .table-wrapper-demo {
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid #d1d5db;
      background: #ffffff;
    }
    table.demo-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
    }
    .demo-table th,
    .demo-table td {
      padding: 7px 10px;
      border-bottom: 1px solid #e5e7eb;
    }
    .demo-table thead {
      background: #0f172a;
      color: #e5e7eb;
      text-transform: uppercase;
      letter-spacing: .08em;
      font-size: 0.75rem;
    }
    .demo-table tbody tr:nth-child(odd) {
      background: #f9fafb;
    }

    /* un poco de espacio global arriba */
    .page-top-space {
      height: 24px;
    }

    @media (max-width: 900px) {
      .pf-grid-demo {
        grid-template-columns: repeat(2,minmax(0,1fr));
      }
      .pf-field-wide {
        grid-column: span 2;
      }
    }
    @media (max-width: 600px) {
      .pf-grid-demo {
        grid-template-columns: 1fr;
      }
      .pf-field-wide {
        grid-column: span 1;
      }
    }
  </style>
</head>
<body>

  <!-- NAV ENVUELTO EN container-global -->
  <header class="top-nav">
    <div class="container-global top-nav-inner">
      <div class="top-nav-left">
        <span class="logo">FLUS DEMO</span>
      </div>
      <nav class="top-nav-links">
        <a href="#" class="active">Productos</a>
        <a href="#">Caja</a>
        <a href="#">Movimientos</a>
        <a href="#">Stock</a>
      </nav>
    </div>
  </header>

  <div class="page-top-space"></div>

  <!-- TODA LA PÁGINA ENVUELTA EN container-global -->
  <main class="container-global">

    <!-- PANEL FORM DEMO -->
    <section class="panel-demo">
      <h1>PRODUCTOS</h1>
      <div class="sub-title">Demo de layout al 80% de ancho</div>

      <div class="form-header-row">
        <h2 class="sub-title">Nuevo producto</h2>
        <button type="button" class="btn-pill">Ocultar formulario</button>
      </div>

      <form>
        <div class="pf-grid-demo">
          <div class="pf-field">
            <label>Código</label>
            <input type="text" placeholder="Ej: 1001">
          </div>
          <div class="pf-field pf-field-wide">
            <label>Nombre</label>
            <input type="text" placeholder="Nombre del producto">
          </div>
          <div class="pf-field">
            <label>Categoría</label>
            <input type="text" placeholder="Bebidas, Golosinas...">
          </div>
          <div class="pf-field">
            <label>Precio</label>
            <input type="number" step="0.01" placeholder="0.00">
          </div>
          <div class="pf-field">
            <label>Stock</label>
            <input type="number" placeholder="0">
          </div>
        </div>

        <div class="pf-actions">
          <button class="btn-primary" type="button">Guardar</button>
          <button class="btn-pill" type="button">Cancelar</button>
        </div>
      </form>
    </section>

    <!-- PANEL TABLA DEMO -->
    <section class="panel-demo panel-table">
      <h2 class="sub-title">Listado demo</h2>
      <div class="table-wrapper-demo">
        <table class="demo-table">
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Categoría</th>
              <th>Precio</th>
              <th>Stock</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($productosDemo as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['codigo']) ?></td>
              <td><?= htmlspecialchars($p['nombre']) ?></td>
              <td><?= htmlspecialchars($p['categoria']) ?></td>
              <td>$<?= number_format($p['precio'],2,',','.') ?></td>
              <td><?= (int)$p['stock'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

</body>
</html>
