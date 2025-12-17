
# Proyecto KIOSCO organizado

Estructura propuesta:

- public/
  - index.php, caja.php, productos.php, stock.php, movimientos.php, ventas.php, venta_detalle.php
  - api/
    - index.php  (lógica de la API, viene del antiguo api.php)
    - api.php    (compatibilidad, simplemente incluye index.php)
  - assets/
    - css/app.css   (copiar aquí tu CSS si ya lo tenías)
    - js/caja.js    (copiar aquí tu JS si ya lo tenías)
  - partials/
    - nav.php       (si tenías un menú de navegación)

- src/
  - config.php      (configuración de base de datos y función getPDO())

Para usarlo en un servidor:

- Colocá la carpeta `public` como DocumentRoot (o apuntá tu servidor a `public/`).
- El frontend llama a la API usando la ruta relativa `api/api.php`, que reenvía a `api/index.php`.
- Los archivos PHP que necesitan base de datos usan:
    require_once __DIR__ . '/../src/config.php';
