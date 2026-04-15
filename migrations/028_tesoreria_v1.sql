-- Tesoreria v1 operativa: cuentas, categorias, movimientos y obligaciones.
-- No implementa contabilidad formal ni toca caja/cobranzas/cuenta corriente existentes.

CREATE TABLE IF NOT EXISTS `tesoreria_cuentas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(120) NOT NULL,
  `tipo` VARCHAR(30) NOT NULL DEFAULT 'OTRO',
  `sucursal_id` INT(11) DEFAULT NULL,
  `sucursal_nombre` VARCHAR(120) DEFAULT NULL,
  `saldo_inicial` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVA',
  `observaciones` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tes_cuentas_tipo` (`tipo`),
  KEY `idx_tes_cuentas_estado` (`estado`),
  KEY `idx_tes_cuentas_sucursal` (`sucursal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tesoreria_categorias` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `tipo` VARCHAR(20) NOT NULL DEFAULT 'EGRESO',
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVA',
  `orden` INT(11) NOT NULL DEFAULT 100,
  `observaciones` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_tes_categorias_slug` (`slug`),
  KEY `idx_tes_categorias_tipo` (`tipo`),
  KEY `idx_tes_categorias_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tesoreria_movimientos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `request_uid` VARCHAR(64) DEFAULT NULL,
  `tipo` VARCHAR(20) NOT NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  `cuenta_origen_id` INT(11) DEFAULT NULL,
  `cuenta_destino_id` INT(11) DEFAULT NULL,
  `categoria_id` INT(11) DEFAULT NULL,
  `sucursal_id` INT(11) DEFAULT NULL,
  `sucursal_nombre` VARCHAR(120) DEFAULT NULL,
  `fecha` DATETIME NOT NULL,
  `importe` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `concepto` VARCHAR(180) NOT NULL,
  `referencia` VARCHAR(120) DEFAULT NULL,
  `observaciones` VARCHAR(255) DEFAULT NULL,
  `entidad_tipo` VARCHAR(40) DEFAULT NULL,
  `entidad_id` INT(11) DEFAULT NULL,
  `obligacion_id` INT(11) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_tes_mov_request_uid` (`request_uid`),
  KEY `idx_tes_mov_tipo_fecha` (`tipo`, `fecha`),
  KEY `idx_tes_mov_estado` (`estado`),
  KEY `idx_tes_mov_cuenta_origen` (`cuenta_origen_id`),
  KEY `idx_tes_mov_cuenta_destino` (`cuenta_destino_id`),
  KEY `idx_tes_mov_categoria` (`categoria_id`),
  KEY `idx_tes_mov_sucursal` (`sucursal_id`),
  KEY `idx_tes_mov_obligacion` (`obligacion_id`),
  KEY `idx_tes_mov_entidad` (`entidad_tipo`, `entidad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tesoreria_obligaciones` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `descripcion` VARCHAR(180) NOT NULL,
  `categoria_id` INT(11) DEFAULT NULL,
  `sucursal_id` INT(11) DEFAULT NULL,
  `sucursal_nombre` VARCHAR(120) DEFAULT NULL,
  `fecha_vencimiento` DATE NOT NULL,
  `importe_estimado` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `importe_pagado` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  `cuenta_sugerida_id` INT(11) DEFAULT NULL,
  `movimiento_pago_id` INT(11) DEFAULT NULL,
  `observaciones` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tes_obl_estado_vto` (`estado`, `fecha_vencimiento`),
  KEY `idx_tes_obl_categoria` (`categoria_id`),
  KEY `idx_tes_obl_sucursal` (`sucursal_id`),
  KEY `idx_tes_obl_cuenta` (`cuenta_sugerida_id`),
  KEY `idx_tes_obl_mov_pago` (`movimiento_pago_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tesoreria_categorias` (`nombre`, `slug`, `tipo`, `orden`, `created_at`, `updated_at`) VALUES
  ('Alquiler', 'alquiler', 'EGRESO', 10, NOW(), NOW()),
  ('Impuestos', 'impuestos', 'EGRESO', 20, NOW(), NOW()),
  ('Servicios', 'servicios', 'EGRESO', 30, NOW(), NOW()),
  ('Sueldos', 'sueldos', 'EGRESO', 40, NOW(), NOW()),
  ('Mantenimiento', 'mantenimiento', 'EGRESO', 50, NOW(), NOW()),
  ('Marketing', 'marketing', 'EGRESO', 60, NOW(), NOW()),
  ('Comisiones', 'comisiones', 'EGRESO', 70, NOW(), NOW()),
  ('Retiros', 'retiros', 'EGRESO', 80, NOW(), NOW()),
  ('Ajustes', 'ajustes', 'AMBOS', 90, NOW(), NOW()),
  ('Otros', 'otros', 'AMBOS', 100, NOW(), NOW()),
  ('Aporte de capital', 'aporte-capital', 'INGRESO', 110, NOW(), NOW()),
  ('Ingreso extraordinario', 'ingreso-extraordinario', 'INGRESO', 120, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `tipo` = VALUES(`tipo`),
  `orden` = VALUES(`orden`),
  `updated_at` = NOW();

INSERT INTO `permissions` (`nombre`, `slug`, `created_at`) VALUES
  ('Ver tesoreria', 'ver_tesoreria', NOW()),
  ('Gestionar tesoreria', 'gestionar_tesoreria', NOW()),
  ('Ver reportes de tesoreria', 'ver_reportes_tesoreria', NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`);

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p
WHERE LOWER(r.`slug`) IN ('admin', 'administrador')
  AND p.`slug` IN ('ver_tesoreria', 'gestionar_tesoreria', 'ver_reportes_tesoreria');
