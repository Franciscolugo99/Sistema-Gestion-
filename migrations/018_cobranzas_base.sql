-- Fase 3 mínima: base de cobranzas/aplicaciones sin romper venta_pagos ni cuenta corriente.
-- No destructiva. Complementa legacy y mantiene modo no fiscal intacto.

CREATE TABLE IF NOT EXISTS `cobranzas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `external_key` VARCHAR(191) DEFAULT NULL,
  `origen` VARCHAR(30) NOT NULL DEFAULT 'GENERAL',
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVA',
  `venta_id` INT(11) DEFAULT NULL,
  `cliente_id` INT(11) DEFAULT NULL,
  `cc_movimiento_id` INT(11) DEFAULT NULL,
  `caja_id` INT(11) DEFAULT NULL,
  `caja_movimiento_id` INT(11) DEFAULT NULL,
  `medio_pago` VARCHAR(50) NOT NULL,
  `importe_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `referencia` VARCHAR(100) DEFAULT NULL,
  `observaciones` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_cobranzas_external_key` (`external_key`),
  KEY `idx_cobranzas_venta` (`venta_id`),
  KEY `idx_cobranzas_cliente` (`cliente_id`),
  KEY `idx_cobranzas_cc_mov` (`cc_movimiento_id`),
  KEY `idx_cobranzas_caja` (`caja_id`),
  KEY `idx_cobranzas_caja_mov` (`caja_movimiento_id`),
  KEY `idx_cobranzas_medio` (`medio_pago`),
  KEY `idx_cobranzas_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cobranza_aplicaciones` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `cobranza_id` INT(11) NOT NULL,
  `application_key` VARCHAR(191) DEFAULT NULL,
  `tipo_aplicacion` VARCHAR(30) NOT NULL DEFAULT 'GENERAL',
  `venta_id` INT(11) DEFAULT NULL,
  `documento_id` INT(11) DEFAULT NULL,
  `factura_id` INT(11) DEFAULT NULL,
  `cc_movimiento_id` INT(11) DEFAULT NULL,
  `monto` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_cobranza_aplicaciones_key` (`application_key`),
  KEY `idx_cobranza_aplicaciones_cobranza` (`cobranza_id`),
  KEY `idx_cobranza_aplicaciones_venta` (`venta_id`),
  KEY `idx_cobranza_aplicaciones_documento` (`documento_id`),
  KEY `idx_cobranza_aplicaciones_factura` (`factura_id`),
  KEY `idx_cobranza_aplicaciones_cc_mov` (`cc_movimiento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
