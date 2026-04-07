ALTER TABLE inventario_sesiones
  ADD COLUMN IF NOT EXISTS categoria_nombre VARCHAR(100) DEFAULT NULL AFTER categoria_id;

ALTER TABLE inventario_conteos
  ADD COLUMN IF NOT EXISTS stock_sistema_snapshot DECIMAL(10,3) DEFAULT NULL AFTER cantidad;
