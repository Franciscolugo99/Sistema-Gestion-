-- Compatibilidad para export/import de catalogo entre sucursales en bases actualizadas desde versiones viejas.

SET @proveedores_razon_social_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'razon_social'
);
SET @sql_add_proveedores_razon_social = IF(
    @proveedores_razon_social_exists = 0,
    'ALTER TABLE `proveedores` ADD COLUMN `razon_social` VARCHAR(150) DEFAULT NULL AFTER `nombre`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_proveedores_razon_social; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @proveedores_contacto_nombre_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'contacto_nombre'
);
SET @sql_add_proveedores_contacto_nombre = IF(
    @proveedores_contacto_nombre_exists = 0,
    'ALTER TABLE `proveedores` ADD COLUMN `contacto_nombre` VARCHAR(100) DEFAULT NULL AFTER `cuit`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_proveedores_contacto_nombre; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @proveedores_whatsapp_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'whatsapp'
);
SET @sql_add_proveedores_whatsapp = IF(
    @proveedores_whatsapp_exists = 0,
    'ALTER TABLE `proveedores` ADD COLUMN `whatsapp` VARCHAR(20) DEFAULT NULL AFTER `email`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_proveedores_whatsapp; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @proveedores_ciudad_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'ciudad'
);
SET @sql_add_proveedores_ciudad = IF(
    @proveedores_ciudad_exists = 0,
    'ALTER TABLE `proveedores` ADD COLUMN `ciudad` VARCHAR(100) DEFAULT NULL AFTER `direccion`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_proveedores_ciudad; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @proveedores_provincia_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'provincia'
);
SET @sql_add_proveedores_provincia = IF(
    @proveedores_provincia_exists = 0,
    'ALTER TABLE `proveedores` ADD COLUMN `provincia` VARCHAR(100) DEFAULT NULL AFTER `ciudad`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_proveedores_provincia; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @proveedores_dias_pago_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'dias_pago'
);
SET @sql_add_proveedores_dias_pago = IF(
    @proveedores_dias_pago_exists = 0,
    'ALTER TABLE `proveedores` ADD COLUMN `dias_pago` TINYINT(3) UNSIGNED DEFAULT 0 AFTER `provincia`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_proveedores_dias_pago; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @proveedores_descuento_habitual_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'descuento_habitual'
);
SET @sql_add_proveedores_descuento_habitual = IF(
    @proveedores_descuento_habitual_exists = 0,
    'ALTER TABLE `proveedores` ADD COLUMN `descuento_habitual` DECIMAL(5,2) DEFAULT 0.00 AFTER `dias_pago`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_proveedores_descuento_habitual; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @proveedores_notas_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'notas'
);
SET @sql_add_proveedores_notas = IF(
    @proveedores_notas_exists = 0,
    'ALTER TABLE `proveedores` ADD COLUMN `notas` TEXT DEFAULT NULL AFTER `descuento_habitual`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_proveedores_notas; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
