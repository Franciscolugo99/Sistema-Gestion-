-- migrations/011_cc_schema_compat.sql
-- Alinea el esquema usado por CuentaCorrienteController con instalaciones viejas
-- sin cambios destructivos ni FKs nuevas que puedan fallar por datos legacy.

SET @db := DATABASE();

-- =========================================================
-- cuenta_corriente_movimientos.autorizado_por
-- =========================================================
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'cuenta_corriente_movimientos'
        AND COLUMN_NAME = 'autorizado_por'
    ),
    'SELECT 1',
    'ALTER TABLE cuenta_corriente_movimientos
       ADD COLUMN autorizado_por INT(11) DEFAULT NULL
       COMMENT ''Usuario que autorizo exceder limite''
       AFTER created_by'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- cuenta_corriente_movimientos.caja_movimiento_id
-- =========================================================
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'cuenta_corriente_movimientos'
        AND COLUMN_NAME = 'caja_movimiento_id'
    ),
    'SELECT 1',
    'ALTER TABLE cuenta_corriente_movimientos
       ADD COLUMN caja_movimiento_id INT(11) DEFAULT NULL
       COMMENT ''ID del movimiento de caja generado al cobrar''
       AFTER caja_id'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'cuenta_corriente_movimientos'
        AND INDEX_NAME = 'idx_cc_mov_caja_mov'
    ),
    'SELECT 1',
    'CREATE INDEX idx_cc_mov_caja_mov
       ON cuenta_corriente_movimientos (caja_movimiento_id)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- caja_movimientos.cc_movimiento_id
-- =========================================================
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'caja_movimientos'
        AND COLUMN_NAME = 'cc_movimiento_id'
    ),
    'SELECT 1',
    'ALTER TABLE caja_movimientos
       ADD COLUMN cc_movimiento_id INT(11) DEFAULT NULL
       COMMENT ''Referencia al movimiento de CC que genero este ingreso'''
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'caja_movimientos'
        AND INDEX_NAME = 'idx_caja_mov_cc_mov'
    ),
    'SELECT 1',
    'CREATE INDEX idx_caja_mov_cc_mov
       ON caja_movimientos (cc_movimiento_id)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- caja_movimientos.medio_pago
-- =========================================================
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'caja_movimientos'
        AND COLUMN_NAME = 'medio_pago'
    ),
    'SELECT 1',
    'ALTER TABLE caja_movimientos
       ADD COLUMN medio_pago VARCHAR(30) DEFAULT NULL
       COMMENT ''EFECTIVO, MP, DEBITO, CREDITO, TRANSFERENCIA''
       AFTER tipo'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- caja_sesiones.total_transferencia
-- =========================================================
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'caja_sesiones'
        AND COLUMN_NAME = 'total_transferencia'
    ),
    'SELECT 1',
    'ALTER TABLE caja_sesiones
       ADD COLUMN total_transferencia DECIMAL(10,2) NOT NULL DEFAULT 0.00
       COMMENT ''Total de ingresos por transferencia bancaria''
       AFTER total_credito'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
