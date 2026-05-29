-- FLUS 4.1.0 - trazabilidad generica de ajustes automaticos de precio.
-- Mantiene los totales existentes y agrega auditoria para reglas como recargo horario.

SET @ventas_ajuste_aplicado_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'ajuste_precio_aplicado'
);
SET @sql_add_ventas_ajuste_aplicado = IF(
    @ventas_ajuste_aplicado_exists = 0,
    'ALTER TABLE `ventas` ADD COLUMN `ajuste_precio_aplicado` TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_ventas_ajuste_aplicado; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @ventas_ajuste_total_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'ajuste_precio_total'
);
SET @sql_add_ventas_ajuste_total = IF(
    @ventas_ajuste_total_exists = 0,
    'ALTER TABLE `ventas` ADD COLUMN `ajuste_precio_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_ventas_ajuste_total; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_precio_base_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'precio_unit_base'
);
SET @sql_add_vi_precio_base = IF(
    @vi_precio_base_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `precio_unit_base` DECIMAL(12,2) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_precio_base; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_ajuste_tipo_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_tipo'
);
SET @sql_add_vi_ajuste_tipo = IF(
    @vi_ajuste_tipo_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_tipo` VARCHAR(30) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_ajuste_tipo; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_ajuste_origen_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_origen'
);
SET @sql_add_vi_ajuste_origen = IF(
    @vi_ajuste_origen_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_origen` VARCHAR(40) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_ajuste_origen; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_ajuste_nombre_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_nombre'
);
SET @sql_add_vi_ajuste_nombre = IF(
    @vi_ajuste_nombre_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_nombre` VARCHAR(100) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_ajuste_nombre; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_ajuste_pct_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_pct'
);
SET @sql_add_vi_ajuste_pct = IF(
    @vi_ajuste_pct_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_pct` DECIMAL(8,3) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_ajuste_pct; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_ajuste_unit_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_unit_monto'
);
SET @sql_add_vi_ajuste_unit = IF(
    @vi_ajuste_unit_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_unit_monto` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_ajuste_unit; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vi_ajuste_total_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND COLUMN_NAME = 'ajuste_precio_total'
);
SET @sql_add_vi_ajuste_total = IF(
    @vi_ajuste_total_exists = 0,
    'ALTER TABLE `venta_items` ADD COLUMN `ajuste_precio_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vi_ajuste_total; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_ventas_ajuste_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND INDEX_NAME = 'idx_ventas_ajuste_precio'
);
SET @sql_add_idx_ventas_ajuste = IF(
    @idx_ventas_ajuste_exists = 0,
    'ALTER TABLE `ventas` ADD KEY `idx_ventas_ajuste_precio` (`ajuste_precio_aplicado`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_ventas_ajuste; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_vi_ajuste_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_items' AND INDEX_NAME = 'idx_venta_items_ajuste_precio'
);
SET @sql_add_idx_vi_ajuste = IF(
    @idx_vi_ajuste_exists = 0,
    'ALTER TABLE `venta_items` ADD KEY `idx_venta_items_ajuste_precio` (`ajuste_precio_tipo`, `ajuste_precio_origen`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_vi_ajuste; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
