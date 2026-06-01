SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas'
    )
    AND NOT EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'cae'
    ),
    'ALTER TABLE `facturas` ADD COLUMN `cae` VARCHAR(20) DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas'
    )
    AND NOT EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'cae_vto'
    ),
    'ALTER TABLE `facturas` ADD COLUMN `cae_vto` VARCHAR(10) DEFAULT NULL AFTER `cae`',
    'SELECT 1'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME IN ('cae','estado_fiscal','punto_venta','numero','naturaleza','tipo')
      GROUP BY TABLE_NAME
      HAVING COUNT(DISTINCT COLUMN_NAME) = 6
    ),
    'UPDATE facturas
     SET estado_fiscal = ''AUTORIZADA''
     WHERE COALESCE(TRIM(cae), '''') <> ''''
       AND COALESCE(TRIM(estado_fiscal), ''NO_APLICA'') IN ('''', ''NO_APLICA'')
       AND COALESCE(punto_venta, 0) > 0
       AND COALESCE(numero, 0) > 0
       AND (
         COALESCE(TRIM(naturaleza), ''FACTURA'') = ''FACTURA''
         OR UPPER(COALESCE(TRIM(tipo), '''')) NOT IN (''NCA'', ''NCB'', ''NCC'', ''NDA'', ''NDB'', ''NDC'')
       )',
    'SELECT 1'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
