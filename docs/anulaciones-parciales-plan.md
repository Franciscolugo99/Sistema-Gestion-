# Anulaciones parciales y Notas de Credito

## Objetivo

Implementar anulaciones parciales en FLUS sin romper:

- stock
- cuenta corriente
- trazabilidad de ventas
- trazabilidad fiscal ARCA

La estrategia acordada es avanzar por fases:

1. Track no fiscal
2. Track fiscal total
3. Track fiscal parcial

## Estado actual

- `public/api/actions/anular_venta.php` ya resuelve bien la anulacion total:
  - transaccion
  - idempotencia
  - reposicion total de stock
  - reversa de cuenta corriente
- No existe anulacion por items.
- No existen tablas de anulaciones parciales.
- No existe flujo de Nota de Credito ARCA.

## Revision del avance externo

El material recibido sirve como base, pero no puede entrar tal cual. Estos son los puntos a corregir antes de mergear codigo.

### 1. La migracion propuesta no coincide con el esquema real de permisos

El SQL recibido inserta en `permissions` usando columnas `name`, `label` y `description`.

En FLUS hoy la tabla usa:

- `nombre`
- `slug`
- `created_at`

Eso hace que la migracion falle en instalaciones reales.

### 2. La NC no debe colgar de `venta_fiscal`

El avance agrega columnas `nc_*` en `venta_fiscal`.

Eso no escala si una misma venta termina con:

- varias anulaciones parciales
- varias NC asociadas a la misma factura original

La trazabilidad fiscal tiene que vivir al nivel del evento de anulacion, no al nivel de la venta completa.

### 3. El flujo asumido permite una sola NC por factura

El avance valida:

- `SELECT id FROM facturas WHERE nc_de_factura_id = ? LIMIT 1`

Con eso se bloquea:

- parcial + parcial
- parcial + total posterior

Ese modelo sirve solo para una unica NC por factura original.

### 4. No conviene marcar la factura original como `ANULADA`

En el flujo fiscal, la factura original sigue existiendo como comprobante emitido.
La compensacion ocurre por una Nota de Credito asociada.

Cambiar el estado local de la factura original a `ANULADA`:

- distorsiona la trazabilidad
- complica vistas e impresion
- mezcla anulacion comercial con compensacion fiscal

La venta puede quedar anulada comercialmente. La factura original no deberia desaparecer ni mutar semantica.

### 5. No conviene mutar `ventas.total` en cada anulacion parcial

Cambiar el total original de la venta rompe la foto historica de la operacion.

Eso impacta:

- auditoria
- reportes
- conciliacion con factura original
- futuras NC

La recomendacion es mantener el total original inmutable y calcular el afectado desde `venta_anulaciones`.

### 6. El flujo fiscal recibido no es realmente seguro despues de ARCA

El comentario dice:

- si ARCA aprueba y luego falla DB, la NC debe persistirse igual

Pero el codigo usa una sola transaccion y hace rollback completo si algo falla despues.

Riesgo:

- ARCA aprueba
- la DB local revierte
- FLUS pierde la referencia local a una NC real ya emitida

Eso es un problema critico de integridad.

### 7. El endpoint fiscal parcial no reutiliza toda la logica robusta de anulacion actual

En el avance, la ruta fiscal parcial:

- no aplica reversa de CC completa
- no registra siempre movimientos de stock
- no valida correctamente anulaciones previas para evitar sobre-anular
- recalcula de forma simplificada

La parte mas robusta hoy sigue estando en `anular_venta.php`.

### 8. El calculo fiscal parcial fija IVA 21% por defecto

El avance arma items de NC parcial con `iva_porcentaje = 21.0`.

FLUS ya tiene logica para:

- IVA por producto
- facturas A/B/C
- calculos desde `venta_items` y `factura_manual_items`

Hardcodear 21% es incorrecto para ventas con otra alicuota.

## Decision de arquitectura

### Regla principal

Separar siempre:

- anulacion comercial
- anulacion fiscal

### Entidad central

`venta_anulaciones` debe ser la entidad principal del evento.

Cada anulacion debe poder guardar:

- venta origen
- tipo
- estado
- motivo
- usuario
- montos afectados
- referencia fiscal si existe
- errores fiscales si existieron

### Estado recomendado para anulaciones

Estados sugeridos para `venta_anulaciones.estado`:

- `CONFIRMADA`
- `FISCAL_PENDIENTE`
- `FISCAL_RECHAZADA`
- `CANCELADA`

Para fase 1 alcanza con `CONFIRMADA`.

### Estado recomendado para ventas

`ventas.estado` puede seguir usando:

- `EMITIDA`
- `PARCIALMENTE_ANULADA`
- `ANULADA`

Pero el estado real debe derivarse de cantidades anuladas, no de un simple flag sin respaldo.

## Fase 1 recomendada: solo track no fiscal

### Alcance

- ventas sin factura
- anulacion parcial por items
- reposicion parcial de stock
- reversa proporcional de CC
- auditoria completa

### Cambios de datos recomendados

Crear `venta_anulaciones`:

- `id`
- `venta_id`
- `tipo` (`TOTAL` o `PARCIAL`)
- `estado`
- `motivo`
- `anulado_por`
- `anulado_en`
- `monto_bruto`
- `monto_neto`
- `monto_iva`
- `monto_total`

Crear `venta_anulacion_items`:

- `id`
- `anulacion_id`
- `venta_item_id`
- `producto_id`
- `cantidad_anulada`
- `precio_unitario_snapshot`
- `subtotal_snapshot`
- `iva_porcentaje_snapshot`
- `subtotal_anulado`

Agregar permiso:

- `anular_items_venta`

### Reglas de negocio

- No permitir anulacion parcial sobre ventas facturadas en fase 1.
- Validar en transaccion que la suma historica anulada por item no supere la cantidad original.
- Reponer solo el stock afectado.
- Revertir solo la parte proporcional de CC.
- Registrar movimientos de stock por item.
- Mantener trazabilidad del motivo y usuario.

### Recomendacion sobre total de venta

Preferido:

- no mutar `ventas.total`

Alternativa temporal solo si simplifica demasiado la UI actual:

- mutarlo solo en track no fiscal

La opcion preferida sigue siendo conservar el total original.

## Fase 2 recomendada: NC total fiscal

### Orden obligatorio

1. Preparar importes de NC
2. Emitir NC ante ARCA
3. Persistir comprobante fiscal local sin perder trazabilidad
4. Recién despues aplicar efectos comerciales

### Regla critica

Si ARCA rechaza:

- no tocar venta
- no tocar stock
- no tocar CC

### Regla critica post-ARCA

Si ARCA aprueba y luego falla persistencia o post-proceso:

- no perder el registro local de la NC emitida
- dejar la anulacion en estado recuperable

## Fase 3 recomendada: NC parcial fiscal

Esta fase recien deberia arrancar cuando:

- fase 1 este estable en produccion
- fase 2 este estable en homologacion y luego en produccion

Es la fase con mas riesgo por:

- prorrateos
- IVA por item
- redondeos
- multiples NC por factura

## Proximo paso sugerido

El siguiente entregable seguro es:

1. corregir el modelo de datos para fase 1
2. implementar solo `anular_items_venta.php` para ventas no facturadas
3. integrar el modal en `venta_detalle.php`
4. dejar el track fiscal explicitamente fuera del primer merge

## Archivos del repo a tocar primero

- `migrations/010_anulaciones_parciales.sql`
- `public/api/actions/anular_items_venta.php`
- `public/venta_detalle.php`
- `public/assets/js/venta_anular_items.js`
- `public/rol_permisos.php`

## Archivos a no tocar todavia

- `public/api/actions/emitir_nota_credito.php`
- `src/facturacion_nc_lib.php`
- `public/includes/ArcaWsfe.php`
- `public/factura_ver.php`

Esos archivos conviene abordarlos en una segunda etapa, con diseño fiscal ya cerrado.
