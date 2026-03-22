-- Fase 1 tecnica: ampliar facturas para soportar FACTURA / NC / ND
-- No destructiva. No toca indices legacy de numeracion.
-- La unicidad de NC por anulacion va solo por venta_anulacion_id:
-- una anulacion fiscal debe producir como maximo un comprobante compensatorio.

ALTER TABLE `facturas`
  ADD COLUMN `naturaleza` ENUM('FACTURA','NC','ND') NOT NULL DEFAULT 'FACTURA' AFTER `cliente_id`;

ALTER TABLE `facturas`
  ADD COLUMN `tipo_cbte` INT(11) DEFAULT NULL AFTER `tipo`;

ALTER TABLE `facturas`
  ADD COLUMN `venta_anulacion_id` INT(11) DEFAULT NULL AFTER `venta_id`;

ALTER TABLE `facturas`
  ADD COLUMN `factura_asociada_id` INT(11) DEFAULT NULL AFTER `venta_anulacion_id`;

ALTER TABLE `facturas`
  ADD COLUMN `comprobante_asoc_tipo_cbte` INT(11) DEFAULT NULL AFTER `factura_asociada_id`;

ALTER TABLE `facturas`
  ADD COLUMN `comprobante_asoc_punto_venta` INT(11) DEFAULT NULL AFTER `comprobante_asoc_tipo_cbte`;

ALTER TABLE `facturas`
  ADD COLUMN `comprobante_asoc_numero` INT(11) DEFAULT NULL AFTER `comprobante_asoc_punto_venta`;

ALTER TABLE `facturas`
  ADD COLUMN `comprobante_asoc_cuit` VARCHAR(20) DEFAULT NULL AFTER `comprobante_asoc_numero`;

ALTER TABLE `facturas`
  ADD COLUMN `doc_tipo` INT(11) DEFAULT NULL AFTER `comprobante_asoc_cuit`;

ALTER TABLE `facturas`
  ADD COLUMN `doc_numero` VARCHAR(20) DEFAULT NULL AFTER `doc_tipo`;

ALTER TABLE `facturas`
  ADD COLUMN `condicion_iva_receptor_id` INT(11) DEFAULT NULL AFTER `doc_numero`;

ALTER TABLE `facturas`
  ADD COLUMN `importe_no_gravado` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `importe_exento`;

ALTER TABLE `facturas`
  ADD COLUMN `moneda_id` VARCHAR(3) NOT NULL DEFAULT 'PES' AFTER `importe_no_gravado`;

ALTER TABLE `facturas`
  ADD COLUMN `moneda_cotiz` DECIMAL(12,6) NOT NULL DEFAULT 1.000000 AFTER `moneda_id`;

ALTER TABLE `facturas`
  ADD KEY `idx_facturas_naturaleza` (`naturaleza`);

ALTER TABLE `facturas`
  ADD KEY `idx_facturas_venta_anulacion` (`venta_anulacion_id`);

ALTER TABLE `facturas`
  ADD KEY `idx_facturas_factura_asociada` (`factura_asociada_id`);

ALTER TABLE `facturas`
  ADD KEY `idx_facturas_tipo_cbte` (`tipo_cbte`);

ALTER TABLE `facturas`
  ADD KEY `idx_facturas_asoc_cbte` (`comprobante_asoc_tipo_cbte`,`comprobante_asoc_punto_venta`,`comprobante_asoc_numero`);

ALTER TABLE `facturas`
  ADD UNIQUE KEY `ux_facturas_nc_por_anulacion` (`venta_anulacion_id`);

-- No tocar venta_fiscal en esta fase.
-- No tocar el indice unico historico de facturas en esta fase.
