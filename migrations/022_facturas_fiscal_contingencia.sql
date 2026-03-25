-- Fase 7 (mínima): endurece contingencia fiscal sin tocar install.sql (baseline + scripts/migrate.php).
-- Extiende estado_fiscal para distinguir ERROR_POST_ARCA y RECUPERADA en factura común.

SET @facturas_estado_fiscal_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'estado_fiscal'
);
SET @sql_facturas_estado_fiscal = IF(
    @facturas_estado_fiscal_exists = 1,
    'ALTER TABLE `facturas` MODIFY COLUMN `estado_fiscal` ENUM(''NO_APLICA'',''PENDIENTE_ENVIO'',''ERROR_TRANSITORIO'',''ERROR_POST_ARCA'',''AUTORIZADA'',''RECUPERADA'',''RECHAZADA'') NOT NULL DEFAULT ''NO_APLICA''',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_facturas_estado_fiscal; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
