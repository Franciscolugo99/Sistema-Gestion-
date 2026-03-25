-- Fase 4 minima: recibos de cobranza y aplicacion explicita sin romper caja/CC legacy.
-- Reutiliza documentos_comerciales como cabecera de recibo y agrega una capa propia de aplicaciones.
-- No destruye venta_pagos, cobranzas ni cuenta_corriente_movimientos.

SET @cobranzas_recibo_documento_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'cobranzas'
      AND COLUMN_NAME  = 'recibo_documento_id'
);

SET @sql_add_cobranzas_recibo_documento = IF(
    @cobranzas_recibo_documento_exists = 0,
    'ALTER TABLE `cobranzas` ADD COLUMN `recibo_documento_id` INT(11) DEFAULT NULL AFTER `caja_movimiento_id`',
    'SELECT 1 -- cobranzas.recibo_documento_id ya existe, skip'
);

PREPARE _stmt FROM @sql_add_cobranzas_recibo_documento; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_cobranzas_recibo_documento_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'cobranzas'
      AND INDEX_NAME   = 'idx_cobranzas_recibo_documento'
);

SET @sql_add_cobranzas_recibo_documento_idx = IF(
    @idx_cobranzas_recibo_documento_exists = 0,
    'ALTER TABLE `cobranzas` ADD KEY `idx_cobranzas_recibo_documento` (`recibo_documento_id`)',
    'SELECT 1 -- idx_cobranzas_recibo_documento ya existe, skip'
);

PREPARE _stmt FROM @sql_add_cobranzas_recibo_documento_idx; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

CREATE TABLE IF NOT EXISTS `recibo_aplicaciones` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `recibo_documento_id` INT(11) NOT NULL,
  `cobranza_id` INT(11) NOT NULL,
  `application_key` VARCHAR(191) DEFAULT NULL,
  `tipo_aplicacion` VARCHAR(30) NOT NULL DEFAULT 'SALDO_CC',
  `cliente_id` INT(11) DEFAULT NULL,
  `cc_movimiento_id` INT(11) DEFAULT NULL,
  `documento_id` INT(11) DEFAULT NULL,
  `factura_id` INT(11) DEFAULT NULL,
  `monto` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_recibo_aplicaciones_key` (`application_key`),
  KEY `idx_recibo_aplicaciones_recibo` (`recibo_documento_id`),
  KEY `idx_recibo_aplicaciones_cobranza` (`cobranza_id`),
  KEY `idx_recibo_aplicaciones_cliente` (`cliente_id`),
  KEY `idx_recibo_aplicaciones_cc_mov` (`cc_movimiento_id`),
  KEY `idx_recibo_aplicaciones_documento` (`documento_id`),
  KEY `idx_recibo_aplicaciones_factura` (`factura_id`),
  KEY `idx_recibo_aplicaciones_tipo` (`tipo_aplicacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @cc_mov_request_uid_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'cuenta_corriente_movimientos'
      AND COLUMN_NAME  = 'request_uid'
);

SET @sql_add_cc_mov_request_uid = IF(
    @cc_mov_request_uid_exists = 0,
    'ALTER TABLE `cuenta_corriente_movimientos` ADD COLUMN `request_uid` VARCHAR(64) DEFAULT NULL AFTER `referencia`',
    'SELECT 1 -- cuenta_corriente_movimientos.request_uid ya existe, skip'
);

PREPARE _stmt FROM @sql_add_cc_mov_request_uid; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_cc_mov_request_uid_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'cuenta_corriente_movimientos'
      AND INDEX_NAME   = 'ux_cc_mov_request_uid'
);

SET @sql_add_cc_mov_request_uid_idx = IF(
    @idx_cc_mov_request_uid_exists = 0,
    'ALTER TABLE `cuenta_corriente_movimientos` ADD UNIQUE KEY `ux_cc_mov_request_uid` (`request_uid`)',
    'SELECT 1 -- ux_cc_mov_request_uid ya existe, skip'
);

PREPARE _stmt FROM @sql_add_cc_mov_request_uid_idx; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
