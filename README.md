# FLUS – Sistema de Gestión POS (PHP + MySQL)

Sistema web tipo **POS / gestión** para kioscos y comercios.

**Version:** 3.8.0  
**Build:** 2026-03-23  
**PHP:** 8.0+  
**Base de datos:** MySQL/MariaDB

---

## Estado actual (2026-03-23)

### Ventas y anulaciones

- Soporte inicial para anulacion parcial no fiscal de ventas no facturadas.
- Historial de devoluciones dentro del detalle de venta, con motivo, usuario, fecha, monto devuelto y neto vigente.
- Detalle de venta rediseñado para distinguir mejor estado, metricas y productos devueltos parcial o totalmente.
- Listado y vista rapida de ventas con mejor lectura de estados `Parcial` y `Anulada`, mostrando original/devuelto/vigente.
- Criterio de ventas activas alineado para que `PARCIALMENTE_ANULADA` siga apareciendo en dashboard, reportes y exportaciones.

### Cuenta corriente

- Fix de compatibilidad de esquema para ventas y cobros CC en instalaciones viejas.
- El esquema base y las migraciones quedan alineados con el controlador de cuenta corriente y el arqueo por transferencia.

### Operacion y navegacion

- Nav refactorizado: partial mas limpio, CSS/JS externos y mejor consistencia visual.
- Header con ayuda rapida de atajos y mejor jerarquia para modulos principales.
- Base de perfiles de impresion para ticket, comanda y factura.
- Home principal mas directa: modulos mas claros, mejores tarjetas y accesos coherentes segun permiso real.

### Caja y terminales

- Caja mas robusta: recupera tickets pendientes por terminal/apertura y permite seguir cobrando despues de reabrir.
- Nuevo selector operativo de ticket: `Auto imprimir`, `Vista previa` o `No abrir`.
- Pantalla de apertura de caja mas clara y alineada con el resto del sistema.
- Terminales renovado: estados operativos, edicion inline y bloqueos para no desactivar cajas abiertas o terminales en uso.
- Terminales ahora admite override de ticket/papel por caja sin tocar esquema.
- Flujo de permisos separado para `abrir_caja`, `realizar_ventas` y `cerrar_caja`, evitando rebotes a historial no autorizado.

### Clientes y relacion comercial

- Ficha de cliente nueva con resumen comercial, fiscal y de cuenta corriente.
- Clientes ahora enlaza mejor con ventas, facturacion y cuenta corriente.
- Drawer de clientes con actividad vinculada para no tratar al cliente como un ABM aislado.

### Inventario y compras

- Analisis de inventario mas operativo: conserva filtros, exporta segun la vista activa y agrega acciones rapidas por producto.
- Nuevas vistas de solo consulta para `productos` y `stock`, separadas del ABM completo.
- Compras e inventario fisico pueden abrir con busqueda precargada desde analisis.

### Compras

- Borradores automaticos mientras se carga una compra.
- Recuperacion del borrador al volver al modulo.
- Mejor responsive en el formulario de carga y en anchos intermedios.

### Dashboard, roles y administracion

- Dashboard parcialmente desmontado del monolito: filtros, cache y metricas extraidos a `src/Dashboard/`.
- Graficos estabilizados despues de filtros y cache, con mejor recuperacion de datasets.
- Modulo de licencia mas administrativo y menos expuesto a detalles internos.
- Rol `Operador` agregado para negocios chicos con caja + stock + compras sin abrir administracion sensible.
- Pantalla de roles/permisos reorganizada por areas de negocio, impacto real y vista previa de accesos.
- Cuenta admin de resguardo protegida: no permite cambiar rol, estado, usuario ni vaciar su rol base.

### Base tecnica

- Panel Tecnico interno para soporte, diagnostico y ejecucion de smoke tests desde la UI.
- Smoke tests minimos en `tests/` para helpers criticos y chequeos rapidos del sistema.
- Hardening general en login, backups/restore, usuarios y links publicos.
- Runner `scripts/migrate.php` + carpeta `migrations/` (**idempotente**).
- Baseline `install.sql` para instalacion limpia + migraciones para actualizar instalaciones existentes.
- Chequeos de esquema movidos fuera de `INFORMATION_SCHEMA` runtime en zonas calientes como nav, ventas, precios, caja, install y soporte.

### Facturación electrónica

FLUS incorpora ahora un módulo completo de **facturación electrónica** que permite emitir comprobantes fiscales (A/B/C) de manera integrada al POS.  
Las principales capacidades son:

- **Listado de facturas:** busque y filtre facturas por fecha, cliente, número, tipo y estado, con accesos rápidos (Hoy/Semana/Mes). Se muestran KPIs del periodo (total facturado, ticket promedio, cantidad de facturas) y se puede exportar a CSV.
- **Emisión manual de facturas:** cree facturas sin venta previa. Seleccione el concepto (productos o servicios), busque productos con autocompletado, agregue líneas con cantidad, precio e IVA y visualice el total antes de emitir. El sistema calcula automáticamente alícuotas y solicita el CAE a ARCA/AFIP, generando el PDF oficial.
- **Facturación desde ventas:** desde el detalle de una venta puede emitirse la factura fiscal correspondiente. La librería de facturación determina el tipo de comprobante según la condición IVA del cliente y del emisor, genera la numeración y registra el CAE.
- **Configuración fiscal:** nuevo panel en `Administración → Configuración` para definir datos de la empresa (razón social, CUIT, domicilio, IIBB, punto de venta, condición IVA), subir certificados y claves para AFIP/ARCA, elegir el modo (Demo/Homologación/Producción), cargar el logo de la factura y establecer un límite de ítems por comprobante. Incluye pruebas de conexión y sincronización de numeración con ARCA.
- **Biblioteca de facturación:** funciones en `src/facturacion_lib.php` centralizan la lógica de emisión, cálculo de importes y comunicación con AFIP/ARCA. Soporta emisión desde ventas y manuales, validación de datos fiscales y generación de PDFs.

Estas funciones marcan el primer paso hacia un sistema de gestión más completo, permitiendo emitir comprobantes fiscales directamente desde FLUS.

> Ver `CHANGELOG.md` para el detalle histórico por versión.
### Notas de Crédito fiscales

FLUS incorpora gestión de **Notas de Crédito (NC)** sobre comprobantes ya emitidos, integrada al módulo de Facturación.

Capacidades principales:

- **NC total** sobre una factura origen.
- **NC parcial por ítem**, acreditando solo cantidades seleccionadas.
- Visualización de:
  - total original,
  - cantidad ya acreditada,
  - saldo fiscal restante,
  - estado comercial/fiscal asociado.
- Protección contra reenvíos y doble submit mediante idempotencia reforzada.
- Pantalla de recovery para casos `ERROR_POST_ARCA`, donde la parte fiscal fue aprobada pero falló la aplicación local/comercial.

Permiso específico:
- `emitir_nota_credito`

Archivos principales:
- `public/facturacion_nc.php`
- `public/facturacion_nc_emitir.php`
- `public/facturacion_nc_recovery.php`
- `src/Fiscal/Service/DbAnulacionFiscalCoordinator.php`
- `src/Fiscal/Service/DbFiscalRecoveryService.php`

Migraciones relacionadas:
- `010_anulaciones_parciales.sql`
- `011_cc_schema_compat.sql`
- `012_venta_anulaciones_fiscal.sql`
- `013_facturas_fiscal_ext.sql`
- `014_factura_items_eventos_arca.sql`
- `015_fiscal_nc_hardening.sql`
## Actualizacion de instalaciones existentes

- Hacer backup de archivos y base de datos antes de desplegar.
- Copiar la nueva version y ejecutar `php scripts/migrate.php`.
- Validar modulos criticos despues del deploy.
- Usar la guia de [docs/UPGRADE_3.4.0.md](docs/UPGRADE_3.4.0.md).

## Instalacion limpia

1. Configurar DB y credenciales en `src/config.php` (o el flujo de `public/install.php`).
2. Importar `install.sql`.
3. Ejecutar `php scripts/migrate.php`.
4. Ingresar con:
   - usuario: `admin`
   - clave: `flusadmin123`
5. Cambiar la clave inicial apenas termine la instalacion.

## Requisitos

- PHP 8.0+
- MySQL/MariaDB
- Apache/Nginx
- Extensiones PHP típicas: `pdo_mysql`, `json`, `mbstring`, `zip`

---

## Instalación rápida (dev)

1. Configurar DB y credenciales en `src/config.php` (o el flujo de `public/install.php`).
2. Si la base esta vacia, importar `install.sql`.
3. Ejecutar migraciones:
   ```bash
   php scripts/migrate.php
   ```
4. Abrir el sistema desde `public/`.

## Checklist de release / deploy

1. Confirmar backup de base y proyecto.
2. Copiar archivos nuevos al servidor.
3. Ejecutar `php scripts/migrate.php`.
4. Validar login, ventas, anulaciones parciales, compras, productos, stock y proveedores.
5. Revisar `src/version.php` en la instancia desplegada.

---

## Smoke test (minimo)

- Login + cambio de tema
- Panel Tecnico: abrir, ejecutar smoke tests y validar salida en verde
- Caja: venta simple / anulacion (si aplica) / no duplica por doble click
- Ventas: anulacion parcial no fiscal, anulacion total posterior y detalle con historial consistente
- Productos: busqueda por codigo, edicion basica, pesables y cambio de estado
- Stock: ajuste rapido, historial reciente y visualizacion de pesables
- Proveedores: editar, ver historial/productos y probar re-vinculacion puntual o global
- Backups: crear + validar
- Diagnostico: generar paquete ZIP
- Inventario fisico: crear sesion + conteo + cerrar + aplicar ajustes

---

## Documentación histórica (v2.3.x)

Contenido recuperado desde commit 3c12bdf para no perder notas operativas.

# FLUS – Sistema de Gestión POS (PHP + MySQL)

Sistema web tipo **POS / gestión** para kioscos y comercios.

**Version:** 2.3.1  
**PHP:** 8.0+  
**Base de datos:** MySQL/MariaDB

---

## 🆕 Novedades v2.3.1 (2026-01-28)

- ✅ Correcciones de estabilidad en endpoints (p. ej. `strict_types` en acciones PHP).
- ✅ Ajustes y refactors menores en APIs: **cuenta corriente**, **inventario**, **clientes**.
- ✅ Mejoras de consistencia en exportaciones (sesión de caja / dashboard).

> Ver [CHANGELOG.md](CHANGELOG.md) para el detalle y el histórico.

---

## 🆕 Novedades v2.3.0

- ✅ **Ventas**: Historial avanzado con filtros, KPIs, gráficos y **exportación CSV**
- ✅ **Ventas**: **Autocompletado de clientes** (dropdown visual + teclado)
- ✅ **Ventas**: **Ticket público compartible** (link con token) + acciones WhatsApp/Email
- ✅ **Backups**: mejoras de robustez/UX + lock de restore

> Ver [CHANGELOG.md](CHANGELOG.md) para el detalle completo y notas de upgrade.

---

## 🧠 Arquitectura recomendada (LAN)

- **Servidor**: PC donde corre Apache/PHP + BD (y donde vive `storage/`).
- **Terminales**: PCs que ingresan por navegador vía LAN (no ejecutan PHP local).

**Importante:** funcionalidades como _ticket público_ se validan en el **servidor**, por eso secretos como `APP_SECRET` deben existir y mantenerse estables ahí.

---

## ✨ Características Principales

### 🏪 Punto de Venta (Caja)

- Carga rápida por código de barras o búsqueda
- Soporte para productos **pesables** (carnicería, fiambres, frutas)
- **Split payments** (pagos con 2 medios)
- Descuentos globales por monto o porcentaje
- Generación de ticket térmico (58mm/80mm)
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
- Mejor soporte para mantener sincronizado proveedor – productos

### 🎁 Promociones

- **NxM**: Llevás N, pagás M (ej: 3x2)
- **N° al X%**: Cada N unidades, descuento del X%
- **Combos fijos**: Productos combinados a precio especial
- Motor centralizado (PromoEngine)

### 🖥️ Multi-Terminal

- Soporte para múltiples cajas/terminales
- Sistema de locks para evitar conflictos
- Heartbeat para detectar cajas inactivas

### 👥 Usuarios & Seguridad

- RBAC (Roles y Permisos)
- CSRF protection en todos los forms
- Auditoría de acciones
- Sesiones seguras

### 📊 Reportes

- Dashboard con estadísticas
- Historial de ventas con filtros
- Exportación CSV
- Historial de caja

---

## 🚀 Instalación Rápida

### 1. Requisitos

- PHP 8.0+ (recomendado 8.1/8.2)
- MySQL/MariaDB 5.7+
- Apache con mod_rewrite (XAMPP recomendado en Windows)
- Extensiones PHP: `pdo_mysql`, `mbstring`, `openssl`

### 2. Configuración

```bash
# Clonar o descomprimir
cd /htdocs/flus

# Copiar configuración
cp src/config.example.php src/config.php

# Editar credenciales de BD
nano src/config.php
```

### 3. Base de Datos

```sql
CREATE DATABASE kiosco CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Luego importar `install.sql` y ejecutar `php scripts/migrate.php`.

### 4. Acceder

```
http://localhost/flus/public/install.php
```

---

## 📁 Estructura del Proyecto

```
flus/
├── public/              # Archivos web accesibles
│   ├── api/            # Endpoints REST
│   ├── assets/         # CSS, JS
│   ├── includes/       # PromoEngine, Controllers
│   ├── lib/            # Helpers core
│   └── partials/       # Fragmentos HTML
├── src/                # Lógica backend
│   ├── config.php      # Configuración (crear desde example)
│   ├── helpers.php     # Funciones globales
│   └── *.php           # Controllers y libs
├── storage/            # Archivos generados (backups, logs)
└── scripts/            # Scripts CLI
```

---

## 🔐 Permisos Disponibles

| Permiso                 | Descripción                                     |
| ----------------------- | ----------------------------------------------- |
| `realizar_ventas`       | Usar la caja                                    |
| `cerrar_caja`           | Cerrar sesión de caja                           |
| `editar_productos`      | ABM de productos                                |
| `editar_stock`          | Ajustar stock                                   |
| `ver_reportes`          | Ver historial de ventas                         |
| `administrar_usuarios`  | Gestionar usuarios y roles                      |
| `gestionar_backups`     | Crear/restaurar backups                         |
| `ver_diagnostico`       | Ver diagnostico y descargar paquetes de soporte |
| `administrar_config`    | Configuración del sistema                       |
| `caja_modificar_precio` | Cambiar precios en caja                         |

---

## 📡 API Endpoints

| Endpoint                                     | Método | Descripción                            |
| -------------------------------------------- | ------ | -------------------------------------- |
| `?action=buscar_producto`                    | GET    | Buscar por código                      |
| `?action=buscar_productos`                   | GET    | Buscar por nombre (autocomplete)       |
| `?action=calcular_carrito`                   | POST   | Calcular precios con promos            |
| `?action=registrar_venta`                    | POST   | Registrar venta                        |
| `?action=listar_promos_activas`              | GET    | Promociones vigentes                   |
| `?action=terminal_list`                      | GET    | Listar terminales                      |
| `ventas_api.php?action=listar_ventas`        | GET    | Listado de ventas (filtros/paginación) |
| `ventas_api.php?action=venta_preview`        | GET    | Preview de venta (modal)               |
| `ventas_api.php?action=stats`                | GET    | KPIs/series para gráficos              |
| `ventas_api.php?action=buscar_clientes`      | GET    | Autocomplete de clientes               |
| `ventas_api.php?action=ticket_publico_url`   | GET    | Generar URL/token de ticket público    |
| `ventas_api.php?action=send_ticket_whatsapp` | POST   | Preparar envío por WhatsApp (wa.me)    |
| `ventas_api.php?action=send_ticket_email`    | POST   | Envío por Email (si está habilitado)   |

---

## 🛠️ Desarrollo

### Convenciones

- PHP: `declare(strict_types=1)` en todos los archivos
- SQL: Prepared statements obligatorios
- JS: ES6+ sin transpilación
- CSS: BEM-like con prefijos por módulo

### Testing

```bash
# Verificar sintaxis PHP
find . -name "*.php" -exec php -l {} \;

# Verificar que la API responde
curl http://localhost/flus/public/api/index.php?action=health
```

---

### 🔄 Upgrade desde versiones anteriores

### Desde v2.3.0 a v2.3.1

1. **Backup recomendado** (BD + carpeta `storage/`)
2. **Reemplazar archivos**
   - Conservar `src/config.php`
   - **No pisar `storage/`** (logs, backups, uploads, locks, etc.)
3. **Migraciones**
   - Esta versión es principalmente de mantenimiento. Si tu branch incluyó cambios de BD, agregá y documentá un script `scripts/upgrade_v231.sql`.
4. **Verificación rápida**
   - Caja / Productos: búsqueda y autocompletado
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
   - Si vas a usar **ticket público**, definir un `APP_SECRET` real en el **servidor** (no usar el secreto por defecto).
     - Recomendado: persistirlo en `storage/app_secret.key` para que no cambie entre upgrades.
     - El link incluye `ts` y el token **expira**: TTL por defecto 7 días (configurable con `TICKET_TOKEN_TTL_SECONDS`).
   - Si tu instalación usa pagos mixtos: la tabla `venta_pagos` mejora la calidad de los reportes; si no existe, FLUS funciona igual (modo compat).

4. **Verificación rápida**
   - Ventas → filtrar y exportar CSV
   - Abrir preview de una venta
   - Generar link de ticket público y abrirlo (debe validar token)

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

- Entrar al sistema y probar crear/eliminar un producto que esté en un combo
- Si funciona sin errores, el upgrade fue exitoso

## 🧭 Roadmap corto (WIP)

- Licencias: en **Acerca de** mostrar plan/vencimiento/días restantes leyendo `storage/license.json` (pendiente).
- Documentar upgrade SQL por versión (un archivo por release cuando aplique).
