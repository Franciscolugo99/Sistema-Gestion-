# Facturacion y evolucion a sistema de gestion

Fecha: 2026-03-10

## Resumen ejecutivo

FLUS ya tiene una base funcional para operar como POS:

- ventas con items, promos y split payments
- caja y cierre de caja
- clientes
- cuenta corriente
- compras / proveedores
- facturacion electronica con modo demo y produccion

El punto importante es este: hoy la facturacion esta montada sobre la venta ya cerrada. Eso permite emitir comprobantes, pero no alcanza todavia para sostener bien un sistema de gestion mas amplio con documentos comerciales, cobranzas, notas de credito, estados intermedios y trazabilidad contable mas fuerte.

## Lo que ya esta bien encaminado

### 1. Venta operativa

La venta esta bastante completa en el flujo POS:

- `public/api/index.php`
  - `registrar_venta` guarda venta, items, pagos, stock y promos.
- `public/caja.php`
  - la caja ya soporta cobro rapido y pagos mixtos.
- `public/venta_detalle.php`
  - ya muestra pagos, items y factura vinculada.

### 2. Cuenta corriente

Es el modulo con mejor base transaccional hoy:

- `public/includes/CuentaCorrienteController.php`
  - usa `cuenta_corriente_movimientos` como fuente de verdad.
  - maneja transacciones y `FOR UPDATE`.
- `sql/001_crear_cuenta_corriente.sql`
  - separa saldo cache en `clientes` de los movimientos reales.

Esto es un buen modelo a imitar para otros dominios.

### 3. Facturacion electronica

Existe una base real, no solo una maqueta:

- `src/facturacion_lib.php`
  - emite desde venta existente
  - calcula importes
  - resuelve tipo de comprobante
  - soporta demo / produccion
  - integra con ARCA/AFIP
- `public/facturacion.php`
  - listado y vista operativa
- `sql/005_facturacion_electronica_PATCH_config.sql`
  - agrega configuracion fiscal y campos extra en `facturas`

## Debilidades actuales

### 1. Facturacion acoplada a ventas

Hoy el documento fiscal depende directamente de `ventas`:

- `facturas.venta_id` es el ancla principal.
- `src/facturacion_lib.php` marca `ventas.facturada = 1`.
- la emision nace desde `public/factura_nueva.php` o `public/factura_emitir.php`.

Consecuencia:

- una venta equivale casi siempre a un comprobante
- cuesta modelar presupuesto, pedido, remito, factura, nota de credito y recibo como documentos distintos
- no hay ciclo documental completo

### 2. Duplicacion de flujo de facturacion

Hoy conviven dos caminos para crear facturas:

- `public/factura_nueva.php`
  - inserta directo en `facturas`
- `src/facturacion_lib.php`
  - tiene la logica fiscal mas rica y soporte AFIP/ARCA

Eso es una alerta de arquitectura: la regla de negocio no esta 100% centralizada.

### 3. Modelo de datos todavia orientado al POS

El esquema base historico muestra esto:

- `ventas`
  - total, medio_pago, monto_pagado, vuelto, estado
- `venta_pagos`
  - detalle de medios
- `facturas`
  - venta_id, cliente_id, tipo, numero, total, estado

Ver referencias en:

- `scripts/migrations/20260121_ventas_mejoras.sql`

Esto sirve para kiosco / caja, pero todavia no separa bien:

- operacion comercial
- cobro
- comprobante fiscal
- movimiento contable

### 4. Falta de estados intermedios de negocio

Para un sistema de gestion mas completo faltan estados como:

- presupuesto
- pedido
- reservado
- entregado parcial
- pendiente de cobro
- cobrado parcial
- facturado parcial
- anulado fiscalmente

Hoy `ventas.estado` esta muy enfocado a `EMITIDA/ANULADA`.

### 5. Compras y fiscalidad todavia no conversan del todo

Tenes compras y proveedores, pero todavia no parece haber una capa unificada para:

- libro IVA compras / ventas
- credito fiscal / debito fiscal
- relacion entre compras, stock y cuentas a pagar
- documentos de proveedor con estados

## Que deberia tener la base objetivo

Si queres que FLUS crezca a sistema de gestion, conviene separar 5 piezas:

### 1. Documento comercial

Entidad cabecera para:

- presupuesto
- pedido
- remito
- venta mostrador
- factura
- nota de credito
- nota de debito

Con estados y trazabilidad entre documentos.

### 2. Lineas del documento

Items con:

- producto
- cantidad
- precio lista
- precio final
- descuento
- impuestos
- subtotal

### 3. Cobros y cobranzas

Separar claramente:

- lo vendido
- lo cobrado
- como se cobro
- si quedo saldo pendiente

`venta_pagos` ya va en esa direccion y puede evolucionar a una tabla mas general de cobranzas.

### 4. Fiscal

Un subsistema propio para:

- tipo de comprobante
- punto de venta
- CAE
- vencimiento CAE
- condicion IVA emisor / receptor
- detalle de alicuotas
- notas de credito / debito vinculadas

### 5. Cuentas corrientes y cuentas a pagar

Ya tenes buena base para clientes. El espejo futuro es:

- cuenta corriente clientes
- cuenta corriente proveedores
- vencimientos
- recibos
- aplicacion de pagos a comprobantes

## Roadmap recomendado

### Fase 1. Ordenar la arquitectura antes de ampliar features

Objetivo: que la facturacion no dependa de caminos duplicados.

Acciones:

- unificar la creacion de facturas en una sola capa de servicio
- hacer que `public/factura_nueva.php` use `src/facturacion_lib.php`
- definir una regla unica para:
  - cuando una venta puede facturarse
  - cuando no
  - como se vincula cliente / condicion IVA / documento

### Fase 2. Fortalecer el modelo comercial

Objetivo: dejar de pensar solo en "venta cerrada de caja".

Acciones:

- ampliar estados de venta/documento
- separar mejor venta, cobro y comprobante
- agregar cabecera documental mas flexible
- preparar soporte para notas de credito y comprobantes asociados

### Fase 3. Completar padron fiscal y maestro de clientes

Objetivo: emitir mejor y con menos friccion operativa.

Acciones:

- completar datos fiscales del cliente
- normalizar condicion IVA
- tipo y numero de documento
- razon social vs consumidor final
- validaciones de CUIT mas integradas al flujo de emision

### Fase 4. Gestion real

Objetivo: pasar de POS a sistema de gestion.

Acciones:

- compras con estados y comprobantes
- cuentas a pagar
- reportes fiscales
- rentabilidad por producto/categoria
- cierres con mejor trazabilidad

## Siguiente paso recomendado en este repo

El mejor primer paso no es agregar mas pantallas de facturacion. El mejor primer paso es consolidar el dominio.

Orden sugerido:

1. Unificar emision en `src/facturacion_lib.php`.
2. Crear un servicio de dominio para "documentos de venta" o "ventas fiscales".
3. Recien despues agregar:
   - nota de credito
   - factura automatica desde caja
   - reportes fiscales

## Decision practica

Si hoy quisieras avanzar sin romper la base, la prioridad seria:

- primero consolidar arquitectura
- despues mejorar modelo documental
- recien despues expandir funcionalidad fiscal

Eso te deja crecer hacia un sistema de gestion de verdad, en lugar de seguir agregando features sobre una venta POS que ya esta cargando demasiadas responsabilidades.
