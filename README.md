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

## Instalación rápida (XAMPP en Windows)

1) **Clonar** o copiar el proyecto dentro de `htdocs`, por ejemplo:

```
C:\xampp\htdocs\kiosco
```

2) Crear tu archivo de configuración:

- Copiá:

```
/src/config.example.php  ->  /src/config.php
```

- Editá credenciales:

```php
$DB_HOST = 'localhost';
$DB_NAME = 'kiosco';
$DB_USER = 'root';
$DB_PASS = '';
```

3) **Apuntar el DocumentRoot a `public/`**

Opción A (simple en local): entrar por URL con `public`:

- `http://localhost/kiosco/public/`

Opción B (recomendado): configurar Apache para que el DocumentRoot sea `.../kiosco/public`.

4) **Base de datos**

Este repo **no trae** un `.sql` de esquema/datos. Tenés 2 opciones:

- Importar tu dump existente (`kiosco.sql`) en una DB llamada `kiosco`, o
- Crear las tablas manualmente (ver “Modelo de datos esperado”).

---

## Modelo de datos esperado (tablas)

En el código se usan (mínimo) estas tablas:

- `productos`
- `ventas`, `venta_items`
- `movimientos_stock`
- `promos`, `promo_productos`, `promo_combo_items`
- `caja_sesiones`
- `clientes`
- `compras`, `compra_items`, `proveedores`
- `facturas`, `config_facturacion`
- `users`, `roles`, `permissions`, `role_permission`
- `audit_log`
- `app_config`

> Si te falta alguna tabla, vas a ver errores SQL al navegar módulos específicos.

---

## Usuarios, roles y permisos

El sistema usa **RBAC**:

- `users.role_id` -> `roles.id`
- `role_permission` relaciona roles con `permissions`
- En PHP:
  - `require_login()` protege páginas
  - `require_permission('slug')` restringe secciones

No se incluyen credenciales por defecto en el repo (depende de tu base).
Si necesitás, podés crear un usuario admin directamente por SQL en tu entorno.

---

## Backups

### Desde la UI
Ruta: **Backups** (requiere permiso `gestionar_backups`).

Guarda archivos en:

```
/storage/backups
```

### Automatización (CLI)
Ejecutar:

```
C:\xampp\php\php.exe C:\xampp\htdocs\kiosco\scripts\backup_db.php
```

Recomendado: programarlo en el **Programador de tareas** de Windows.

---

## Estructura del proyecto

```
/public
  /api                Endpoints JSON (ej: registrar venta)
  /assets             CSS/JS
  /partials           header/footer/nav
  /lib                helpers, csrf, etc.
  *.php               módulos (caja, productos, ventas, etc.)

/src
  config.php          credenciales + getPDO()
  audit_lib.php
  backup_lib.php
  facturacion_lib.php
  logger.php

/storage
  /backups
  /logs
```

---

## API (resumen)

La API principal vive en:

- `/public/api/index.php` (con wrapper `/public/api/api.php`)

Ejemplos de acciones:

- `GET  ?action=buscar_producto&codigo=...`
- `POST ?action=registrar_venta` (requiere CSRF)

> El token CSRF se valida vía `X-CSRF-Token` o campo `csrf` en el body.

---

## Troubleshooting rápido

- **Pantalla en blanco / 500:** activar `display_errors` en PHP y revisar `storage/logs`.
- **No conecta a DB:** revisar `/src/config.php` y que exista la DB.
- **Permisos 403:** el usuario no tiene el permiso requerido (tabla `permissions` + `role_permission`).
- **Backups fallan:** verificar que exista `mysqldump` (XAMPP: `C:\xampp\mysql\bin\mysqldump.exe`).

---

## Roadmap (ideas)

- Dump/“instalador” de base con datos mínimos (roles, permisos, usuario admin).
- Unificar librerías duplicadas de backups (solo dejar `/src/backup_lib.php`).
- Facturación AFIP (WSFE) + diseño de comprobante “real” y numeración.
- Tests básicos y validaciones centralizadas.

---

## Licencia

Definir licencia (MIT/Propietaria) según cómo lo vayas a distribuir/comercializar.
