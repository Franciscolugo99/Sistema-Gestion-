-- Fase 1 tecnica: persistencia de lineas fiscales y eventos ARCA
-- No destructiva. Sin FKs fragiles a tablas legacy.

CREATE TABLE IF NOT EXISTS `factura_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `factura_id` INT(11) NOT NULL,
  `linea_orden` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `origen_tipo` ENUM('VENTA','ANULACION') NOT NULL DEFAULT 'VENTA',
  `snapshot_source` ENUM('ORIGINAL','RECONSTRUIDO') NOT NULL DEFAULT 'ORIGINAL',
  `venta_item_id` INT(11) DEFAULT NULL,
  `venta_anulacion_item_id` INT(11) DEFAULT NULL,
  `producto_id` INT(11) DEFAULT NULL,
  `codigo_snapshot` VARCHAR(50) DEFAULT NULL,
  `descripcion_snapshot` VARCHAR(255) NOT NULL,
  `cantidad` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `precio_unitario_bruto` DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
  `descuento_total` DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
  `iva_porcentaje` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `neto_gravado` DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
  `iva_importe` DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
  `subtotal_total` DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fi_factura` (`factura_id`),
  KEY `idx_fi_factura_linea` (`factura_id`,`linea_orden`),
  KEY `idx_fi_venta_item` (`venta_item_id`),
  KEY `idx_fi_anulacion_item` (`venta_anulacion_item_id`),
  KEY `idx_fi_producto` (`producto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `factura_eventos_arca` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `venta_anulacion_id` INT(11) DEFAULT NULL,
  `factura_id` INT(11) DEFAULT NULL,
  `request_uid` CHAR(36) NOT NULL,
  `operacion` ENUM('NC_TOTAL','NC_PARCIAL','CONSULTA','RECOVERY') NOT NULL,
  `resultado` ENUM('PENDIENTE','OK','ERROR') NOT NULL DEFAULT 'PENDIENTE',
  `intento_no` INT(11) NOT NULL DEFAULT 1,
  `modo` ENUM('demo','homologacion','produccion') NOT NULL DEFAULT 'demo',
  `error_code` VARCHAR(50) DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `request_json` LONGTEXT DEFAULT NULL,
  `response_json` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_fea_request_uid` (`request_uid`),
  KEY `idx_fea_anulacion` (`venta_anulacion_id`),
  KEY `idx_fea_factura` (`factura_id`),
  KEY `idx_fea_operacion_fecha` (`operacion`,`created_at`),
  KEY `idx_fea_resultado_fecha` (`resultado`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
