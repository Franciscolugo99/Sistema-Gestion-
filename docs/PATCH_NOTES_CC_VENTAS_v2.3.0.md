# PATCH NOTES - Integración Cuenta Corriente con Ventas v2.3.0

## Fecha: 2025-02-02

## Resumen

Esta actualización implementa la separación correcta entre "venta" (generación de ingreso) y "cobro" (entrada de dinero real) para las ventas a cuenta corriente.

**Problema resuelto:** Anteriormente, las ventas a CC sumaban como "efectivo" en caja, inflando los totales aunque ese dinero no existiera hasta que el cliente pagara.

**Solución:** Ahora una venta a CC crea deuda pero NO aumenta efectivo en caja. El dinero entra a caja exclusivamente cuando se registra un pago de CC (parcial o total).

---

## Cambios Principales

### 1. Registro de Ventas (api/index.php)

- **Pagos mixtos mejorados:** Ahora se pueden combinar pagos en efectivo, tarjeta, MP, etc. con Cuenta Corriente en la misma venta
- **Separación CC/Caja:** Los montos pagados a CC NO se registran como movimientos de caja
- **Nuevo campo `monto_cc`:** Las ventas ahora guardan cuánto se cargó a cuenta corriente
- **Validación de CC:** Se verifica disponibilidad de crédito antes de confirmar la venta
- **Registro automático de CARGO:** Al vender a CC, se crea automáticamente el movimiento de cuenta corriente
- **Nuevos endpoints:** `buscar_clientes_cc` y `verificar_cc` para autocompletado y validación

### 2. Cobro de Cuenta Corriente (CuentaCorrienteController)

- **Registro en caja:** Al cobrar un pago de CC desde caja, ahora se registra correctamente el INGRESO en `caja_movimientos`
- **Actualización de totales:** Los totales de caja se actualizan con el medio de pago usado (efectivo, transferencia, débito, crédito, MP)
- **Múltiples medios:** Se puede cobrar CC con cualquier medio de pago (efectivo, transferencia, débito, crédito, MP)
- **Validación de sobrepago:** No se permite pagar más de lo que el cliente debe

### 3. Interfaz de Caja (caja.php)

- **Botón "Cobrar CC":** Nuevo botón en la barra de acciones para cobrar deudas de clientes
- **Modal mejorado:** Interface para buscar cliente, ver su deuda y registrar el pago
- **Múltiples medios:** El modal permite seleccionar el medio de pago (efectivo, transferencia, MP, débito, crédito)
- **Autocompletado de monto:** Al seleccionar cliente, se autocompleta con su saldo completo

### 4. Modelo de Datos (Migración 004)

Nuevas columnas:
- `ventas.cliente_id` - FK al cliente (para CC y facturación)
- `ventas.monto_cc` - Monto cargado a CC (que no entró a caja)
- `cuenta_corriente_movimientos.autorizado_por` - Usuario que autorizó exceder límite
- `cuenta_corriente_movimientos.caja_movimiento_id` - Referencia al movimiento de caja generado

Nueva tabla:
- `venta_pagos` - Registra pagos individuales de cada venta (para pagos mixtos)

Nueva vista:
- `v_ventas_medios_reales` - Facilita reportes separando ventas contado/mixto/CC

---

## Cómo Ejecutar la Migración

```bash
# Desde línea de comandos
php scripts/migrate_004_cc_ventas.php

# O desde navegador
https://tu-sitio.com/scripts/migrate_004_cc_ventas.php
```

---

## Regla Contable Central

> "Una venta a CC no debe aumentar efectivo ni medios de caja; solo crea deuda. El dinero entra a caja exclusivamente cuando se registra un pago de CC (parcial o total), y eso debe reflejarse en caja, cierres y KPIs."

### Ejemplo de Flujo

1. **Venta mixta $10.000** (paga $4.000 efectivo + $6.000 CC)
   - Caja suma: $4.000 efectivo
   - CC cliente sube: $6.000
   - `ventas.monto_cc` = 6.000

2. **Cliente paga $3.000 de su deuda** (transferencia)
   - CC cliente baja: $3.000
   - Caja suma: $3.000 transferencia (ingreso)
   - Se crea `caja_movimientos` tipo INGRESO

3. **Cliente paga el resto $3.000** (efectivo)
   - CC cliente queda en: $0
   - Caja suma: $3.000 efectivo (ingreso)

---

## Archivos Modificados

```
public/api/index.php                           # Lógica de registrar_venta con CC + endpoints buscar_clientes_cc y verificar_cc
public/api/cuenta_corriente_api.php            # Soporte para múltiples medios de pago en cobro CC
public/includes/CuentaCorrienteController.php  # registrarPago con movimientos de caja
public/caja.php                                # Botón y modal de Cobrar CC
public/assets/js/caja.js                       # Lógica de venta con CC
public/assets/js/caja_cc_pago.js               # JS del modal de cobro CC (múltiples medios)
src/api_helpers.php                            # norm_medio_pago ya soportaba CC
migrations/004_cc_ventas_integration.sql       # SQL de migración
scripts/migrate_004_cc_ventas.php              # Script de migración PHP
```

---

## Casos de Prueba

| Escenario | Esperado |
|-----------|----------|
| Venta 100% CC ($10.000) | cc_saldo += 10.000, caja NO suma nada |
| Venta mixta ($4.000 ef + $6.000 CC) | caja += 4.000, cc_saldo += 6.000 |
| Pago parcial CC ($2.000 transferencia) | cc_saldo -= 2.000, caja += 2.000 |
| Pago total CC ($4.000 efectivo) | cc_saldo = 0, caja += 4.000 |
| Sobrepago CC (saldo 0, paga 1) | Error, no permite |
| Venta excede límite sin permiso | Error, no permite |
| Venta excede límite con permiso | OK, se guarda autorizado_por |

---

## Consideraciones

### Datos Históricos

Las ventas existentes NO se modifican. Si anteriormente se registraron ventas a CC como efectivo, quedarán así. Se recomienda:

1. **Opción A (recomendada):** Aplicar el cambio solo a nuevas ventas
2. **Opción B:** Script de reconciliación para convertir ventas "fiadas" a CC (hacer con backup)

### Permisos

Se utilizan los permisos existentes:
- `registrar_cargo_cc` - Para vender a cuenta corriente
- `registrar_pago_cc` - Para cobrar deudas de CC
- `vender_excedido_cc` - Para autorizar ventas que excedan el límite
- `ver_cuenta_corriente` - Para acceder a datos de CC de clientes

---

## Fixes de Seguridad y Estabilidad (v2.3.1 - 2025-02-03)

### P0 - Bugs Críticos Corregidos

#### 1. Transacciones Anidadas (PDO)
**Problema:** `registrarCargo()` y `registrarPago()` hacían `beginTransaction()` siempre, pero podían ser llamados desde `registrar_venta` que ya tenía una transacción activa. Esto causaba error "There is already an active transaction" y rompía todas las ventas con CC.

**Fix:** Implementado patrón de transacción externa:
```php
$ownTransaction = !$this->pdo->inTransaction();
if ($ownTransaction) $this->pdo->beginTransaction();
// ... operaciones ...
if ($ownTransaction) $this->pdo->commit();
```

#### 2. CSRF Desactivado en Ventas
**Problema:** La acción `registrar_venta` no validaba token CSRF, exponiendo a ataques cross-site request forgery.

**Fix:** Activada validación CSRF obligatoria:
```php
require_csrf_json($body);  // ✅ CSRF obligatorio
```

### P1 - Mejoras de Seguridad

#### 3. Endpoints CC Sin Permisos
**Problema:** Los endpoints `buscar_clientes_cc` y `verificar_cc` solo verificaban login, pero exponían datos sensibles de CC a cualquier usuario logueado.

**Fix:** Ahora requieren al menos uno de estos permisos:
- `registrar_cargo_cc`
- `registrar_pago_cc`
- `ver_cuenta_corriente`

### P1 - Mejoras en Reportes

#### 4. Listado de Ventas con CC
**Cambios en ventas.php:**
- Nueva columna "CC" muestra monto cargado a cuenta corriente
- Badge `CC-FULL` (naranja) para ventas 100% a CC
- Badge `CC-MIXTO` (azul) para ventas parcialmente a CC
- Columna Total muestra "(X caja)" debajo en ventas mixtas
- KPIs de hoy/ayer ahora calculan:
  - `sum_hoy` / `sum_ayer` = Total facturado
  - `sum_caja_hoy` / `sum_caja_ayer` = Lo que entró a caja
  - `sum_cc_hoy` / `sum_cc_ayer` = Lo que fue a CC

---

## Arqueo Correcto - Separación de Medios de Pago (v2.3.2 - 2025-02-03)

### Problema Resuelto
TRANSFERENCIA sumaba incorrectamente a `total_efectivo`, causando:
- Arqueo de efectivo inflado (efectivo fantasma)
- Imposibilidad de conciliar caja física

### Cambios en Base de Datos

**Nueva columna `caja_movimientos.medio_pago`:**
```sql
ALTER TABLE caja_movimientos
ADD COLUMN medio_pago VARCHAR(30) DEFAULT NULL;
```
Permite identificar si un ingreso fue por EFECTIVO, TRANSFERENCIA, MP, etc.

**Nueva columna `caja_sesiones.total_transferencia`:**
```sql
ALTER TABLE caja_sesiones
ADD COLUMN total_transferencia DECIMAL(10,2) NOT NULL DEFAULT 0.00;
```
Acumula transferencias separado de efectivo.

### Mapeo Estricto de Medios → Totales

| Medio de Pago | Columna en caja_sesiones |
|--------------|-------------------------|
| EFECTIVO | total_efectivo |
| MP / MERCADOPAGO | total_mp |
| DEBITO | total_debito |
| CREDITO | total_credito |
| TRANSFERENCIA / TRANSFER | total_transferencia |
| CC | No suma a ningún total |

### Archivos Modificados

1. **api/index.php** - `update_caja_medio_delta()` corregido
2. **CuentaCorrienteController.php** - `actualizarTotalesCaja()` corregido
3. **CuentaCorrienteController.php** - `registrarPago()` ahora guarda `medio_pago` en caja_movimientos
4. **caja_cerrar.php** - Muestra y guarda `total_transferencia`
5. **caja_sesion_detalle.php** - Muestra fila de Transferencia
6. **caja_sesion_print.php** - Muestra fila de Transferencia
7. **caja_sesion_export.php** - Exporta Transferencia a CSV
8. **caja_movimientos.php** - Muestra columna Medio en listado

### Regla de Arqueo

> "Efectivo esperado" = `caja_sesiones.total_efectivo`
>
> NUNCA incluye transferencias, MP, débito ni crédito.

### Tests de Aceptación

✓ Cobro CC por TRANSFERENCIA → `total_transferencia += monto`, `total_efectivo` NO cambia
✓ Cobro CC por EFECTIVO → `total_efectivo += monto`
✓ Venta mixta (EFECTIVO + CC) → caja suma solo efectivo, CC no suma
✓ caja_movimientos guarda `medio_pago` para trazabilidad
✓ Cierre muestra todos los medios separados

---

## Fix Crítico P0 - Cierre de Caja No Pisa Totales (v2.3.3 - 2025-02-03)

### Problema Resuelto

El cierre de caja recalculaba los totales por medio desde ventas y pisaba los valores acumulados durante el turno. Esto causaba que los cobros de CC (que sí actualizaban `caja_sesiones.*` durante el turno) se perdieran al cerrar.

Además, el cálculo de "efectivo esperado" sumaba TODOS los movimientos de caja (incluyendo cobros CC por transferencia/MP) como si fueran efectivo.

### Cambios en caja_cerrar.php

#### 1. Fuente de verdad de totales
**Antes:** Se recalculaban desde ventas/venta_pagos (perdiendo cobros CC)
**Ahora:** Se usan los valores ya acumulados en `caja_sesiones` (incluyen ventas + cobros CC)

#### 2. Filtrado de movimientos manuales
**Antes:** Sumaba TODOS los movimientos como efectivo
**Ahora:** Solo suma movimientos que:
- NO son cobros de CC (`cc_movimiento_id IS NULL`)
- Son EFECTIVO o sin medio_pago asignado

```php
// Excluir cobros CC y filtrar solo efectivo
$whereMovEfectivo = "caja_id = ?";
if ($hasCCMovCol) {
  $whereMovEfectivo .= " AND (cc_movimiento_id IS NULL OR cc_movimiento_id = 0)";
}
if ($hasMedioPagoMovCol) {
  $whereMovEfectivo .= " AND (medio_pago IS NULL OR UPPER(medio_pago) = 'EFECTIVO')";
}
```

#### 3. Saldo sistema correcto
```php
// Antes (INCORRECTO)
$saldoSistema = $saldoInicial + $porMedio['EFECTIVO'] + $movIngresos - $movEgresos;

// Ahora (CORRECTO)
$saldoSistema = $saldoInicial + $totEfectivo + $movEfectivoIngresos - $movEfectivoEgresos;
```

#### 4. UPDATE no pisa totales
**Antes:** El UPDATE seteaba `total_efectivo`, `total_mp`, etc. con valores recalculados
**Ahora:** El UPDATE solo actualiza:
- `fecha_cierre`
- `saldo_sistema`
- `saldo_declarado`
- `diferencia`
- `notas`
- `total_ventas`
- `total_productos`
- `total_anulaciones`

Los totales por medio (`total_efectivo`, `total_mp`, etc.) se preservan como fueron acumulados durante el turno.

#### 5. UI mejorada
- Muestra "Total Efectivo (ventas + cobros CC)" en lugar de "Ventas en efectivo"
- Muestra movimientos manuales separados de cobros CC
- Muestra "Ventas a Cuenta Corriente" como información adicional

### Regla de Arqueo Final

> **"Efectivo esperado"** = `saldo_inicial` + `total_efectivo` (ya acumulado) + `mov_manuales_efectivo`
>
> NUNCA incluye cobros CC por transferencia/MP, esos van a sus columnas correspondientes.

---

## Soporte

Para reportar problemas o sugerencias, contactar al equipo de desarrollo.
