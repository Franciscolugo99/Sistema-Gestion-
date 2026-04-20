# Plan maestro de integracion FLUS

Fecha de corte: 2026-04-20

Este documento deja contexto operativo para continuar el trabajo en mas de una maquina.
La idea es que FLUS no siga creciendo solo por pantallas nuevas, sino con reglas claras
de integracion entre modulos.

## Resumen ejecutivo

FLUS cumple bien como POS y gestion comercial para comercios chicos: caja, ventas,
stock, compras, proveedores, clientes, cuenta corriente, permisos, diagnostico y
backups ya forman una base operativa real.

El punto a corregir no es "faltan modulos", sino que algunos modulos ya se pisan o
conviven por compatibilidad legacy:

- pagos, caja, cobranzas, recibos, cuenta corriente y tesoreria representan dinero
  desde lugares distintos;
- ventas, facturas y documentos comerciales todavia conviven con capas de transicion;
- compras/proveedores funciona bien para stock, pero todavia no alimenta tesoreria
  ni cuentas por pagar;
- algunos permisos siguen usando slugs amplios, por ejemplo compras depende de
  `editar_stock` y ventas/listados de `ver_reportes`;
- varias pantallas publicas siguen siendo hotspots grandes con logica de consulta,
  accion y vista en el mismo archivo.

## Lo hecho en esta tanda

### Compras

- Se compacto y ordeno el formulario de compras.
- Se alinearon inputs de proveedor, comprobante, busqueda de producto, cantidad,
  costo unitario, descuento y monto.
- Se corrigio el subtotal para que no deje espacios blancos innecesarios ni quede
  como bloque fijo mal ubicado.
- Se pulio el selector de descuento global cuando cambia entre monto y porcentaje.
- Se cambio el feedback de compras para usar notificaciones tipo Notis.js como el
  resto del sistema.
- Se revisaron errores de borrador/confirmacion para no mostrar banners inferiores
  desalineados.

### Clientes y proveedores

- Se reviso la diferencia visual entre clientes y proveedores.
- Se comenzo a unificar criterio de UI: filtros, tabla, acciones, estados y lectura
  de actividad relacionada.
- Se ajustaron acciones para que botones como editar, desactivar y cuenta corriente
  sean mas comprensibles.
- Se cargaron datos demo para probar comportamiento con volumen mayor de clientes,
  proveedores y compras.
- Se corrigio la tabla de proveedores para que estado y acciones no deformen el
  layout con datos reales.
- Se alineo la paginacion de proveedores con el criterio usado por otros modulos.

### Release y fiscal

- Se preparo el camino de `3.9.0-rc1` con README, changelog y docs de release.
- Se corrigio el formato de timestamp WSAA para ARCA usando horario local compatible
  con el TRA.
- Se corrigio la seleccion de comprobante para emisor RI y receptor monotributista:
  debe ir por comprobante clase A cuando corresponde.
- Se agrego manejo operativo para cerrar incidencias fiscales rechazadas que ya no
  deben seguir apareciendo como pendientes de recovery.
- Se agrego migracion `029_facturas_cierre_incidencia_fiscal.sql`.
- Se amplio smoke test y quedo pasando en la corrida local.

### Commit relevante ya publicado

- `6b5b0f1 Unificar UI de compras clientes y proveedores`

## Diagnostico actual

### Modulos bien conectados

- Caja, ventas, pagos y stock tienen el circuito POS mas maduro.
- Clientes y cuenta corriente estan bien encaminados: el historial de movimientos
  es la fuente de verdad y el saldo del cliente funciona como cache operativa.
- Compras, proveedores y stock ya forman un circuito util para ingresar mercaderia.
- Facturacion, documentos, cobranzas y recibos tienen una direccion correcta, con
  migraciones no destructivas y compatibilidad legacy.
- Diagnostico, migraciones, smoke y panel tecnico son una fortaleza del producto.

### Redundancias a corregir

1. Dinero representado en demasiados lugares:
   `venta_pagos`, `caja_movimientos`, `cuenta_corriente_movimientos`, `cobranzas`,
   `cobranza_aplicaciones`, `recibos` y `tesoreria_movimientos`.
2. Documento comercial y venta todavia conviven como fuentes parcialmente solapadas.
3. `facturas` concentra demasiadas responsabilidades: fiscal, comercial, recovery,
   envio, relaciones documentales e incidencia.
4. Permisos de negocio todavia dependen de permisos genericos.
5. Hotspots publicos grandes siguen mezclando controlador, query, reglas y vista.
6. APIs legacy y action files conviven sin un contrato unico por dominio.

### Modulos que deben conectarse mejor

- Compras -> Tesoreria: una compra confirmada deberia poder generar obligacion a
  pagar o egreso sugerido.
- Proveedores -> Cuentas por pagar: proveedores necesita una vista financiera
  equivalente, aunque mas simple, a cuenta corriente de clientes.
- Caja -> Tesoreria: el cierre de caja deberia alimentar movimientos financieros
  o, como minimo, dejar un evento listo para tesoreria.
- Cobranzas -> Tesoreria: un cobro real deberia terminar asociado a una cuenta
  financiera, como efectivo, banco, Mercado Pago o transferencia.
- Compras -> Costos/precios: cada compra deberia reforzar historial de costo,
  margen sugerido y alertas de variacion.
- Clientes -> Facturacion/CC: la ficha del cliente deberia mostrar estado fiscal,
  comprobantes, deuda y ultimas operaciones en una sola lectura.

### Modulos que conviene mantener separados

- Caja y tesoreria: conectadas por eventos, no fusionadas.
- Facturacion fiscal y envio comercial: ARCA/CAE no debe mezclarse con WhatsApp/email.
- Inventario fisico y stock operativo: conteo fisico debe seguir siendo auditoria.
- Diagnostico, backups, licencia y soporte tecnico: mantener en zona admin/tecnica.
- Configuracion fiscal ARCA: mantener controlada, no mezclar con flujos diarios.

## Plan de correccion

### Fase 0 - Orden para trabajar en dos maquinas

Objetivo: evitar perder contexto o pisar cambios.

Reglas:

- Antes de trabajar en la otra maquina, traer cambios con `git pull`.
- Despues de traer cambios, correr migraciones si hay archivos nuevos en
  `migrations/`.
- Correr smoke antes de seguir tocando dominios sensibles.
- No empezar una fase nueva con cambios locales sin commitear que pertenezcan a otra
  maquina.
- Mantener este archivo actualizado cuando cambie el plan.

Checklist sugerido al cambiar de maquina:

```powershell
& 'C:\Program Files\Git\cmd\git.exe' pull
C:\xampp\php\php.exe scripts\migrate.php
C:\xampp\php\php.exe tests\smoke.php
```

### Fase 1 - Cerrar release 3.9.0-rc1

Objetivo: que el estado actual quede estable antes de refactors grandes.

Pendiente:

- Confirmar que `README.md`, `CHANGELOG.md`, `docs/RELEASE_3_9_0.md` y
  `src/version.php` reflejen la misma version.
- Confirmar que la migracion `029` este aplicada en ambas maquinas.
- Validar manualmente:
  - compras: borrador, confirmar, cancelar, listado;
  - proveedores: filtros, paginacion, acciones, historial;
  - clientes: ficha, cuenta corriente, acciones;
  - facturacion recovery: reemitir, cerrar incidencia, ver factura;
  - caja: venta, cobro, anulacion, cierre.
- Dejar claro si fiscal real sale como pendiente o como habilitado validado.

Criterio de salida:

- Smoke OK.
- Migraciones OK.
- Sin banners visuales rotos en modulos principales.
- Fiscal real documentado como pendiente si no hay prueba ARCA cerrada.

### Fase 2 - Definir modelo financiero canonico

Objetivo: que cada peso tenga un duenio claro.

Decisiones a tomar:

- `venta_pagos`: detalle operativo original de una venta.
- `caja_movimientos`: movimiento de caja/sesion.
- `cuenta_corriente_movimientos`: deuda y pagos por cliente.
- `cobranzas`: registro canonico de cobro comercial.
- `recibos`: documento emitido por una cobranza.
- `tesoreria_movimientos`: impacto financiero por cuenta.

Trabajo:

- Crear un documento de contrato financiero.
- Marcar que tabla es fuente de verdad por flujo.
- Definir eventos de sincronizacion:
  - venta contado -> venta_pago + caja + cobranza + tesoreria;
  - venta CC -> venta + cargo CC;
  - pago CC -> movimiento CC + cobranza + recibo + caja/tesoreria;
  - factura cobrada -> cobranza + recibo + tesoreria;
  - compra confirmada -> stock + obligacion proveedor;
  - pago proveedor -> tesoreria + cierre de obligacion.

Criterio de salida:

- Ningun flujo nuevo escribe dinero en dos lugares sin idempotencia o external key.
- Cada pantalla muestra de donde sale el dato financiero.

### Fase 3 - Compras, proveedores y tesoreria

Objetivo: cerrar el lado de egresos.

Trabajo:

- Agregar opcion en compras confirmadas para generar obligacion en tesoreria.
- Asociar obligaciones a proveedor y compra.
- Crear resumen financiero en proveedor:
  - compras confirmadas;
  - obligaciones pendientes;
  - pagos realizados;
  - ultima compra;
  - total comprado;
  - deuda estimada.
- Permitir pagar obligacion desde tesoreria y dejar rastro hacia proveedor.

Criterio de salida:

- Una compra no queda solo como stock: tambien puede quedar como compromiso de pago.
- Proveedor tiene lectura comercial y financiera.

### Fase 4 - Permisos dedicados

Objetivo: que permisos representen modulos reales, no atajos historicos.

Nuevos permisos sugeridos:

- `ver_ventas`
- `gestionar_ventas`
- `ver_compras`
- `gestionar_compras`
- `ver_proveedores`
- `editar_proveedores`
- `ver_clientes`
- `editar_clientes`
- `ver_tesoreria`
- `gestionar_tesoreria`

Trabajo:

- Revisar `public/partials/nav.php`.
- Reemplazar dependencias como `editar_stock` para compras y `ver_reportes` para
  ventas.
- Actualizar seeds/install/migrations de permisos.
- Agregar smoke para permisos nuevos.

Criterio de salida:

- Un usuario puede ver compras sin necesariamente editar stock.
- Un usuario puede ver ventas sin tener permisos generales de reportes.

### Fase 5 - Ciclo documental oficial

Objetivo: que FLUS tenga un flujo comercial claro.

Flujo deseado:

```text
Presupuesto -> Remito -> Venta -> Factura -> Cobranza -> Recibo -> Nota de credito
```

Trabajo:

- Documentar estados posibles por documento.
- Definir conversiones permitidas.
- Definir que pasa con stock en cada paso.
- Definir que pasa con caja/tesoreria en cada paso.
- Reducir dependencia de "venta manual fake" donde ya exista documento comercial.

Criterio de salida:

- Una factura no necesita explicar su historia con datos duplicados.
- El usuario entiende si esta viendo una venta, un documento, una factura o un cobro.

### Fase 6 - Reducir hotspots

Objetivo: bajar riesgo de mantenimiento.

Prioridad de extraccion:

1. `public/compras.php`
2. `public/proveedores.php`
3. `public/productos.php`
4. `src/facturacion_lib.php`
5. `src/cobranzas_lib.php`
6. `public/factura_ver.php`

Trabajo:

- Mover consultas a libs por dominio.
- Dejar paginas publicas como request + permisos + render.
- Mantener tests smoke por contrato critico.
- No hacer refactor estetico sin mejora funcional o de riesgo.

Criterio de salida:

- Archivos publicos principales quedan por debajo del presupuesto documentado.
- Las reglas sensibles viven en `src/`.

### Fase 7 - QA funcional por modulo

Objetivo: que FLUS prometa solo lo que realmente se probo.

Matriz minima:

- Caja: apertura, venta, pago mixto, cuenta corriente, cierre.
- Ventas: listado, detalle, anulacion total/parcial, ticket.
- Compras: borrador, confirmar, anular/cancelar, impacto stock.
- Proveedores: alta, edicion, desactivacion, historial, paginacion.
- Clientes: alta, edicion, CC, factura, actividad.
- Facturacion: emitir desde venta, manual, rechazo, recovery, cierre incidencia.
- Tesoreria: cuenta, categoria, movimiento, obligacion, pago.
- Inventario: ajuste, conteo fisico, analisis, reposicion.

Criterio de salida:

- Cada modulo critico tiene prueba manual documentada.
- Smoke no reemplaza QA manual fiscal ni operativo.

## Prioridad inmediata recomendada

1. Terminar y commitear el estado actual de release/fiscal/cierre de incidencias.
2. Correr smoke y migraciones en ambas maquinas.
3. Crear contrato financiero antes de conectar tesoreria automaticamente.
4. Conectar compras/proveedores con tesoreria.
5. Separar permisos de compras y ventas.
6. Recien despues, empezar extraccion de hotspots grandes.

Contrato financiero base:

- [docs/CONTRATO_FINANCIERO_FLUS.md](CONTRATO_FINANCIERO_FLUS.md)

Primer avance aplicado:

- Se agrego la migracion `030_tesoreria_obligaciones_compras.sql`.
- Tesoreria puede vincular obligaciones con compras/proveedores mediante
  `external_key`, `compra_id` y `proveedor_id`.
- Compras confirmadas muestran accion manual para crear deuda en tesoreria.
- La accion es idempotente: si la deuda ya existe, FLUS reutiliza la vinculacion.

## Estado de riesgo

- Riesgo bajo: UI de compras/clientes/proveedores, paginacion, notificaciones.
- Riesgo medio: permisos dedicados, porque impactan navegacion y roles.
- Riesgo medio/alto: conexion automatica con tesoreria, porque puede duplicar dinero.
- Riesgo alto: cambiar ciclo documental/fiscal sin contrato escrito.

## Nota final

La direccion es buena. FLUS no necesita sumar mas modulos por ahora; necesita que los
modulos existentes hablen mejor entre si y que cada dato importante tenga un duenio.
