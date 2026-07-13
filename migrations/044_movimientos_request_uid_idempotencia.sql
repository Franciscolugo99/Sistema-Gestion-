-- FLUS 4.2.2 - idempotencia persistente para movimientos operativos.
-- Las filas historicas conservan NULL; MySQL permite multiples NULL en indices UNIQUE.

ALTER TABLE `caja_movimientos`
  ADD COLUMN `request_uid` VARCHAR(80) DEFAULT NULL AFTER `usuario_registro`;

ALTER TABLE `caja_movimientos`
  ADD UNIQUE KEY `ux_caja_movimientos_request_uid` (`request_uid`);

ALTER TABLE `movimientos_stock`
  ADD COLUMN `request_uid` VARCHAR(80) DEFAULT NULL AFTER `usuario_id`;

ALTER TABLE `movimientos_stock`
  ADD UNIQUE KEY `ux_movimientos_stock_request_uid` (`request_uid`);
