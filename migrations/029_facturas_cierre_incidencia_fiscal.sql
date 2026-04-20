-- Cierre operativo de incidencias fiscales rechazadas.
-- No borra comprobantes ni altera trazas ARCA: solo permite sacarlos de la bandeja activa.

SET @fiscal_cerrada_at_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'fiscal_cerrada_at'
);
SET @sql_add_fiscal_cerrada_at = IF(
    @fiscal_cerrada_at_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `fiscal_cerrada_at` DATETIME DEFAULT NULL AFTER `fiscal_approved_at`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_fiscal_cerrada_at; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @fiscal_cerrada_por_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'fiscal_cerrada_por'
);
SET @sql_add_fiscal_cerrada_por = IF(
    @fiscal_cerrada_por_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `fiscal_cerrada_por` INT(11) DEFAULT NULL AFTER `fiscal_cerrada_at`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_fiscal_cerrada_por; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @fiscal_cierre_motivo_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'fiscal_cierre_motivo'
);
SET @sql_add_fiscal_cierre_motivo = IF(
    @fiscal_cierre_motivo_exists = 0,
    'ALTER TABLE `facturas` ADD COLUMN `fiscal_cierre_motivo` VARCHAR(255) DEFAULT NULL AFTER `fiscal_cerrada_por`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_fiscal_cierre_motivo; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_facturas_fiscal_cierre_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND INDEX_NAME = 'idx_facturas_fiscal_cierre'
);
SET @sql_add_idx_facturas_fiscal_cierre = IF(
    @idx_facturas_fiscal_cierre_exists = 0,
    'ALTER TABLE `facturas` ADD KEY `idx_facturas_fiscal_cierre` (`estado_fiscal`, `fiscal_cerrada_at`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_facturas_fiscal_cierre; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
