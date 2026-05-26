-- Compatibilidad para modulos que exportan/importan clientes con razon social separada.

SET @clientes_razon_social_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'razon_social'
);
SET @clientes_nombre_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'nombre'
);
SET @sql_add_clientes_razon_social = IF(
    @clientes_razon_social_exists = 0,
    IF(
        @clientes_nombre_exists > 0,
        'ALTER TABLE `clientes` ADD COLUMN `razon_social` VARCHAR(255) DEFAULT NULL AFTER `nombre`',
        'ALTER TABLE `clientes` ADD COLUMN `razon_social` VARCHAR(255) DEFAULT NULL'
    ),
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_clientes_razon_social; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
