-- Control de turnos de caja por cajero.
-- Agrega datos de cierre sin afectar instalaciones donde el codigo ya funciona sin estas columnas.

SET @caja_cierre_motivo_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_sesiones' AND COLUMN_NAME = 'cierre_motivo'
);
SET @sql_add_caja_cierre_motivo = IF(
    @caja_cierre_motivo_exists = 0,
    'ALTER TABLE `caja_sesiones` ADD COLUMN `cierre_motivo` VARCHAR(40) DEFAULT NULL AFTER `notas`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_caja_cierre_motivo; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @caja_cerrado_por_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_sesiones' AND COLUMN_NAME = 'cerrado_por_user_id'
);
SET @sql_add_caja_cerrado_por = IF(
    @caja_cerrado_por_exists = 0,
    'ALTER TABLE `caja_sesiones` ADD COLUMN `cerrado_por_user_id` INT(11) DEFAULT NULL AFTER `fecha_cierre`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_caja_cerrado_por; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @caja_fondo_siguiente_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_sesiones' AND COLUMN_NAME = 'cierre_fondo_siguiente'
);
SET @sql_add_caja_fondo_siguiente = IF(
    @caja_fondo_siguiente_exists = 0,
    'ALTER TABLE `caja_sesiones` ADD COLUMN `cierre_fondo_siguiente` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `cierre_motivo`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_caja_fondo_siguiente; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @caja_retiro_efectivo_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_sesiones' AND COLUMN_NAME = 'cierre_retiro_efectivo'
);
SET @sql_add_caja_retiro_efectivo = IF(
    @caja_retiro_efectivo_exists = 0,
    'ALTER TABLE `caja_sesiones` ADD COLUMN `cierre_retiro_efectivo` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `cierre_fondo_siguiente`',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_caja_retiro_efectivo; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_caja_cerrado_por_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_sesiones' AND INDEX_NAME = 'idx_caja_cerrado_por'
);
SET @sql_add_idx_caja_cerrado_por = IF(
    @idx_caja_cerrado_por_exists = 0,
    'ALTER TABLE `caja_sesiones` ADD KEY `idx_caja_cerrado_por` (`cerrado_por_user_id`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_caja_cerrado_por; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
