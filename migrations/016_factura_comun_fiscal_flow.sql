-- Fase 1 acotada: estado fiscal e idempotencia para factura común.
-- Soporte previsto via install.sql (baseline) + scripts/migrate.php.
-- Requiere baseline install.sql + migraciones 013/014 ya aplicadas.
-- No toca facturas.venta_id ni elimina compatibilidad legacy.

ALTER TABLE `facturas`
  ADD COLUMN `estado_fiscal` ENUM('NO_APLICA','PENDIENTE_ENVIO','ERROR_TRANSITORIO','AUTORIZADA','RECHAZADA') NOT NULL DEFAULT 'NO_APLICA' AFTER `estado`;

ALTER TABLE `facturas`
  ADD COLUMN `fiscal_request_uid` CHAR(36) DEFAULT NULL AFTER `estado_fiscal`;

ALTER TABLE `facturas`
  ADD COLUMN `fiscal_intentos` INT(11) NOT NULL DEFAULT 0 AFTER `fiscal_request_uid`;

ALTER TABLE `facturas`
  ADD COLUMN `fiscal_error_code` VARCHAR(50) DEFAULT NULL AFTER `fiscal_intentos`;

ALTER TABLE `facturas`
  ADD COLUMN `fiscal_error_message` TEXT DEFAULT NULL AFTER `fiscal_error_code`;

ALTER TABLE `facturas`
  ADD COLUMN `fiscal_requested_at` DATETIME DEFAULT NULL AFTER `fiscal_error_message`;

ALTER TABLE `facturas`
  ADD COLUMN `fiscal_approved_at` DATETIME DEFAULT NULL AFTER `fiscal_requested_at`;

ALTER TABLE `facturas`
  ADD KEY `idx_facturas_estado_fiscal` (`estado_fiscal`);

ALTER TABLE `facturas`
  ADD UNIQUE KEY `ux_facturas_fiscal_request_uid` (`fiscal_request_uid`);

ALTER TABLE `factura_eventos_arca`
  ADD COLUMN `venta_id` INT(11) DEFAULT NULL AFTER `venta_anulacion_id`;

ALTER TABLE `factura_eventos_arca`
  ADD COLUMN `cliente_id` INT(11) DEFAULT NULL AFTER `venta_id`;

ALTER TABLE `factura_eventos_arca`
  MODIFY COLUMN `operacion` ENUM('FACTURA_VENTA','FACTURA_MANUAL','FACTURA_RECOVERY','NC_TOTAL','NC_PARCIAL','CONSULTA','RECOVERY') NOT NULL;

ALTER TABLE `factura_eventos_arca`
  ADD KEY `idx_fea_venta` (`venta_id`);

ALTER TABLE `factura_eventos_arca`
  ADD KEY `idx_fea_cliente` (`cliente_id`);

ALTER TABLE `factura_eventos_arca`
  ADD KEY `idx_fea_venta_operacion` (`venta_id`,`operacion`,`created_at`);
