-- Endurece movimientos_stock para anulaciones especificas y snapshots de stock.
-- El codigo ya distingue anulacion de venta y anulacion de compra en reportes.

ALTER TABLE `movimientos_stock`
  MODIFY COLUMN `tipo` ENUM(
    'VENTA',
    'COMPRA',
    'AJUSTE_POSITIVO',
    'AJUSTE_NEGATIVO',
    'ANULACION',
    'ANULACION_VENTA',
    'ANULACION_COMPRA',
    'DEVOLUCION'
  ) NOT NULL;

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
