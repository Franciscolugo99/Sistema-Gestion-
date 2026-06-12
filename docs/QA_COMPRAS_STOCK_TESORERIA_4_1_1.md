# QA de compras, stock, tesoreria y Caja para FLUS 4.1.1

Fecha de revision: 2026-06-11

## Objetivo

Validar que los cambios de Compras mantengan una sola fuente de verdad entre:

- estado de la compra;
- stock y movimientos de inventario;
- costo vigente e historial de costos;
- deuda vinculada en Tesoreria;
- permisos de acceso;
- interfaz de carga, listado, detalle y anulacion.

Este parche agrega `migrations/040_movimientos_stock_snapshots_compat.sql` para asegurar
`movimientos_stock.stock_anterior`, `movimientos_stock.stock_nuevo` y el trigger
de snapshots en upgrades legacy. `install.sql` ya contiene esa estructura.

## Areas afectadas

### Compras

- Creacion y autosave de borradores.
- Actualizacion inmediata del listado despues del autosave.
- Confirmacion protegida por estado y bloqueo de fila.
- Carga de varios productos y cantidades decimales para pesables.
- Detalle de compra con contenido escapado.
- Anulacion con o sin reversion de stock.
- Mensajes y confirmaciones operativas.

### Stock

- Ingreso de stock al confirmar una compra.
- Un solo movimiento `COMPRA` por producto e intento valido.
- Rechazo total de la anulacion cuando un producto no tiene stock suficiente.
- Movimiento `ANULACION_COMPRA` al revertir mercaderia.
- Conservacion de cantidades con tres decimales.

### Costos

- Actualizacion del costo neto al confirmar.
- Historial de costo dentro de la misma transaccion de la compra.
- Restauracion del costo anterior al anular.
- Restauracion hasta el costo previo a la compra cuando un producto aparece en varias lineas.
- Conservacion de un costo modificado manualmente despues de la compra.
- Auditoria de cambio de costo usando la misma conexion transaccional.

### Mercado Pago y pagos divididos

- QR valida el importe asignado a `MP`, no el total completo de la venta.
- Point valida la suma asignada a `DEBITO` o `CREDITO`.
- Efectivo + MP y efectivo + Point conservan sus importes parciales.
- La contingencia manual se registra separadamente para QR y Point.
- Una misma venta no mezcla debito y credito dentro de una sola order Point.
- En modo oscuro, los montos, metodos seleccionados y resumenes de Pago 1/Pago 2 mantienen contraste legible.
- Total, Pagado y Vuelto/Resta pagar tienen un unico resumen inferior; el lateral no duplica importes.
- Pago 1 y Pago 2 no repiten metodo e importe debajo de sus controles.
- El area del ticket muestra solamente la tabla y aprovecha toda la altura disponible.
- Resumen y acciones quedan integrados como pie del ticket con divisores, no como tarjetas independientes.
- En split payment, alternar Cuenta Corriente, MP, debito, credito y efectivo no bloquea ningun monto.
- Bordes, radios, divisores y sombras de Caja mantienen una geometria continua en claro y oscuro.

### Tesoreria

- Creacion idempotente de deuda para compras confirmadas.
- Cancelacion automatica de deuda pendiente al anular la compra.
- Bloqueo de la anulacion cuando la deuda tiene pagos parciales o totales.
- Rollback conjunto de deuda, stock, costo y estado ante cualquier error.

### Permisos

- `public/compras.php` exige `editar_stock` y `ver_costos` en backend.
- La gestion de deuda exige ademas `gestionar_tesoreria`.
- Ocultar botones no reemplaza estas validaciones.

### Interfaz

- Acciones del listado sin manejadores JavaScript inline.
- Modal de detalle con foco, cierre por Escape y restauracion de foco.
- Modal de anulacion con explicacion de la reversion de stock.
- Estados de carga, error y reintento.
- Modo claro, modo oscuro y `prefers-reduced-motion`.

## Orden obligatorio de comprobacion

### 1. Base tecnica

1. Ejecutar `php -l` sobre cada PHP modificado.
2. Ejecutar `node --check` sobre los JavaScript modificados.
3. Ejecutar `php tests/smoke.php`.
4. Ejecutar `$env:FLUS_TEST_DB='1'; php tests/integration_db.php`.
5. Confirmar que `git diff --check` no informa errores.

### 2. Permisos

1. Entrar como administrador y abrir Compras.
2. Entrar como usuario sin `editar_stock` o sin `ver_costos` e intentar abrir `compras.php` por URL.
3. Confirmar que el backend rechaza el acceso.
4. Entrar como usuario con `editar_stock` y `ver_costos`, pero sin `gestionar_tesoreria`.
5. Confirmar que puede operar compras, pero no crear deuda.

### 3. Borrador y autosave

1. Crear una compra con proveedor y comprobante.
2. Agregar un producto.
3. Cambiar cantidad, costo y descuento.
4. Verificar que el autosave no duplica filas ni items.
5. Confirmar que el borrador aparece en el listado sin recargar.
6. Eliminar el borrador y verificar la confirmacion previa.

### 4. Varios productos y pesables

1. Agregar al menos un producto por unidad y uno pesable.
2. Usar una cantidad decimal, por ejemplo `1,250 kg`.
3. Aplicar descuento por item y descuento global.
4. Confirmar subtotales, total y prorrateo.
5. Guardar, volver a editar y comprobar que no se pierden decimales.

### 5. Confirmacion e idempotencia

1. Registrar stock y costo inicial de todos los productos.
2. Confirmar la compra.
3. Verificar un solo incremento de stock por producto.
4. Verificar un solo movimiento `COMPRA` por producto.
5. Verificar el costo neto y su historial.
6. Reenviar la confirmacion o usar dos pestañas.
7. Confirmar que el segundo intento se rechaza porque la compra ya no es `BORRADOR`.
8. Verificar que el stock no se duplica.

### 6. Deuda en Tesoreria

1. Crear deuda desde una compra confirmada.
2. Repetir la accion y comprobar que no se duplica.
3. Anular una compra con deuda pendiente.
4. Confirmar que la deuda pasa a `CANCELADO`.
5. Crear otra deuda y registrar un pago parcial.
6. Intentar anular la compra.
7. Confirmar que FLUS bloquea la anulacion y conserva compra, deuda, stock y costo.

### 7. Anulacion con stock

1. Anular sin marcar reversion de stock.
2. Confirmar que cambia el estado, pero el stock queda igual.
3. En otra compra, anular marcando reversion.
4. Confirmar el descuento de stock y el movimiento `ANULACION_COMPRA`.
5. Consumir parte del stock antes de anular.
6. Intentar revertir una cantidad mayor al stock disponible.
7. Confirmar que toda la operacion se rechaza sin cambios parciales.

### 8. Costos posteriores

1. Confirmar una compra que cambie el costo.
2. Cambiar luego el costo manualmente desde la herramienta correspondiente.
3. Anular la compra con reversion.
4. Confirmar que FLUS conserva el costo manual posterior.

### 9. Producto repetido con costos distintos

1. Crear una compra y agregar dos veces el mismo producto en lineas separadas.
2. Usar costos distintos, por ejemplo `$100` y `$120`.
3. Confirmar que el costo vigente queda en `$120`.
4. Anular la compra con reversion de stock.
5. Confirmar que vuelve al costo anterior a ambas lineas, no a `$100`.
6. Repetir agregando un cambio manual posterior y confirmar que ese cambio se conserva.

### 10. Mercado Pago dividido

1. En modo automatico, cobrar una venta con efectivo + MP.
2. Confirmar que la order QR usa solo el importe asignado a MP.
3. Verificar que la venta se registra con ambas lineas en `venta_pagos`.
4. Repetir con efectivo + debito o credito mediante Point.
5. Confirmar que Point recibe solo su importe parcial.
6. Simular falla de red y aceptar contingencia manual.
7. Verificar `mp_verified = 0`, `mp_origin` correcto y motivo manual.
8. Rechazar la contingencia y confirmar que la venta no se registra.
9. Intentar combinar debito y credito en la misma venta y confirmar el bloqueo explicito.
10. Repetir en tema oscuro y confirmar que inputs, chips, importes y bordes de ambos pagos se distinguen sin fondos claros lavados.
11. Confirmar que Total, Pagado y Vuelto/Resta pagar se leen completos en el resumen inferior a `1366x768`.
12. Confirmar que el lateral comienza directamente con los medios de pago y no repite esos importes.
13. Confirmar que cada pago muestra una sola vez su metodo y su monto.
14. Confirmar que el ticket no reserva espacio para encabezados, estados o ilustraciones vacias.
15. Confirmar que resumen y acciones se perciben como parte del ticket en temas claro y oscuro.
16. Alternar repetidamente los medios de Pago 1 y Pago 2 y confirmar que ambos montos siguen editables.
17. Revisar que no haya dobles sombras, bordes negros, huecos ni esquinas superpuestas entre tabla, pie y pagos.

### 11. Upgrade legacy de stock

1. Partir de una base donde `movimientos_stock` no tenga `stock_anterior` ni `stock_nuevo`.
2. Ejecutar `php scripts/migrate.php`.
3. Confirmar que se aplica `040_movimientos_stock_snapshots_compat.sql`.
4. Verificar que ambas columnas y `before_insert_movimiento_stock` existen.
5. Registrar un ajuste y comprobar snapshots anterior/nuevo.
6. Abrir el historial desde Stock y confirmar que no devuelve error SQL.

### 12. Detalle y seguridad de contenido

1. Abrir el detalle desde el listado.
2. Verificar importes, descuentos, estado y comprobante.
3. Probar observaciones con caracteres especiales.
4. Confirmar que se muestran como texto y no se ejecuta HTML.
5. Cerrar con boton, fondo y tecla Escape.
6. Confirmar que el foco vuelve al boton que abrio el modal.

### 13. Responsive y temas

1. Revisar en `1366x768` con tema claro y oscuro.
2. Revisar en `1024x768`.
3. Revisar en un viewport movil cercano a `390x844`.
4. Confirmar que no hay scroll horizontal de pagina.
5. Confirmar que las tablas usan su contenedor de scroll cuando corresponde.
6. Confirmar que botones, inputs y modales no se superponen.
7. Confirmar que las acciones tactiles principales mantienen un area util adecuada.

### 14. Regresion relacionada

1. Abrir Stock y revisar movimientos de los productos usados.
2. Abrir Historial de precios y revisar cambios de costo.
3. Abrir Tesoreria y filtrar obligaciones por compra.
4. Abrir Proveedores y confirmar que el proveedor sigue vinculado.
5. Revisar reportes de compras para confirmar totales y estados.

## Resultado de esta revision

- Smoke: `142 OK / 0 fallidas / 0 skipped`.
- Integracion DB: instalacion limpia, 42 migraciones y flujos criticos en verde.
- La integracion elimina temporalmente las columnas de snapshot y confirma que la migracion `040` las reconstruye.
- Casos automatizados agregados:
  - rechazo de segunda confirmacion por estado;
  - rechazo de reversion con stock insuficiente;
  - deuda pendiente cancelable;
  - deuda parcial no anulable;
  - producto pesable;
  - restauracion del costo anterior;
  - restauracion del costo previo cuando el producto aparece varias veces;
  - conservacion de un costo manual posterior;
  - validacion de importes parciales para QR y Point;
  - compatibilidad de snapshots de stock en upgrades legacy;
  - historial y auditoria dentro de la conexion transaccional.
  - matriz de permisos: admin y encargado habilitados; cajero y operador sin acceso completo.
- QA previo en navegador: flujo de compras y anulacion verificado en `1366x768`, tema claro y oscuro.
- El backend y la navegacion ya exigen `editar_stock` + `ver_costos`; la matriz se valida contra una instalacion limpia.
- Los controles compactos y cierres de modal alcanzan `44px` en movil.
- Pendiente manual antes de produccion: confirmar visualmente el rechazo por URL con un usuario limitado y revisar tablet/movil. El navegador automatizado no pudo abrir `localhost` por politica del entorno.

## Criterio de salida

El cambio puede publicarse cuando:

- smoke e integracion DB siguen en verde;
- no existe duplicacion de stock ante doble confirmacion;
- una deuda pagada o parcial bloquea la anulacion;
- una falta de stock revierte toda la operacion;
- un pago dividido valida cada medio digital contra su importe parcial;
- la migracion `040` repara columnas y trigger en una base legacy;
- varias lineas del mismo producto restauran el costo previo a toda la compra;
- los permisos se validan por URL con un usuario real limitado;
- Compras resulta operable en 1366, tablet y movil sin superposiciones.
