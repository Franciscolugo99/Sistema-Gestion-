# FLUS – Sistema de Gestión POS (PHP + MySQL)

Sistema web tipo **POS / gestión** para kioscos y comercios.

**Versión:** 3.2.2  
**Build:** 2026-01-29  
**PHP:** 8.0+  
**Base de datos:** MySQL/MariaDB

---

## 🆕 Novedades v3.2.2 (build 2026-01-29)

### Funcionalidades
- ✅ **Diagnóstico** exportable en ZIP (soporte / troubleshooting).
- ✅ **Inventario físico (conteo)** + aplicación de ajustes con movimientos de stock.
- ✅ **Reposición sugerida** (stock bajo / sugerencias) + export CSV.
- ✅ **Historial de precios / ajustes masivos**.
- ✅ **System API**: endpoints para operación/soporte (health/diagnóstico/backups) y para módulos nuevos (inventario/reposición/precios).

### Base / Seguridad / Migrations
- ✅ **Hardening P0**: contrato JSON estándar (`ok/error`) + CSRF reforzado en APIs.
- ✅ **Migraciones P1**: runner `scripts/migrate.php` + carpeta `migrations/` (**idempotente**).
- ✅ **BD portable**: views sin `DEFINER` y limpieza de inconsistencias (FKs duplicadas).

### Repo / mantenimiento
- ✅ Se **ignora** el estado local de licencia (no se versiona).
- ✅ Se versionan las migraciones SQL (`migrations/*.sql`).
- ✅ Refactor de alineación entre scripts API/UI + install + helpers.

> Nota: los permisos de acceso se alinearon a slugs existentes (`ver_stock`, `editar_stock`, `editar_productos`, `gestionar_backups`, `ver_reportes`).

---

## Requisitos

- PHP 8.0+
- MySQL/MariaDB
- Apache/Nginx
- Extensiones PHP típicas: `pdo_mysql`, `json`, `mbstring`, `zip`

---

## Instalación rápida (dev)

1. Configurar DB y credenciales en `src/config.php` (o el flujo de `public/install.php`).
2. Ejecutar migraciones:
   ```bash
   php scripts/migrate.php
   ```
3. Abrir el sistema desde `public/`.

---

## Migraciones (upgrade)

- Las migraciones son **idempotentes**: podés correr `php scripts/migrate.php` varias veces.
- Si actualizás una instalación vieja: **backup → migrate → smoke test**.

---

## Smoke test (mínimo)

- Login + cambio de tema
- Caja: venta simple / anulación (si aplica) / no duplica por doble click
- Productos: búsqueda + edición básica
- Stock / movimientos
- Backups: crear + validar
- Diagnóstico: generar paquete ZIP
- Inventario físico: crear sesión + conteo + cerrar + aplicar ajustes
