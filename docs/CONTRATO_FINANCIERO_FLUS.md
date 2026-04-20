# Contrato financiero FLUS

Fecha de corte: 2026-04-20

Este contrato define que tabla es fuente de verdad para cada movimiento de dinero.
El objetivo es evitar duplicaciones cuando se conecten ventas, caja, cuenta corriente,
cobranzas, recibos, compras, proveedores y tesoreria.

## Principio general

FLUS puede registrar el mismo hecho economico en mas de una tabla, pero solo una debe
ser la fuente de verdad del dominio que representa.

- Las tablas operativas describen que paso en el flujo diario.
- Las tablas documentales describen que comprobante o constancia se emitio.
- Las tablas financieras describen que dinero entro, salio o quedo pendiente.
- Las tablas de enlace deben usar claves idempotentes para no duplicar importes.

## Fuentes de verdad por dominio

| Dominio | Fuente de verdad | Rol |
| --- | --- | --- |
| Venta POS | `ventas` y `venta_items` | Operacion comercial cerrada en caja. |
| Pago de venta | `venta_pagos` | Detalle original de medios usados en una venta. |
| Caja operativa | `caja_sesiones` y `caja_movimientos` | Movimiento dentro de una sesion de caja/terminal. |
| Cuenta corriente cliente | `cuenta_corriente_movimientos` | Deuda, pagos, ajustes y reversas del cliente. |
| Cobranza comercial | `cobranzas` | Cobro real recibido desde venta, factura o cuenta corriente. |
| Aplicacion de cobranza | `cobranza_aplicaciones` | A que venta, factura, documento o movimiento CC se aplica un cobro. |
| Recibo | `documentos_comerciales` con tipo recibo y `recibo_aplicaciones` | Constancia documental de una cobranza. |
| Factura fiscal | `facturas` y eventos fiscales | Comprobante fiscal, estado ARCA, CAE y recovery. |
| Documento comercial | `documentos_comerciales` y `documento_items` | Presupuesto, remito, factura manual/documental o recibo interno. |
| Compra | `compras` y `compra_items` | Ingreso comercial de mercaderia y costo. |
| Proveedor | `proveedores` | Identidad comercial y relacion de compra. |
| Obligacion financiera | `tesoreria_obligaciones` | Compromiso de pago pendiente o vencido. |
| Movimiento financiero | `tesoreria_movimientos` | Impacto en cuenta de dinero: ingreso, egreso o transferencia. |

## Regla de no duplicacion

Cada flujo que genere registros derivados debe tener una clave idempotente.

Ejemplos:

- pago de venta: `sale:{venta_id}:{medio}:{monto}` o clave equivalente existente;
- pago de cuenta corriente: `cc:{movimiento_id}`;
- cobro de factura: `invoice:{factura_id}:{medio}:{monto}`;
- obligacion desde compra: `compra:{compra_id}`;
- pago de obligacion: `obligacion:{obligacion_id}:pago:{request_uid}`.

Si el flujo se reintenta, debe reutilizar el registro existente o fallar sin duplicar
plata.

## Flujos canonicos

### Venta contado

Fuente operativa:

1. `ventas`
2. `venta_items`
3. `venta_pagos`

Registros derivados:

1. `caja_movimientos`, si corresponde a una caja abierta.
2. `cobranzas`, como cobro comercial.
3. `cobranza_aplicaciones`, enlazando la cobranza a la venta.
4. `tesoreria_movimientos`, cuando se active la integracion financiera.

Regla:

- `venta_pagos` no reemplaza a `cobranzas`; conserva el detalle original del cobro
  POS.
- `cobranzas` no recalcula la venta; registra el cobro recibido y su aplicacion.

### Venta a cuenta corriente

Fuente operativa:

1. `ventas`
2. `venta_items`
3. `cuenta_corriente_movimientos` con tipo `CARGO`

Registros derivados:

1. Factura fiscal, si se emite.
2. Cobranzas y recibos recien cuando el cliente paga.

Regla:

- Una venta a CC no debe generar ingreso financiero hasta que exista pago real.

### Pago de cuenta corriente

Fuente de deuda:

1. `cuenta_corriente_movimientos`

Registros derivados:

1. `cobranzas`
2. `cobranza_aplicaciones`
3. recibo documental, si el esquema esta disponible.
4. `caja_movimientos`, si el pago entra por caja.
5. `tesoreria_movimientos`, cuando se active la integracion financiera.

Regla:

- La reversa de CC debe revertir el movimiento de CC y dejar trazabilidad, no editar
  el historial.

### Factura fiscal cobrada

Fuente fiscal:

1. `facturas`

Fuente de cobro:

1. `cobranzas`
2. `cobranza_aplicaciones`

Registros derivados:

1. recibo documental;
2. caja, si entra por sesion activa;
3. tesoreria, si se selecciona cuenta financiera.

Regla:

- Estado fiscal y estado de cobro son conceptos separados.
- Una factura puede estar fiscalmente emitida y financieramente pendiente.

### Compra confirmada

Fuente comercial:

1. `compras`
2. `compra_items`

Registros derivados:

1. movimientos de stock;
2. costo ultimo/historial de costo, si aplica;
3. `tesoreria_obligaciones`, si la compra queda pendiente de pago;
4. `tesoreria_movimientos`, solo si se registra pago inmediato.

Regla:

- Confirmar compra no debe implicar pago automatico salvo que el usuario lo indique.
- La obligacion de tesoreria debe poder vincularse a `compra_id` y `proveedor_id`.

### Pago a proveedor

Fuente de obligacion:

1. `tesoreria_obligaciones`

Registros derivados:

1. `tesoreria_movimientos` de tipo egreso;
2. actualizacion de estado de la obligacion;
3. resumen financiero del proveedor.

Regla:

- El pago a proveedor no debe tocar stock ni compra_items.
- Si se paga parcialmente, la obligacion queda pendiente por el saldo.

## Separaciones que no deben romperse

- Caja no es tesoreria: caja opera turnos; tesoreria mira cuentas financieras.
- Cuenta corriente no es cobranza: CC muestra deuda; cobranza muestra dinero recibido.
- Facturacion fiscal no es cobro: ARCA valida comprobantes, no confirma que se haya cobrado.
- Compra no es pago: compra ingresa mercaderia/costo; tesoreria paga obligaciones.
- Documento comercial no siempre mueve stock ni dinero: presupuesto y remito tienen reglas propias.

## Cambios de esquema sugeridos

Para conectar compras/proveedores con tesoreria sin perder trazabilidad:

- agregar a `tesoreria_obligaciones`:
  - `entidad_tipo`
  - `entidad_id`
  - `proveedor_id`
  - `compra_id`
  - `external_key`
- crear indice unico sobre `external_key`;
- crear indices por `proveedor_id` y `compra_id`.

Alternativa menor:

- usar `observaciones` y `referencia` sin cambiar esquema.

Decision recomendada:

- agregar columnas dedicadas. Evita parsear texto y permite reportes reales por proveedor.

## Orden recomendado de implementacion

1. Agregar soporte idempotente de obligaciones por compra.
2. Exponer en compras una opcion clara: "Generar deuda/proveedor en tesoreria".
3. Mostrar en proveedor el saldo/obligaciones pendientes.
4. Permitir pagar obligacion desde tesoreria con enlace a proveedor y compra.
5. Recien despues conectar cobros de ventas/facturas a tesoreria.

## Criterio de aceptacion

- El smoke sigue en verde.
- Una compra confirmada puede crear como maximo una obligacion por compra.
- Reintentar la accion no duplica deuda.
- Proveedor muestra la deuda generada por compras.
- Pagar una obligacion no altera stock ni reabre la compra.
- Los reportes de tesoreria pueden distinguir egresos manuales de pagos a proveedor.
