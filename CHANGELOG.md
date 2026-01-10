# CHANGELOG - FLUS

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
