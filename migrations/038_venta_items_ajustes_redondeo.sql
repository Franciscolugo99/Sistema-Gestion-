-- FLUS 4.1.0 - trazabilidad del redondeo aplicado por reglas de precio.

SET @ventas_redondeo_total_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'ajuste_precio_redondeo_total'
);
SET @sql_add_ventas_redondeo_total = IF(
    @ventas_redondeo_total_exists = 0,
    'ALTER TABLE `ventas` ADD COLUMN `ajuste_precio_redondeo_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_ventas_redondeo_total; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_regla_unit_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_regla_unit_monto'
);
SET @sql_add_vi_regla_unit = IF(
    @vi_regla_unit_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_regla_unit_monto` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_regla_unit; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_redondeo_modo_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_redondeo_modo'
);
SET @sql_add_vi_redondeo_modo = IF(
    @vi_redondeo_modo_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_redondeo_modo` VARCHAR(30) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_redondeo_modo; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_redondeo_unit_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_redondeo_unit_monto'
);
SET @sql_add_vi_redondeo_unit = IF(
    @vi_redondeo_unit_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_redondeo_unit_monto` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_redondeo_unit; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_redondeo_total_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_redondeo_total'
);
SET @sql_add_vi_redondeo_total = IF(
    @vi_redondeo_total_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_redondeo_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_redondeo_total; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
