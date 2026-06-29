-- FLUS 4.2.0 - idempotencia de creacion de ventas de caja.
-- Permite reintentar el mismo cobro sin duplicar venta, pagos ni stock.

ALTER TABLE `ventas`
  ADD COLUMN `request_uid` VARCHAR(64) DEFAULT NULL AFTER `uuid`;

ALTER TABLE `ventas`
  ADD UNIQUE KEY `ux_ventas_request_uid` (`request_uid`);
