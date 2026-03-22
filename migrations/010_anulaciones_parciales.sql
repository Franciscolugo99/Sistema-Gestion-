-- Fase 1: anulaciones parciales no fiscales

ALTER TABLE `ventas`
  MODIFY COLUMN `estado` ENUM('EMITIDA','PARCIALMENTE_ANULADA','ANULADA')
  NOT NULL DEFAULT 'EMITIDA';

CREATE TABLE IF NOT EXISTS `venta_anulaciones` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `venta_id` INT(11) NOT NULL,
  `tipo` ENUM('TOTAL','PARCIAL') NOT NULL DEFAULT 'PARCIAL',
  `estado` ENUM('CONFIRMADA','CANCELADA') NOT NULL DEFAULT 'CONFIRMADA',
  `motivo` VARCHAR(255) DEFAULT NULL,
  `monto_bruto` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `monto_neto` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `monto_iva` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `monto_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `anulado_por` INT(11) DEFAULT NULL,
  `anulado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_va_venta_id` (`venta_id`),
  KEY `idx_va_estado` (`estado`),
  KEY `idx_va_fecha` (`anulado_en`),
  KEY `idx_va_usuario` (`anulado_por`),
  CONSTRAINT `fk_va_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_va_user` FOREIGN KEY (`anulado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `venta_anulacion_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `anulacion_id` INT(11) NOT NULL,
  `venta_item_id` INT(11) NOT NULL,
  `producto_id` INT(11) NOT NULL,
  `cantidad_anulada` DECIMAL(10,3) NOT NULL,
  `precio_unitario_snapshot` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `descuento_monto_snapshot` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `iva_porcentaje_snapshot` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `subtotal_snapshot` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `subtotal_anulado` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_vai_anulacion_id` (`anulacion_id`),
  KEY `idx_vai_venta_item_id` (`venta_item_id`),
  KEY `idx_vai_producto_id` (`producto_id`),
  CONSTRAINT `fk_vai_anulacion` FOREIGN KEY (`anulacion_id`) REFERENCES `venta_anulaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vai_venta_item` FOREIGN KEY (`venta_item_id`) REFERENCES `venta_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vai_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`nombre`, `slug`, `created_at`)
VALUES ('Anular items de venta', 'anular_items_venta', NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`);

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`slug` = 'anular_items_venta'
WHERE LOWER(r.`slug`) IN ('admin', 'administrador');
