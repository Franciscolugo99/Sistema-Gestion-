-- Garantiza snapshots de stock en instalaciones actualizadas desde esquemas legacy.

SET @mov_stock_anterior_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_stock' AND COLUMN_NAME = 'stock_anterior'
);
SET @sql_add_mov_stock_anterior = IF(
    @mov_stock_anterior_exists = 0,
    'ALTER TABLE `movimientos_stock` ADD COLUMN `stock_anterior` DECIMAL(10,3) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_mov_stock_anterior; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @mov_stock_nuevo_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_stock' AND COLUMN_NAME = 'stock_nuevo'
);
SET @sql_add_mov_stock_nuevo = IF(
    @mov_stock_nuevo_exists = 0,
    'ALTER TABLE `movimientos_stock` ADD COLUMN `stock_nuevo` DECIMAL(10,3) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_mov_stock_nuevo; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

DROP TRIGGER IF EXISTS `before_insert_movimiento_stock`;

DELIMITER ;;
CREATE TRIGGER `before_insert_movimiento_stock` BEFORE INSERT ON `movimientos_stock` FOR EACH ROW
BEGIN
  DECLARE stock_actual DECIMAL(10,3);

  SELECT stock INTO stock_actual
  FROM productos
  WHERE id = NEW.producto_id;

  SET NEW.stock_anterior = stock_actual;

  CASE NEW.tipo
    WHEN 'COMPRA' THEN
      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;
    WHEN 'VENTA' THEN
      SET NEW.stock_nuevo = stock_actual - NEW.cantidad;
    WHEN 'AJUSTE_POSITIVO' THEN
      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;
    WHEN 'AJUSTE_NEGATIVO' THEN
      SET NEW.stock_nuevo = stock_actual - NEW.cantidad;
    WHEN 'ANULACION' THEN
      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;
    WHEN 'ANULACION_VENTA' THEN
      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;
    WHEN 'ANULACION_COMPRA' THEN
      SET NEW.stock_nuevo = stock_actual - NEW.cantidad;
    WHEN 'DEVOLUCION' THEN
      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;
    ELSE
      SET NEW.stock_nuevo = stock_actual;
  END CASE;
END;;
DELIMITER ;
