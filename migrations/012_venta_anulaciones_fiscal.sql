-- Fase 1 tecnica: metadata fiscal resumida para venta_anulaciones
-- No destructiva. Amplia estados y agrega columnas de trazabilidad fiscal.

ALTER TABLE `ventas`
  MODIFY COLUMN `estado` ENUM('EMITIDA','PARCIALMENTE_ANULADA','ANULADA')
  NOT NULL DEFAULT 'EMITIDA';

ALTER TABLE `venta_anulaciones`
  MODIFY COLUMN `estado` ENUM('PENDIENTE','CONFIRMADA','CANCELADA')
  NOT NULL DEFAULT 'CONFIRMADA';

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `requiere_nc` TINYINT(1) NOT NULL DEFAULT 0 AFTER `monto_total`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `factura_origen_id` INT(11) DEFAULT NULL AFTER `requiere_nc`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `nc_factura_id` INT(11) DEFAULT NULL AFTER `factura_origen_id`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `estado_fiscal` ENUM(
    'NO_APLICA',
    'PENDIENTE',
    'ENVIANDO',
    'APROBADA_PENDIENTE_APLICACION',
    'APLICADA',
    'RECHAZADA',
    'ERROR_POST_ARCA'
  ) NOT NULL DEFAULT 'NO_APLICA' AFTER `nc_factura_id`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `fiscal_request_uid` CHAR(36) DEFAULT NULL AFTER `estado_fiscal`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `fiscal_intentos` INT(11) NOT NULL DEFAULT 0 AFTER `fiscal_request_uid`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `fiscal_error_code` VARCHAR(50) DEFAULT NULL AFTER `fiscal_intentos`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `fiscal_error_message` TEXT DEFAULT NULL AFTER `fiscal_error_code`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `fiscal_requested_at` DATETIME DEFAULT NULL AFTER `fiscal_error_message`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `fiscal_approved_at` DATETIME DEFAULT NULL AFTER `fiscal_requested_at`;

ALTER TABLE `venta_anulaciones`
  ADD COLUMN `fiscal_applied_at` DATETIME DEFAULT NULL AFTER `fiscal_approved_at`;

ALTER TABLE `venta_anulaciones`
  ADD KEY `idx_va_estado_fiscal` (`estado_fiscal`);

ALTER TABLE `venta_anulaciones`
  ADD KEY `idx_va_factura_origen` (`factura_origen_id`);

ALTER TABLE `venta_anulaciones`
  ADD KEY `idx_va_nc_factura` (`nc_factura_id`);

ALTER TABLE `venta_anulaciones`
  ADD KEY `idx_va_request_uid` (`fiscal_request_uid`);

ALTER TABLE `venta_anulaciones`
  ADD KEY `idx_va_requiere_nc_estado` (`requiere_nc`,`estado_fiscal`);
