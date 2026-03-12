# FLUS ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“ Sistema de GestiÃƒÆ’Ã‚Â³n POS (PHP + MySQL)

Sistema web tipo **POS / gestiÃƒÆ’Ã‚Â³n** para kioscos y comercios.

**Version:** 3.4.0  
**Build:** 2026-03-11  
**PHP:** 8.0+  
**Base de datos:** MySQL/MariaDB

---

## Estado actual (2026-03-11)

### Base tecnica
- Panel Tecnico interno para soporte, diagnostico y ejecucion de smoke tests desde la UI.
- Smoke tests minimos en `tests/` para helpers criticos y chequeos rapidos del sistema.
- Hardening general en login, backups/restore, usuarios y links publicos.

### Productos
- Reglas de estado extraidas a helper compartido (`src/productos_helpers.php`).
- Busqueda mejorada con prioridad por codigo exacto y prefijo.
- Filtro de pesables y mejor lectura de stock/unidad para productos KG/G/LT/ML.
- Modal de edicion mas estable: ya no se cierra al guardar y evita errores JS visibles para el usuario.

### Stock
- Tabla mas alineada visualmente con productos.
- Soporte mas claro para pesables en listado y ajuste rapido.
- Filtro de pesables, busqueda priorizada por codigo e historial reciente dentro del modal de ajuste.

### Proveedores
- Relacion con productos mas confiable mediante sincronizacion por `proveedor_id` y re-vinculacion de legacy.
- Re-vinculacion puntual por proveedor y accion global para re-vincular todo.
- Vista enriquecida con resumen operativo, ultima compra, historial de compras y productos asociados con modales dedicados.

### Base / Seguridad / Migrations
- Contrato JSON estandar (`ok/error`) + CSRF reforzado en APIs.
- Runner `scripts/migrate.php` + carpeta `migrations/` (**idempotente**).
- Views portables sin `DEFINER` y limpieza de inconsistencias de esquema.
- Compras: columnas de descuentos/totales ya versionadas por migracion y no por `ALTER TABLE` en tiempo de request.

> Ver `CHANGELOG.md` para el detalle historico por version.

## Actualizacion de instalaciones existentes

- Hacer backup de archivos y base de datos antes de desplegar.
- Copiar la nueva version y ejecutar `php scripts/migrate.php`.
- Validar modulos criticos despues del deploy.
- Usar la guia de [docs/UPGRADE_3.4.0.md](docs/UPGRADE_3.4.0.md).

## Requisitos

- PHP 8.0+
- MySQL/MariaDB
- Apache/Nginx
- Extensiones PHP tÃƒÆ’Ã‚Â­picas: `pdo_mysql`, `json`, `mbstring`, `zip`

---

## InstalaciÃƒÆ’Ã‚Â³n rÃƒÆ’Ã‚Â¡pida (dev)

1. Configurar DB y credenciales en `src/config.php` (o el flujo de `public/install.php`).
2. Ejecutar migraciones:
   ```bash
   php scripts/migrate.php
   ```
3. Abrir el sistema desde `public/`.

## Checklist de release / deploy

1. Confirmar backup de base y proyecto.
2. Copiar archivos nuevos al servidor.
3. Ejecutar `php scripts/migrate.php`.
4. Validar login, ventas, compras, productos, stock y proveedores.
5. Revisar `src/version.php` en la instancia desplegada.

---

## Smoke test (minimo)

- Login + cambio de tema
- Panel Tecnico: abrir, ejecutar smoke tests y validar salida en verde
- Caja: venta simple / anulacion (si aplica) / no duplica por doble click
- Productos: busqueda por codigo, edicion basica, pesables y cambio de estado
- Stock: ajuste rapido, historial reciente y visualizacion de pesables
- Proveedores: editar, ver historial/productos y probar re-vinculacion puntual o global
- Backups: crear + validar
- Diagnostico: generar paquete ZIP
- Inventario fisico: crear sesion + conteo + cerrar + aplicar ajustes

---


## DocumentaciÃƒÆ’Ã‚Â³n histÃƒÆ’Ã‚Â³rica (v2.3.x)

Contenido recuperado desde commit 3c12bdf para no perder notas operativas.

# FLUS ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“ Sistema de GestiÃƒÆ’Ã‚Â³n POS (PHP + MySQL)

Sistema web tipo **POS / gestiÃƒÆ’Ã‚Â³n** para kioscos y comercios.

**Version:** 2.3.1  
**PHP:** 8.0+  
**Base de datos:** MySQL/MariaDB

---

## ÃƒÂ°Ã…Â¸Ã¢â‚¬Â Ã¢â‚¬Â¢ Novedades v2.3.1 (2026-01-28)

- ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Correcciones de estabilidad en endpoints (p. ej. `strict_types` en acciones PHP).
- ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Ajustes y refactors menores en APIs: **cuenta corriente**, **inventario**, **clientes**.
- ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Mejoras de consistencia en exportaciones (sesiÃƒÆ’Ã‚Â³n de caja / dashboard).

> Ver [CHANGELOG.md](CHANGELOG.md) para el detalle y el histÃƒÆ’Ã‚Â³rico.

---

## ÃƒÂ°Ã…Â¸Ã¢â‚¬Â Ã¢â‚¬Â¢ Novedades v2.3.0

- ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ **Ventas**: Historial avanzado con filtros, KPIs, grÃƒÆ’Ã‚Â¡ficos y **exportaciÃƒÆ’Ã‚Â³n CSV**
- ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ **Ventas**: **Autocompletado de clientes** (dropdown visual + teclado)
- ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ **Ventas**: **Ticket pÃƒÆ’Ã‚Âºblico compartible** (link con token) + acciones WhatsApp/Email
- ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ **Backups**: mejoras de robustez/UX + lock de restore

> Ver [CHANGELOG.md](CHANGELOG.md) para el detalle completo y notas de upgrade.

---

## ÃƒÂ°Ã…Â¸Ã‚Â§Ã‚Â  Arquitectura recomendada (LAN)

- **Servidor**: PC donde corre Apache/PHP + BD (y donde vive `storage/`).  
- **Terminales**: PCs que ingresan por navegador vÃƒÆ’Ã‚Â­a LAN (no ejecutan PHP local).

**Importante:** funcionalidades como *ticket pÃƒÆ’Ã‚Âºblico* se validan en el **servidor**, por eso secretos como `APP_SECRET` deben existir y mantenerse estables ahÃƒÆ’Ã‚Â­.


---

## ÃƒÂ¢Ã…â€œÃ‚Â¨ CaracterÃƒÆ’Ã‚Â­sticas Principales

### ÃƒÂ°Ã…Â¸Ã‚ÂÃ‚Âª Punto de Venta (Caja)
- Carga rÃƒÆ’Ã‚Â¡pida por cÃƒÆ’Ã‚Â³digo de barras o bÃƒÆ’Ã‚Âºsqueda
- Soporte para productos **pesables** (carnicerÃƒÆ’Ã‚Â­a, fiambres, frutas)
- **Split payments** (pagos con 2 medios)
- Descuentos globales por monto o porcentaje
- GeneraciÃƒÆ’Ã‚Â³n de ticket tÃƒÆ’Ã‚Â©rmico (58mm/80mm)
- Atajos de teclado (F2 cobrar, F4 cancelar, F5 foco)

### Productos & Stock
- ABM con panel lateral de edicion
- Stock minimo con alertas
- Productos pesables (KG/G/LT/ML) con visualizacion consistente en tabla, modal y detalle
- Filtros por pesables y busqueda priorizada por codigo
- Stock con ajuste rapido e historial reciente por producto
- Categorias, marcas y proveedores

### Proveedores
- ABM con drawer lateral y resumen operativo
- Ultima compra, compras recientes y productos asociados
- Re-vinculacion de productos legacy por proveedor o global
- Mejor soporte para mantener sincronizado proveedor ? productos

### ÃƒÂ°Ã…Â¸Ã…Â½Ã‚Â Promociones
- **NxM**: LlevÃƒÆ’Ã‚Â¡s N, pagÃƒÆ’Ã‚Â¡s M (ej: 3x2)
- **NÃƒâ€šÃ‚Â° al X%**: Cada N unidades, descuento del X%
- **Combos fijos**: Productos combinados a precio especial
- Motor centralizado (PromoEngine)

### ÃƒÂ°Ã…Â¸Ã¢â‚¬â€œÃ‚Â¥ÃƒÂ¯Ã‚Â¸Ã‚Â Multi-Terminal
- Soporte para mÃƒÆ’Ã‚Âºltiples cajas/terminales
- Sistema de locks para evitar conflictos
- Heartbeat para detectar cajas inactivas

### ÃƒÂ°Ã…Â¸Ã¢â‚¬ËœÃ‚Â¥ Usuarios & Seguridad
- RBAC (Roles y Permisos)
- CSRF protection en todos los forms
- AuditorÃƒÆ’Ã‚Â­a de acciones
- Sesiones seguras

### ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã…Â  Reportes
- Dashboard con estadÃƒÆ’Ã‚Â­sticas
- Historial de ventas con filtros
- ExportaciÃƒÆ’Ã‚Â³n CSV
- Historial de caja

---

## ÃƒÂ°Ã…Â¸Ã…Â¡Ã¢â€šÂ¬ InstalaciÃƒÆ’Ã‚Â³n RÃƒÆ’Ã‚Â¡pida

### 1. Requisitos
- PHP 8.0+ (recomendado 8.1/8.2)
- MySQL/MariaDB 5.7+
- Apache con mod_rewrite (XAMPP recomendado en Windows)
- Extensiones PHP: `pdo_mysql`, `mbstring`, `openssl`

### 2. ConfiguraciÃƒÆ’Ã‚Â³n
```bash
# Clonar o descomprimir
cd /htdocs/flus

# Copiar configuraciÃƒÆ’Ã‚Â³n
cp src/config.example.php src/config.php

# Editar credenciales de BD
nano src/config.php
```

### 3. Base de Datos
```sql
CREATE DATABASE kiosco CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
Luego importar el schema (disponible en instalador web).

### 4. Acceder
```
http://localhost/flus/public/install.php
```

---

## ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â Estructura del Proyecto

```
flus/
ÃƒÂ¢Ã¢â‚¬ÂÃ…â€œÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ public/              # Archivos web accesibles
ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬Å¡   ÃƒÂ¢Ã¢â‚¬ÂÃ…â€œÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ api/            # Endpoints REST
ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬Å¡   ÃƒÂ¢Ã¢â‚¬ÂÃ…â€œÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ assets/         # CSS, JS
ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬Å¡   ÃƒÂ¢Ã¢â‚¬ÂÃ…â€œÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ includes/       # PromoEngine, Controllers
ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬Å¡   ÃƒÂ¢Ã¢â‚¬ÂÃ…â€œÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ lib/            # Helpers core
ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬Å¡   ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬ÂÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ partials/       # Fragmentos HTML
ÃƒÂ¢Ã¢â‚¬ÂÃ…â€œÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ src/                # LÃƒÆ’Ã‚Â³gica backend
ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬Å¡   ÃƒÂ¢Ã¢â‚¬ÂÃ…â€œÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ config.php      # ConfiguraciÃƒÆ’Ã‚Â³n (crear desde example)
ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬Å¡   ÃƒÂ¢Ã¢â‚¬ÂÃ…â€œÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ helpers.php     # Funciones globales
ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬Å¡   ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬ÂÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ *.php           # Controllers y libs
ÃƒÂ¢Ã¢â‚¬ÂÃ…â€œÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ storage/            # Archivos generados (backups, logs)
ÃƒÂ¢Ã¢â‚¬ÂÃ¢â‚¬ÂÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ scripts/            # Scripts CLI
```

---

## ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ‚Â Permisos Disponibles

| Permiso | DescripciÃƒÆ’Ã‚Â³n |
|---------|-------------|
| `realizar_ventas` | Usar la caja |
| `cerrar_caja` | Cerrar sesiÃƒÆ’Ã‚Â³n de caja |
| `editar_productos` | ABM de productos |
| `editar_stock` | Ajustar stock |
| `ver_reportes` | Ver historial de ventas |
| `administrar_usuarios` | Gestionar usuarios y roles |
| `gestionar_backups` | Crear/restaurar backups |
| `ver_diagnostico` | Ver diagnostico y descargar paquetes de soporte |
| `administrar_config` | ConfiguraciÃƒÆ’Ã‚Â³n del sistema |
| `caja_modificar_precio` | Cambiar precios en caja |

---

## ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â¡ API Endpoints

| Endpoint | MÃƒÆ’Ã‚Â©todo | DescripciÃƒÆ’Ã‚Â³n |
|----------|--------|-------------|
| `?action=buscar_producto` | GET | Buscar por cÃƒÆ’Ã‚Â³digo |
| `?action=buscar_productos` | GET | Buscar por nombre (autocomplete) |
| `?action=calcular_carrito` | POST | Calcular precios con promos |
| `?action=registrar_venta` | POST | Registrar venta |
| `?action=listar_promos_activas` | GET | Promociones vigentes |
| `?action=terminal_list` | GET | Listar terminales |
| `ventas_api.php?action=listar_ventas` | GET | Listado de ventas (filtros/paginaciÃƒÆ’Ã‚Â³n) |
| `ventas_api.php?action=venta_preview` | GET | Preview de venta (modal) |
| `ventas_api.php?action=stats` | GET | KPIs/series para grÃƒÆ’Ã‚Â¡ficos |
| `ventas_api.php?action=buscar_clientes` | GET | Autocomplete de clientes |
| `ventas_api.php?action=ticket_publico_url` | GET | Generar URL/token de ticket pÃƒÆ’Ã‚Âºblico |
| `ventas_api.php?action=send_ticket_whatsapp` | POST | Preparar envÃƒÆ’Ã‚Â­o por WhatsApp (wa.me) |
| `ventas_api.php?action=send_ticket_email` | POST | EnvÃƒÆ’Ã‚Â­o por Email (si estÃƒÆ’Ã‚Â¡ habilitado) |


---

## ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂºÃ‚Â ÃƒÂ¯Ã‚Â¸Ã‚Â Desarrollo

### Convenciones
- PHP: `declare(strict_types=1)` en todos los archivos
- SQL: Prepared statements obligatorios
- JS: ES6+ sin transpilaciÃƒÆ’Ã‚Â³n
- CSS: BEM-like con prefijos por mÃƒÆ’Ã‚Â³dulo

### Testing
```bash
# Verificar sintaxis PHP
find . -name "*.php" -exec php -l {} \;

# Verificar que la API responde
curl http://localhost/flus/public/api/index.php?action=health
```

---

### ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ¢â‚¬Å¾ Upgrade desde versiones anteriores

### Desde v2.3.0 a v2.3.1

1. **Backup recomendado** (BD + carpeta `storage/`)
2. **Reemplazar archivos**
   - Conservar `src/config.php`
   - **No pisar `storage/`** (logs, backups, uploads, locks, etc.)
3. **Migraciones**
   - Esta versiÃƒÆ’Ã‚Â³n es principalmente de mantenimiento. Si tu branch incluyÃƒÆ’Ã‚Â³ cambios de BD, agregÃƒÆ’Ã‚Â¡ y documentÃƒÆ’Ã‚Â¡ un script `scripts/upgrade_v231.sql`.
4. **VerificaciÃƒÆ’Ã‚Â³n rÃƒÆ’Ã‚Â¡pida**
   - Caja / Productos: bÃƒÆ’Ã‚Âºsqueda y autocompletado
   - Inventario: consultas principales
   - Reportes / exportaciones: generar CSV sin errores

---

### Desde v2.2.x a v2.3.0

1. **Backup obligatorio** (BD + carpeta `storage/`)
2. **Reemplazar archivos**
   - Conservar `src/config.php`
   - **No pisar `storage/`** (logs, backups, uploads, locks, etc.)
3. **Cosas a considerar**
   - Agregar `storage/restore.lock` al `.gitignore` (runtime, no va al repo).
   - Si vas a usar **ticket pÃƒÆ’Ã‚Âºblico**, definir un `APP_SECRET` real en el **servidor** (no usar el secreto por defecto).
     - Recomendado: persistirlo en `storage/app_secret.key` para que no cambie entre upgrades.
     - El link incluye `ts` y el token **expira**: TTL por defecto 7 dÃƒÆ’Ã‚Â­as (configurable con `TICKET_TOKEN_TTL_SECONDS`).
   - Si tu instalaciÃƒÆ’Ã‚Â³n usa pagos mixtos: la tabla `venta_pagos` mejora la calidad de los reportes; si no existe, FLUS funciona igual (modo compat).

4. **VerificaciÃƒÆ’Ã‚Â³n rÃƒÆ’Ã‚Â¡pida**
   - Ventas ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ filtrar y exportar CSV
   - Abrir preview de una venta
   - Generar link de ticket pÃƒÆ’Ã‚Âºblico y abrirlo (debe validar token)

---

### Desde v2.1.x a v2.2.0

1. **Backup obligatorio**
```bash
mysqldump -u root -p kiosco > backup_antes_v220.sql
```

2. **Reemplazar archivos**
- Subir los nuevos archivos (conservar `src/config.php`)

3. **Ejecutar script SQL**
```bash
mysql -u root -p kiosco < scripts/upgrade_v220.sql
```

4. **Verificar**
- Entrar al sistema y probar crear/eliminar un producto que estÃƒÆ’Ã‚Â© en un combo
- Si funciona sin errores, el upgrade fue exitoso

## ÃƒÂ°Ã…Â¸Ã‚Â§Ã‚Â­ Roadmap corto (WIP)

- Licencias: en **Acerca de** mostrar plan/vencimiento/dÃƒÆ’Ã‚Â­as restantes leyendo `storage/license.json` (pendiente).
- Documentar upgrade SQL por versiÃƒÆ’Ã‚Â³n (un archivo por release cuando aplique).
