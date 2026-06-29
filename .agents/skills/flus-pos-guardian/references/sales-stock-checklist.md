# Sales, Payments and Stock Checklist

## Core Invariants

- A sale must be recorded exactly once.
- A payment must be recorded exactly once.
- A stock movement must be recorded exactly once.
- Server code must recalculate prices, discounts, promotions, totals and stock impact.
- Frontend totals are hints only.
- Historical financial records should be annulled, reversed or marked, not physically deleted.

## Transactions and Locks

- Use `$pdo->beginTransaction()` for operations that update multiple tables.
- Lock rows that define stock, sale state, purchase state, customer balance or payment state with `FOR UPDATE` inside the transaction.
- Check current state after locking, not before only.
- Commit only after all related rows and audit/movement rows are written.
- Roll back on errors.

## Idempotency and Retries

- Treat double-click, browser retry, timeout and lost connection as normal.
- Use existing idempotency keys where present, such as `request_uid`, session idempotency, external payment ids or unique constraints.
- For Mercado Pago, ensure `mp_payment_id` cannot be linked twice.
- Return the existing result for duplicate safe retries rather than creating a second sale/payment.

## Stock Rules

- Never allow negative stock unless an explicit existing configuration says it is allowed.
- For purchases and returns, ensure stock deltas and movement records match.
- For anulations, verify whether stock should be restored and how the current module records that.
- For pesable products, preserve decimal quantity behavior and existing unit conventions.

## Flow-Specific Checks

- Caja/ventas: inspect `public/caja.php`, `public/caja_lib.php`, `public/api/actions/registrar_venta.php`, `public/api/actions/calcular_carrito.php`, `src/venta_api_lib.php` and relevant JS in `public/assets/js/`.
- Anulations: inspect `public/api/actions/anular_venta.php`, `public/api/actions/anular_items_venta.php`, `src/venta_anulaciones_lib.php` and audit/report impacts.
- Compras: inspect `public/compras.php`, `public/compra_detalle.php`, `src/compras_helpers.php`, `src/compras_precio_historial_lib.php`, `src/compras_tesoreria_lib.php`.
- Cobranzas/cuenta corriente: inspect `src/cobranzas_lib.php`, `public/cobranzas.php`, `public/api/cuenta_corriente_api.php`, `public/api/factura_cobranza_api.php`.
- Tesoreria: inspect `src/tesoreria_lib.php` and `public/tesoreria*.php`.

## Tests to Consider

- Successful primary flow.
- Insufficient permission.
- Invalid product/customer/payment data.
- Double submit or retry.
- Stock at zero or below required quantity.
- Legacy data path when an older table/column may exist.
- Facturacion disabled mode if touching commercial/fiscal crossover.
