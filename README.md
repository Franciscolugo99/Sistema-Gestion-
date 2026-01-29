# FLUS – Sistema de Gestión POS (PHP + MySQL)

Sistema web tipo **POS / gestión** para kioscos y comercios.

**Versión:** 3.2.2  
**Build:** 2026-01-29  
**PHP:** 8.0+  
**Base de datos:** MySQL/MariaDB

---

## 🆕 Novedades v3.2.2 (build 2026-01-29)

- ✅ **Hardening P0**: contrato JSON estándar (`ok/error`), CSRF reforzado, base portable (views sin DEFINER, limpieza FKs duplicadas).
- ✅ **Migraciones P1**: runner `scripts/migrate.php` + carpeta `migrations/` (idempotente).
- ✅ **Operación**:
  - **Health / Diagnóstico** (paquete ZIP) desde `diagnostico.php` + `api/system_api.php`.
  - **Backups**: create/list/validate/restore por API (con auditoría).
- ✅ **Nuevos módulos (P2)**:
  - **Inventario físico (conteo)**: `inventario_fisico.php` + ajustes de stock con movimientos.
  - **Historial de precios / ajustes masivos**: `precios_historial.php`.
  - **Reposición sugerida**: `reposicion.php` (stock bajo / cantidad óptima / export CSV).

> Nota: los permisos de acceso se alinearon a slugs existentes (`ver_stock`, `editar_stock`, `editar_productos`, `gestionar_backups`, `ver_reportes`).

---

## Requisitos

- PHP 8.0+
- MySQL/MariaDB
- Apache/Nginx
- Extensiones PHP típicas: pdo_mysql, json, mbstring, zip

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
- Si migrás una instalación vieja: primero backup, luego migrate, luego smoke test.

---

## Smoke test (mínimo)

- Login + cambio de tema
- Caja: venta simple / anulación (si aplica) / no duplica por doble click
- Productos: búsqueda + edición básica
- Stock / movimientos
- Backups: crear + validar
- Diagnóstico: generar paquete
- Inventario físico: crear sesión + conteo + cerrar + aplicar ajustes

