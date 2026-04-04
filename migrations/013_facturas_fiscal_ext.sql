-- Fase 1 tecnica: ampliar facturas para soportar FACTURA / NC / ND
-- No destructiva. Tolera instalaciones viejas donde facturas no tenia
-- exactamente el baseline actual.

SET @db := DATABASE();

-- Algunas instalaciones legacy no traian importe_exento, pero el resto
-- del flujo fiscal ya lo asume como columna base.
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'importe_exento'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `importe_exento` DECIMAL(12,2) DEFAULT 0.00 AFTER `importe_iva`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'naturaleza'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `naturaleza` ENUM(''FACTURA'',''NC'',''ND'') NOT NULL DEFAULT ''FACTURA'' AFTER `cliente_id`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'tipo_cbte'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `tipo_cbte` INT(11) DEFAULT NULL AFTER `tipo`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'venta_anulacion_id'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `venta_anulacion_id` INT(11) DEFAULT NULL AFTER `venta_id`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'factura_asociada_id'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `factura_asociada_id` INT(11) DEFAULT NULL AFTER `venta_anulacion_id`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'comprobante_asoc_tipo_cbte'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `comprobante_asoc_tipo_cbte` INT(11) DEFAULT NULL AFTER `factura_asociada_id`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'comprobante_asoc_punto_venta'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `comprobante_asoc_punto_venta` INT(11) DEFAULT NULL AFTER `comprobante_asoc_tipo_cbte`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'comprobante_asoc_numero'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `comprobante_asoc_numero` INT(11) DEFAULT NULL AFTER `comprobante_asoc_punto_venta`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'comprobante_asoc_cuit'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `comprobante_asoc_cuit` VARCHAR(20) DEFAULT NULL AFTER `comprobante_asoc_numero`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'doc_tipo'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `doc_tipo` INT(11) DEFAULT NULL AFTER `comprobante_asoc_cuit`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'doc_numero'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `doc_numero` VARCHAR(20) DEFAULT NULL AFTER `doc_tipo`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'condicion_iva_receptor_id'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `condicion_iva_receptor_id` INT(11) DEFAULT NULL AFTER `doc_numero`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'importe_no_gravado'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `importe_no_gravado` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `importe_exento`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'moneda_id'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `moneda_id` VARCHAR(3) NOT NULL DEFAULT ''PES'' AFTER `importe_no_gravado`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND COLUMN_NAME = 'moneda_cotiz'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD COLUMN `moneda_cotiz` DECIMAL(12,6) NOT NULL DEFAULT 1.000000 AFTER `moneda_id`'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND INDEX_NAME = 'idx_facturas_naturaleza'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD KEY `idx_facturas_naturaleza` (`naturaleza`)'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND INDEX_NAME = 'idx_facturas_venta_anulacion'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD KEY `idx_facturas_venta_anulacion` (`venta_anulacion_id`)'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND INDEX_NAME = 'idx_facturas_factura_asociada'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD KEY `idx_facturas_factura_asociada` (`factura_asociada_id`)'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND INDEX_NAME = 'idx_facturas_tipo_cbte'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD KEY `idx_facturas_tipo_cbte` (`tipo_cbte`)'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND INDEX_NAME = 'idx_facturas_asoc_cbte'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD KEY `idx_facturas_asoc_cbte` (`comprobante_asoc_tipo_cbte`,`comprobante_asoc_punto_venta`,`comprobante_asoc_numero`)'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'facturas'
        AND INDEX_NAME = 'ux_facturas_nc_por_anulacion'
    ),
    'SELECT 1',
    'ALTER TABLE `facturas` ADD UNIQUE KEY `ux_facturas_nc_por_anulacion` (`venta_anulacion_id`)'
  )
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- No tocar venta_fiscal en esta fase.
-- No tocar el indice unico historico de facturas en esta fase.
