# FLUS – Sistema de Gestión para Kiosco (PHP + MySQL)

Sistema web tipo **POS / gestión** para kioscos y comercios chicos: **Caja/ventas**, **productos + stock**, **promociones**, **clientes**, **compras**, **facturación (base)**, **auditoría**, **usuarios/roles** y **backups**.

> Nota: este README reemplaza al `README.txt` viejo.

---

## Características principales

- **Caja (POS):** carga rápida de productos por código, cálculo de total/vuelto, generación de **ticket** y descuento de stock con registro en movimientos.
- **Productos:** ABM, panel lateral de edición, stock mínimo, productos **pesables** (3 decimales) y por unidad.
- **Promociones:**
  - Promo por producto (tipo “N paga M”, según lógica del módulo).
  - **Combos** (promo_combo + items).
- **Stock & movimientos:** movimientos por ventas y ajustes; vista de stock.
- **Ventas:** historial, filtros, detalle de venta y ticket imprimible.
- **Clientes:** ABM + asignación/uso en operaciones (según módulo).
- **Compras & proveedores:** registro de compras y sus ítems (según módulo).
- **Usuarios / Roles / Permisos:** RBAC (roles + permissions).
- **Auditoría:** registro de acciones relevantes (tabla `audit_log`).
- **Backups:** creación/listado/borrado/descarga de backups + script CLI para automatizar.

---

## Requisitos

- **PHP 8.0+** (recomendado 8.1/8.2)
- **MySQL/MariaDB**
- Servidor web **Apache** (XAMPP recomendado en Windows)
- Extensiones PHP típicas: `pdo_mysql`, `mbstring`, `openssl`

---



