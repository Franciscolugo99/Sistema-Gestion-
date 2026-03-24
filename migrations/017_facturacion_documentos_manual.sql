-- Fase 2 acotada: capa documental mínima para facturación manual
-- No destructiva. Mantiene compatibilidad con facturas.venta_id legacy.
-- Objetivo: separar documento comercial interno de venta y comprobante fiscal.

CREATE TABLE IF NOT EXISTS `documentos_comerciales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `request_uid` CHAR(36) DEFAULT NULL,
  `tipo_documento` VARCHAR(40) NOT NULL DEFAULT 'FACTURA_MANUAL',
  `origen` VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
  `estado` VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  `cliente_id` INT(11) DEFAULT NULL,
  `venta_id` INT(11) DEFAULT NULL,
  `nota` VARCHAR(255) DEFAULT NULL,
  `medio_pago` VARCHAR(50) DEFAULT NULL,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_documentos_request_uid` (`request_uid`),
  KEY `idx_documentos_tipo_estado` (`tipo_documento`,`estado`),
  KEY `idx_documentos_cliente` (`cliente_id`),
  KEY `idx_documentos_venta` (`venta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `documento_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `documento_id` INT(11) NOT NULL,
  `codigo` VARCHAR(80) DEFAULT NULL,
  `descripcion` VARCHAR(255) NOT NULL,
  `cantidad` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `precio_unitario` DECIMAL(12,2) NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL,
  `iva_porcentaje` DECIMAL(5,2) NOT NULL DEFAULT 21.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_documento_items_documento` (`documento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @facturas_documento_id_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'facturas'
      AND COLUMN_NAME  = 'documento_id'
);

SET @sql_add_facturas_documento_id = IF(
    @facturas_documento_id_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `documento_id` INT(11) DEFAULT NULL AFTER `venta_id`',
    'SELECT 1 -- facturas.documento_id ya existe, skip'
);

PREPARE _stmt FROM @sql_add_facturas_documento_id; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_facturas_documento_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'facturas'
      AND INDEX_NAME   = 'idx_facturas_documento'
);

SET @sql_add_facturas_documento_idx = IF(
    @idx_facturas_documento_exists = 0,
    'ALTER TABLE `facturas` ADD KEY `idx_facturas_documento` (`documento_id`)',
    'SELECT 1 -- idx_facturas_documento ya existe, skip'
);

PREPARE _stmt FROM @sql_add_facturas_documento_idx; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
