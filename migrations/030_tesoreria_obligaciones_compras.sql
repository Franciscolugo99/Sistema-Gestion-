-- Vincula obligaciones de tesoreria con compras/proveedores.
-- No crea pagos automaticos: solo permite registrar deuda pendiente de forma idempotente.

SET @tes_obl_external_key_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tesoreria_obligaciones' AND COLUMN_NAME = 'external_key'
);
SET @sql_add_tes_obl_external_key = IF(
    @tes_obl_external_key_exists = 0,
    'ALTER TABLE `tesoreria_obligaciones` ADD COLUMN `external_key` VARCHAR(191) DEFAULT NULL AFTER `id`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_tes_obl_external_key; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @tes_obl_entidad_tipo_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tesoreria_obligaciones' AND COLUMN_NAME = 'entidad_tipo'
);
SET @sql_add_tes_obl_entidad_tipo = IF(
    @tes_obl_entidad_tipo_exists = 0,
    'ALTER TABLE `tesoreria_obligaciones` ADD COLUMN `entidad_tipo` VARCHAR(40) DEFAULT NULL AFTER `observaciones`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_tes_obl_entidad_tipo; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @tes_obl_entidad_id_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tesoreria_obligaciones' AND COLUMN_NAME = 'entidad_id'
);
SET @sql_add_tes_obl_entidad_id = IF(
    @tes_obl_entidad_id_exists = 0,
    'ALTER TABLE `tesoreria_obligaciones` ADD COLUMN `entidad_id` INT(11) DEFAULT NULL AFTER `entidad_tipo`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_tes_obl_entidad_id; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @tes_obl_proveedor_id_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tesoreria_obligaciones' AND COLUMN_NAME = 'proveedor_id'
);
SET @sql_add_tes_obl_proveedor_id = IF(
    @tes_obl_proveedor_id_exists = 0,
    'ALTER TABLE `tesoreria_obligaciones` ADD COLUMN `proveedor_id` INT(11) DEFAULT NULL AFTER `entidad_id`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_tes_obl_proveedor_id; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @tes_obl_compra_id_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tesoreria_obligaciones' AND COLUMN_NAME = 'compra_id'
);
SET @sql_add_tes_obl_compra_id = IF(
    @tes_obl_compra_id_exists = 0,
    'ALTER TABLE `tesoreria_obligaciones` ADD COLUMN `compra_id` INT(11) DEFAULT NULL AFTER `proveedor_id`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_tes_obl_compra_id; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @ux_tes_obl_external_key_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tesoreria_obligaciones' AND INDEX_NAME = 'ux_tes_obl_external_key'
);
SET @sql_add_ux_tes_obl_external_key = IF(
    @ux_tes_obl_external_key_exists = 0,
    'ALTER TABLE `tesoreria_obligaciones` ADD UNIQUE KEY `ux_tes_obl_external_key` (`external_key`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_ux_tes_obl_external_key; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_tes_obl_entidad_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tesoreria_obligaciones' AND INDEX_NAME = 'idx_tes_obl_entidad'
);
SET @sql_add_idx_tes_obl_entidad = IF(
    @idx_tes_obl_entidad_exists = 0,
    'ALTER TABLE `tesoreria_obligaciones` ADD KEY `idx_tes_obl_entidad` (`entidad_tipo`, `entidad_id`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_tes_obl_entidad; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_tes_obl_proveedor_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tesoreria_obligaciones' AND INDEX_NAME = 'idx_tes_obl_proveedor'
);
SET @sql_add_idx_tes_obl_proveedor = IF(
    @idx_tes_obl_proveedor_exists = 0,
    'ALTER TABLE `tesoreria_obligaciones` ADD KEY `idx_tes_obl_proveedor` (`proveedor_id`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_tes_obl_proveedor; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_tes_obl_compra_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tesoreria_obligaciones' AND INDEX_NAME = 'idx_tes_obl_compra'
);
SET @sql_add_idx_tes_obl_compra = IF(
    @idx_tes_obl_compra_exists = 0,
    'ALTER TABLE `tesoreria_obligaciones` ADD KEY `idx_tes_obl_compra` (`compra_id`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_tes_obl_compra; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

INSERT INTO `tesoreria_categorias` (`nombre`, `slug`, `tipo`, `orden`, `observaciones`, `created_at`, `updated_at`) VALUES
  ('Compras de mercaderia', 'compras-mercaderia', 'EGRESO', 15, 'Obligaciones generadas desde compras confirmadas.', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `tipo` = VALUES(`tipo`),
  `orden` = VALUES(`orden`),
  `estado` = 'ACTIVA',
  `updated_at` = NOW();
