# Reglas de precio y trazabilidad 4.1.0

## Objetivo

FLUS guarda el precio realmente cobrado en ventas y reportes, pero desde 4.1.0 tambien conserva la trazabilidad del ajuste automatico que formo ese precio.

La regla actual de recargo horario queda modelada como una regla comercial generica. Esto evita encerrar el sistema en negocios 24 hs y deja preparada la base para ferreterias, ropa, dieteticas, mayoristas y otros rubros.

## Criterio operativo

- `ventas.total` sigue representando el importe final cobrado.
- `venta_items.subtotal` sigue representando el importe final de la linea.
- Panel, graficos, analisis de ventas, rotacion y ABC siguen leyendo importes cobrados reales.
- Inventario base sigue usando `productos.precio` para valuar stock actual. El ajuste es condicion de venta, no cambio permanente de lista.

## Campos nuevos

En `ventas`:

- `ajuste_precio_aplicado`: indica si la venta tuvo algun ajuste automatico.
- `ajuste_precio_total`: suma del ajuste automatico aplicado a la venta.
- `ajuste_precio_redondeo_total`: parte del ajuste total que corresponde solo al redondeo.

En `venta_items`:

- `precio_unit_base`: precio base del producto al momento de vender.
- `ajuste_precio_tipo`: tipo generico, por ejemplo `recargo`.
- `ajuste_precio_origen`: origen de la regla, por ejemplo `horario`.
- `ajuste_precio_nombre`: nombre visible de la regla.
- `ajuste_precio_pct`: porcentaje aplicado cuando corresponde.
- `ajuste_precio_unit_monto`: monto del ajuste por unidad.
- `ajuste_precio_total`: monto total del ajuste para la linea.
- `ajuste_precio_regla_unit_monto`: monto por unidad generado por la regla porcentual antes de redondear.
- `ajuste_precio_redondeo_modo`: modo de redondeo aplicado, si corresponde.
- `ajuste_precio_redondeo_unit_monto`: diferencia por unidad agregada por el redondeo.
- `ajuste_precio_redondeo_total`: diferencia total de la linea generada por el redondeo.

## Redondeo operativo

El redondeo es opcional y se aplica despues del porcentaje automatico. Sirve para que caja no tenga que cobrar importes incomodos como `$1.357,40`.

Modos disponibles:

- Sin redondeo.
- Hacia arriba a `$10`.
- Hacia arriba a `$50`.
- Hacia arriba a `$100`.
- Psicologico a la siguiente terminacion `90`.

El redondeo siempre va hacia arriba para no cobrar menos que la regla configurada. Si el negocio quiere evitar diferencias por redondeo, debe dejarlo en `Sin redondeo`.

## Ejemplo

Producto con precio base `$1.234`, regla horaria `+10%` y redondeo hacia arriba a `$10`:

- precio por regla: `$1.357,40`
- precio final cobrado: `$1.360,00`
- `precio_unit_base`: `$1.234,00`
- `precio_unit_original`: `$1.360,00`
- `precio_unit_final`: `$1.360,00` si no hubo descuento/promocion posterior
- `ajuste_precio_tipo`: `recargo`
- `ajuste_precio_origen`: `horario`
- `ajuste_precio_pct`: `10.000`
- `ajuste_precio_regla_unit_monto`: `$123,40`
- `ajuste_precio_redondeo_unit_monto`: `$2,60`
- `ajuste_precio_unit_monto`: `$126,00`

## Decisiones de diseno

- No se recalculan ventas viejas.
- No se modifica el total historico de ventas.
- No se cambia el precio de inventario por una regla temporal.
- La trazabilidad queda en columnas opcionales para mantener compatibilidad con instalaciones sin migrar.
- El detalle de venta muestra una nota discreta cuando un item tuvo ajuste automatico.
- Si un usuario autorizado pisa el precio manualmente, el ajuste automatico no se atribuye a esa linea para evitar auditoria falsa.
