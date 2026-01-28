# FLUS PATCH P1+P2 — Sesión unificada + API buscar_productos unificada

Fecha: 2026-01-28

## Qué corrige / mejora

### P1 — Sesión unificada (anti-legacy)
- Agrega `src/session_user.php` con helpers:
  - `session_user_id()`
  - `session_user()`
  - `session_permissions()`
  - `session_has_permission()`
  - `flus_session_normalize_user()` (normaliza `usuario_id`, `user_id`, `user[id]`, `permissions/permisos`)
- Se carga y normaliza automáticamente desde `public/bootstrap.php` y `public/auth.php`.
- `src/Middleware.php` ahora normaliza la sesión antes de validar auth/permisos (evita falsos “no autenticado”).
- Se eliminan lecturas inconsistentes legacy en puntos clave:
  - `public/api/cuenta_corriente_api.php`
  - `public/api/actions/cliente_consultar_cuit.php`
  - `public/includes/ClienteController.php`
  - `public/logout.php`
  - permisos en `public/backups.php`, `public/partials/nav.php`, `inventario_api.php`, `inventario_analisis.php`

### P2 — API `buscar_productos` unificada
- `public/api/index.php` ya **no** tiene un “hotfix” duplicado.
- `action=buscar_productos` delega a `public/api/actions/buscar_productos.php`.
- `public/api/actions/buscar_productos.php` ahora:
  - usa query con **prepared statements**
  - prioriza coincidencias (exact / empieza) para ranking
  - devuelve campos esperados por Caja: `id,codigo,nombre,precio,stock,es_pesable,unidad_venta`
  - es compatible si en tu DB existe `precio` o `precio_venta` (elige el que exista)

## Cómo aplicar
1. Descomprimí el ZIP **encima** de tu proyecto (mismo nivel de `public/` y `src/`).
2. Verificá que se haya copiado:
   - `src/session_user.php`
3. Si tenés opcache, reiniciá Apache.

## Checks rápidos
- Login / navegación normal.
- Caja: autocompletado funciona y trae `stock/es_pesable/unidad_venta`.
- Inventario análisis: abre y no corta por permisos.
- Backups: respeta permiso `gestionar_backups`.
- Clientes: consulta CUIT (API) sin 401 cuando estás logueado.

## Archivos incluidos
- src/session_user.php
- public/bootstrap.php
- public/auth.php
- src/Middleware.php
- src/BaseController.php
- public/api/index.php
- public/api/actions/buscar_productos.php
- public/api/actions/cliente_consultar_cuit.php
- public/api/cuenta_corriente_api.php
- public/includes/ClienteController.php
- public/logout.php
- public/backups.php
- public/partials/nav.php
- public/api/inventario_api.php
- public/inventario_analisis.php
