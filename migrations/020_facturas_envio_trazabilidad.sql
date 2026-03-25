-- Fase 5 acotada: trazabilidad minima de envio/reenvio de facturas.
-- No destructiva. No toca baseline ni reemision fiscal.

SET @envio_ultimo_canal_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'envio_ultimo_canal'
);
SET @sql_add_envio_ultimo_canal = IF(
    @envio_ultimo_canal_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `envio_ultimo_canal` VARCHAR(30) DEFAULT NULL AFTER `fiscal_approved_at`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_envio_ultimo_canal; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @envio_ultimo_destino_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'envio_ultimo_destino'
);
SET @sql_add_envio_ultimo_destino = IF(
    @envio_ultimo_destino_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `envio_ultimo_destino` VARCHAR(190) DEFAULT NULL AFTER `envio_ultimo_canal`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_envio_ultimo_destino; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @envio_ultimo_estado_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'envio_ultimo_estado'
);
SET @sql_add_envio_ultimo_estado = IF(
    @envio_ultimo_estado_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `envio_ultimo_estado` VARCHAR(30) DEFAULT NULL AFTER `envio_ultimo_destino`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_envio_ultimo_estado; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @envio_ultimo_error_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'envio_ultimo_error'
);
SET @sql_add_envio_ultimo_error = IF(
    @envio_ultimo_error_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `envio_ultimo_error` TEXT DEFAULT NULL AFTER `envio_ultimo_estado`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_envio_ultimo_error; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @envio_ultimo_at_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'envio_ultimo_at'
);
SET @sql_add_envio_ultimo_at = IF(
    @envio_ultimo_at_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `envio_ultimo_at` DATETIME DEFAULT NULL AFTER `envio_ultimo_error`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_envio_ultimo_at; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @envio_intentos_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'envio_intentos'
);
SET @sql_add_envio_intentos = IF(
    @envio_intentos_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `envio_intentos` INT(11) NOT NULL DEFAULT 0 AFTER `envio_ultimo_at`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_envio_intentos; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_facturas_envio_estado_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND INDEX_NAME = 'idx_facturas_envio_estado'
);
SET @sql_add_idx_facturas_envio_estado = IF(
    @idx_facturas_envio_estado_exists = 0,
    'ALTER TABLE `facturas` ADD KEY `idx_facturas_envio_estado` (`envio_ultimo_estado`, `envio_ultimo_at`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_facturas_envio_estado; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
