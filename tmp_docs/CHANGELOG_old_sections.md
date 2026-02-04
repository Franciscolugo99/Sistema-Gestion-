## [2.3.0] - 2026-01-22

### ✨ Ventas - módulo avanzado (Historial / Reportes)

- Historial de ventas con **filtros avanzados** (fecha, rango horario, estado, medio, cliente, ID).
- KPIs del período filtrado + vista de gráficos (Chart.js).
- **Exportación CSV** respetando filtros.
- Preview de venta en modal (items, totales) y acciones rápidas.

#### Ticket público compartible (link firmado)
- Nuevo `public/ticket_publico.php` para acceder a un ticket vía link con **token**.
- La API puede generar el link/token para compartir y preparar envío por WhatsApp/Email.
- El link incluye `ts` y el token **expira**: TTL por defecto 7 días (configurable con `TICKET_TOKEN_TTL_SECONDS`).
- **Consideración**: definir un `APP_SECRET` propio en el servidor (evitar secreto por defecto).
  - Recomendado persistirlo en `storage/app_secret.key` para que no cambie en upgrades.

#### Autocompletado de clientes en Ventas
- Nuevo dropdown con estilo FLUS (CSS dedicado) + navegación por teclado.
- Búsqueda con debounce para evitar saturar el servidor.
- Mejora UX: permite seleccionar cliente sin conocer el `cliente_id`.

### 🧰 Backups - robustez y UX
- Ajustes en pantalla de backups y librería de restore.
- Archivo `storage/restore.lock` para evitar restores simultáneos.
  - **NO commitear** este archivo (agregar a `.gitignore`).

### 📁 Archivos modificados / nuevos

| Archivo | Cambio |
|---------|--------|
| `public/ventas.php` | Filtros, KPIs, export, integración de autocomplete |
| `public/assets/js/ventas.js` | UX/preview + mejoras de seguridad (escape HTML) |
| `public/assets/css/ventas.css` | Ajustes visuales |
| `public/assets/css/ventas-autocomplete.css` | ✨ Nuevo (UI autocomplete) |
| `public/api/ventas_api.php` | Reportes/stats + acciones (ticket/whatsapp/email) |
| `public/ticket_publico.php` | ✨ Nuevo (ticket con token) |
| `public/backups.php` | Ajustes |
| `public/assets/js/backups.js` | Ajustes |
| `public/assets/css/backups.css` | Ajustes |
| `src/backup_lib.php` | Ajustes |
| `public/api/index.php` | Ajustes menores |
| `public/bootstrap.php` | Ajustes menores |

---
## [2.2.5] - 2026-01-16

### ✨ Autocompletado Visual en Caja

#### Nuevo dropdown de sugerencias
- **Antes**: Usaba `<datalist>` HTML nativo (limitado, depende del navegador)
- **Ahora**: Dropdown visual personalizado con estilo del sistema

#### Características del nuevo autocompletado:
- 📝 Muestra **nombre**, código, stock y precio de cada producto
- ⌨️ Navegación con **flechas ↑↓** y selección con **Enter**
- 🖱️ Click o hover para seleccionar
- 🔍 Busca a partir de **2 caracteres**
- ⚡ Debounce de 150ms para no saturar el servidor

#### Ejemplo de uso:
1. Escribir "coc" → Aparece dropdown con:
   - Coca Cola 500ml - Código: 123 - Stock: 50 - $1500.00
   - Coca Cola 1.5L - Código: 124 - Stock: 30 - $2500.00
2. Seleccionar con mouse o flechas + Enter
3. Se agrega automáticamente al ticket

### 📁 Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `public/assets/js/caja.js` | Nuevo sistema de autocompletado visual |
| `src/version.php` | Actualizado a v2.2.5 |

---

## [2.2.4] - 2026-01-16

### ✨ Mejora de Búsqueda en Caja

#### Buscar producto por nombre (no solo código)
- **Problema**: Al escribir "coca" o "Agua Graciani" y dar Enter, devolvía "Producto no encontrado"
- El endpoint `buscar_producto` solo buscaba por código exacto (`WHERE codigo = :cod`)
- **Resultado**: No se podía agregar productos escribiendo el nombre

#### Solución
El endpoint ahora busca en este orden de prioridad:
1. Código exacto
2. Nombre exacto  
3. Código o nombre parcial (LIKE) - toma el más relevante

**Ejemplo**: Escribir "coca" ahora encuentra "Coca Cola 500ml" aunque el código sea "7790895000515"

### 📁 Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `public/api/index.php` | Mejorado endpoint `buscar_producto` |
| `src/version.php` | Actualizado a v2.2.4 |

---

## [2.2.3] - 2026-01-16

### 🔴 Corrección Crítica

#### Error "Cannot redeclare json_ok()"
- **Problema**: Conflicto de funciones duplicadas entre `api_helpers.php` y otros archivos
- `buscar_productos.php` y `stock_ajax.php` definían `json_ok()` que ya existía en `api_helpers.php`
- **Resultado**: Error 500 "Cannot redeclare json_ok()" al buscar productos

#### Solución
- `buscar_productos.php`: Agregado check `if (!function_exists('json_ok'))` 
- `stock_ajax.php`: Renombradas funciones a `stock_json_ok()` / `stock_json_fail()` (usa formato diferente: `success` en vez de `ok`)

### 📁 Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `public/api/actions/buscar_productos.php` | Fix redefinición json_ok |
| `public/stock_ajax.php` | Renombradas funciones locales |
| `src/version.php` | Actualizado a v2.2.3 |

---

## [2.2.2] - 2026-01-16

### 🔴 Corrección Crítica

#### Autocompletado de productos en Caja
- **Problema**: El endpoint `buscar_productos` (plural) no existía en la API
- El autocompletado en caja usaba `action=buscar_productos` pero solo existía `buscar_producto` (singular)
- **Resultado**: No aparecían sugerencias al escribir nombre/código de producto

#### Solución
- Agregado nuevo endpoint `buscar_productos` en `api/index.php`
- Busca por código o nombre parcial (LIKE)
- Ordenamiento por relevancia (código exacto primero)
- Límite configurable (default 10, max 20)

### 📁 Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `public/api/index.php` | Agregado endpoint `buscar_productos` |
| `src/version.php` | Actualizado a v2.2.2 |

---

## [2.2.1] - 2026-01-16

### ✨ Mejoras

#### 1. Validación de Formularios de Usuario
- **Nuevo archivo `public/assets/js/usuario_form.js`**
- Validación client-side con mensajes en español
- Toggle de contraseña (mostrar/ocultar) 
- Limpieza de errores en tiempo real
- Compatible con `usuario_nuevo.php` y `usuario_editar.php`

#### 2. Fix Permiso de Stock
- Corregido permiso en menú: `ver_stock` → `editar_stock`
- Ahora consistente con la funcionalidad real de la página

### 📁 Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `public/assets/js/usuario_form.js` | ✨ NUEVO |
| `public/index.php` | Fix permiso stock |
| `src/version.php` | Actualizado a v2.2.1 |

---

## [2.2.0] - 2026-01-16

### 🏗️ Refactorización Mayor

#### 1. Helpers API Centralizados
- **Nuevo archivo `src/api_helpers.php`**: Funciones compartidas para todas las APIs
- Incluye: `json_ok()`, `json_fail()`, `json_error()`, `json_response()`
- Incluye: `parse_num()`, `norm_medio_pago()`, helpers de DB
- Incluye: `setup_api_error_handlers()` para configurar exception handlers
- **Resultado**: Eliminada duplicación de código en 4 archivos

#### 2. Limpieza de Código Muerto
- ❌ Eliminada carpeta `views/` (era redundante, solo hacía require de `public/partials/`)
- ❌ Eliminado archivo `d` (output de grep commiteado por error)

#### 3. API Principal Simplificada
- `public/api/index.php` ahora usa helpers centralizados
- Reducidas ~100 líneas de código duplicado
- Mejor mantenibilidad

### 🔧 Correcciones de Base de Datos

#### 4. Script de Upgrade SQL
- **Nuevo archivo `scripts/upgrade_v220.sql`**
- Fix crítico: Foreign Key `promo_combo_items.producto_id` ahora tiene `ON DELETE CASCADE`
- Índices de optimización para ventas, movimientos y promos
- Verificaciones automáticas post-upgrade

### 📁 Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `src/api_helpers.php` | ✨ NUEVO - Helpers centralizados |
| `src/version.php` | Actualizado a v2.2.0 |
| `public/api/index.php` | Refactorizado para usar api_helpers.php |
| `scripts/upgrade_v220.sql` | ✨ NUEVO - Script de upgrade DB |
| `views/` | ❌ ELIMINADO |
| `d` | ❌ ELIMINADO |

### ⚠️ Instrucciones de Upgrade

1. Hacer backup de la base de datos
2. Reemplazar archivos del sistema
3. Ejecutar `scripts/upgrade_v220.sql` en phpMyAdmin

### 📊 Métricas de la Refactorización

| Métrica | Antes | Después |
|---------|-------|---------|
| Definiciones de json_ok/json_fail | 4 | 1 (centralizada) |
| Carpeta views/ | Existía | Eliminada |
| Archivos basura | 1 | 0 |

---

## [2.1.3] - 2026-01-10

### 🔴 Corrección Crítica

#### Inconsistencia de permisos entre calcular_carrito y registrar_venta

**Problema v2.1.2:**
- `registrar_venta` anulaba silenciosamente `desc_global` si el usuario no tenía permiso
- `calcular_carrito` lo aplicaba siempre, sin verificar permiso
- Resultado: El sync mostraba un total con descuento, pero al registrar la venta el descuento desaparecía → "Pago insuficiente"

**Solución v2.1.3:**
1. **Misma validación en ambos endpoints**: Si viene `desc_global` sin permiso `caja_modificar_precio` → Error 403 (no anular silencioso)
2. **Frontend no envía lo que no puede usar**: Solo envía `desc_global` si tiene permiso
3. **Triple capa de protección**:
   - UI: Botón deshabilitado si no tiene permiso
   - JS: No envía `desc_global` en sync si no tiene permiso
   - PHP: Devuelve error 403 si llega `desc_global` sin permiso

**Código consistente:**
```php
// calcular_carrito Y registrar_venta:
if ($descGlobalReq !== null && !$puedeCambiarPrecio) {
  json_fail('No tiene permiso para aplicar descuentos', 403);
}
```

### 📁 Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `public/api/index.php` | Validación de permiso idéntica en ambos endpoints |
| `public/assets/js/caja.js` | Solo envía desc_global si CAN_MOD_PRECIO |

---

## [2.1.2] - 2026-01-10 (SUPERSEDED - Inconsistencia de permisos)

### 🔴 Correcciones Críticas

#### 1. Server-sync ahora incluye desc_global
- **Problema v2.1.1**: El frontend no enviaba `desc_global` al sincronizar, el server devolvía total sin descuento global
- **Solución**: `sincronizarCarritoConServidor()` ahora envía `desc_global` y el backend lo aplica
- **Resultado**: Total verificado = Total con descuento global aplicado

#### 2. Server-sync ahora respeta precio manual
- **Problema v2.1.1**: El backend forzaba `precio_actual = precio_lista` ignorando cambios manuales
- **Solución**: El frontend envía `precio` y el backend lo respeta si tiene permiso `caja_modificar_precio`
- **Resultado**: Precio manual se verifica correctamente

#### 3. Condición de carrera corregida
- **Problema v2.1.1**: Si había sync en background, `cobrar()` recibía `null` y usaba total local
- **Solución**: Sync forzado ahora espera hasta 2 segundos si hay sync en curso
- **Resultado**: `cobrar()` siempre obtiene total del servidor

#### 4. BUG FATAL en logout corregido
- **Problema**: `terminal_cookie_id()` no existe, `terminal_lock_release()` recibía 4 params (acepta 3)
- **Solución**: Removida llamada a función inexistente, corregida firma de `terminal_lock_release`
- **Resultado**: Logout funciona, terminal locks se liberan correctamente

### 🔒 Seguridad

#### 5. CSRF en endpoints de usuarios/roles
- Agregado CSRF a `rol_eliminar.php`, `usuario_eliminar.php`, `usuario_toggle_estado.php`
- Estos endpoints modifican datos sensibles y estaban expuestos

### 🛠️ Mejoras Arquitecturales

#### 6. Librerías sin side-effects
- `caja_lib.php` y `promos_logic.php` ya no incluyen `bootstrap.php` completo
- Usan guard `APP_BOOTSTRAPPED` para cargar solo dependencias mínimas
- **Resultado**: APIs no reciben HTML inesperado cuando la DB falla

### 📁 Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `public/logout.php` | Fix llamadas a funciones inexistentes |
| `public/assets/js/caja.js` | Envía desc_global y precio, fix condición de carrera |
| `public/api/index.php` | calcular_carrito acepta desc_global y precio manual |
| `public/caja_lib.php` | No incluye bootstrap.php |
| `public/promos_logic.php` | No incluye bootstrap.php |
| `public/api/rol_eliminar.php` | +CSRF |
| `public/api/usuario_eliminar.php` | +CSRF |
| `public/api/usuario_toggle_estado.php` | +CSRF |

### ⚠️ Notas sobre el estado actual

**APIs que aún existen pero podrían consolidarse:**
- `ventas_api.php` - Funcional, separado del switch principal
- Los 3 endpoints de usuarios/roles - Ahora con CSRF

**Lo que NO cambió (funciona como está):**
- Motor de cálculo `calcular_totales_con_promos` - Único motor, consistente
- PromoEngine - Se usa internamente por el motor de cálculo

---

## [2.1.1] - 2026-01-10 (SUPERSEDED - Tenía bugs en sync)

### 🔴 Correcciones Críticas

#### Unificación de Lógica de Precios (FIX CRÍTICO)
- **Nuevo endpoint `calcular_carrito`**: El frontend ahora puede consultar al servidor los precios exactos calculados con PromoEngine
- **Sincronización antes de cobrar**: La función `cobrar()` ahora SIEMPRE sincroniza con el servidor antes de procesar la venta
- **Nueva función `sincronizarCarritoConServidor()`**: Permite al frontend obtener precios calculados por el backend
- **Eliminación de riesgo de negocio**: Ya no hay posibilidad de que el cliente vea un precio y pague otro

### 🗑️ Código Eliminado (Limpieza)

Se eliminaron 10 archivos de código muerto/legacy:

- `/d` - Archivo basura (output de grep commiteado por error)
- `public/api/api.php` - Deprecado, solo redirigía
- `public/api/promos_api.php` - Deprecado
- `public/api/terminal_heartbeat.php` - Deprecado
- `public/api/terminal_list.php` - Deprecado
- `public/api/terminal_select.php` - Deprecado
- `public/api/terminal_status.php` - Deprecado
- `public/api/terminal_switch.php` - Deprecado
- `/views/` - Carpeta duplicada (las vistas reales están en `public/partials/`)

### 🔒 Mejoras de Seguridad

#### test_backup.php protegido
- Ahora requiere autenticación (`require_login()`)
- Requiere permiso `gestionar_backups`
- Ya no expone información del sistema a usuarios no autorizados

#### config.example.php mejorado
- Uso de constantes `define()` en lugar de variables globales
- Conexión PDO singleton (evita múltiples conexiones)
- Agregada constante `APP_DEBUG` para controlar información de debug
- Agregadas constantes `APP_NAME` y `APP_VERSION`

### 📁 Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `src/config.example.php` | Refactorizado con constantes y singleton |
| `public/test_backup.php` | Agregada protección de autenticación |
| `public/api/index.php` | Agregado endpoint `calcular_carrito` |
| `public/assets/js/caja.js` | Agregada sincronización con servidor |

### 🔧 Cambios Técnicos

#### Nuevo endpoint API: `calcular_carrito`
```
POST /api/index.php?action=calcular_carrito

Request:
{
  "csrf_token": "...",
  "items": [
    {"id": 123, "cantidad": 2},
    {"id": 456, "cantidad": 1.5}
  ]
}

Response:
{
  "ok": true,
  "items": [...],
  "total_bruto": 1500.00,
  "total_neto": 1350.00,
  "descuento_total": 150.00
}
```

#### Nueva función JavaScript: `sincronizarCarritoConServidor()`
- Llama al endpoint `calcular_carrito`
- Actualiza el carrito local con los precios del servidor
- Se ejecuta automáticamente antes de cada cobro
- Tiene debounce para evitar llamadas excesivas

### 📊 Métricas de la Limpieza

| Antes | Después | Reducción |
|-------|---------|-----------|
| 95 archivos PHP | 87 archivos PHP | -8 archivos |
| Lógica duplicada | Centralizada | ✅ Riesgo eliminado |
| 8 APIs deprecadas | 0 | -100% |

---

## [2.0.0] - Versión anterior

- Sistema base con PromoEngine
- Multi-terminal con locks
- Split payments
- Dashboard con estadísticas
