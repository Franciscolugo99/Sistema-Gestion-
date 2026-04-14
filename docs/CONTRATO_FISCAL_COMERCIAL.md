# Contrato fiscal/comercial

Esta hoja fija el contrato minimo entre venta, documento comercial, factura, nota de credito, cobranza, recibo y recovery. La regla general es no reutilizar un campo o estado para representar dos hechos distintos.

## Venta

- Representa la operacion de mostrador y la foto comercial original.
- `ventas.total` no debe mutar para explicar NC, anulaciones parciales o cobranzas posteriores.
- Los pagos de caja viven en `venta_pagos`; la porcion a cuenta corriente no es una cobranza real hasta que se paga.
- La venta puede vincularse a factura/documento, pero no reemplaza al documento fiscal ni al recibo.

## Documento comercial

- Representa una pieza comercial interna: factura manual base, presupuesto, remito o recibo documental.
- Puede existir antes o al lado de una venta.
- `documentos_comerciales` y `documento_items` son la base para reconstruir detalle cuando el flujo no nace estrictamente desde POS.
- No debe usarse para guardar el resultado fiscal de ARCA.

## Factura

- Representa el comprobante fiscal emitido o pendiente de emision.
- `facturas.estado_fiscal`, `fiscal_request_uid`, `fiscal_intentos`, `fiscal_requested_at`, `fiscal_approved_at`, `fiscal_error_code` y `fiscal_error_message` describen la relacion con ARCA.
- `facturas.venta_id` y `facturas.documento_id` son vinculos de origen o soporte, no la identidad de la factura.
- Una factura autorizada no debe desaparecer ni cambiar de significado por una anulacion comercial; se compensa con NC.

## Nota de credito

- Es una factura con naturaleza `NC` y comprobante propio.
- Debe vincularse a la factura original y, cuando aplique, a `venta_anulaciones`.
- NC total y NC parcial son eventos distintos; no se modelan mutando la factura original.
- La reposicion de stock se registra como efecto de la anulacion/NC, no reescribiendo la venta original.

## Cobranza

- Representa el hecho de cobro real.
- Debe poder vincularse a venta, factura, documento o movimiento de cuenta corriente segun el origen.
- Debe ser idempotente por clave externa o `request_uid` cuando el flujo pueda reintentarse.
- No debe confundirse con la promesa de cobro a cuenta corriente.

## Recibo

- Representa la constancia documental del cobro.
- Se genera desde una cobranza y se aplica contra factura/documento/venta cuando corresponda.
- Su aplicacion debe evitar duplicados para la misma cobranza.
- No reemplaza a la cobranza: la cobranza es el hecho de pago, el recibo es su constancia.

## Recovery fiscal

- Solo regulariza intentos fiscales pendientes o ambiguos.
- Debe reutilizar `fiscal_request_uid` y `factura_eventos_arca` cuando existan.
- No debe reenviar a ARCA a ciegas si el caso quedo en `ERROR_POST_ARCA`.
- Si reconstruye contexto, debe compararlo contra el snapshot/request original antes de finalizar.
- La trazabilidad `envio_ultimo_*` queda reservada al reenvio comercial al cliente; no describe la interaccion fiscal con ARCA.

## Invariantes

- No mezclar envio comercial con emision fiscal.
- No duplicar facturas, cobranzas, recibos ni aplicaciones ante un retry.
- No perder la foto historica de venta, factura o documento por un ajuste posterior.
- No publicar una release que rompa `tests/smoke.php` o `tests/integration_db.php` cuando el cambio toca este contrato.
