-- migrations/005_compras_descuentos_schema.sql
-- Mueve al sistema de migraciones las columnas de compras que antes se agregaban en runtime.

ALTER TABLE compras
  ADD COLUMN IF NOT EXISTS total_neto DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total;

ALTER TABLE compras
  ADD COLUMN IF NOT EXISTS total_iva DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_neto;

ALTER TABLE compras
  ADD COLUMN IF NOT EXISTS total_bruto DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_iva;

ALTER TABLE compras
  ADD COLUMN IF NOT EXISTS descuento_tipo VARCHAR(10) NOT NULL DEFAULT 'MONTO' AFTER total_bruto;

ALTER TABLE compras
  ADD COLUMN IF NOT EXISTS descuento_valor DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER descuento_tipo;

ALTER TABLE compras
  ADD COLUMN IF NOT EXISTS descuento_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER descuento_valor;

UPDATE compras
SET total_bruto = total
WHERE total_bruto = 0 AND total <> 0;

UPDATE compras
SET total_neto = total
WHERE total_neto = 0 AND total <> 0;

ALTER TABLE compra_items
  ADD COLUMN IF NOT EXISTS descuento DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal;

ALTER TABLE compra_items
  ADD COLUMN IF NOT EXISTS descuento_tipo VARCHAR(10) NOT NULL DEFAULT 'MONTO' AFTER descuento;

ALTER TABLE compra_items
  ADD COLUMN IF NOT EXISTS descuento_porc DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER descuento_tipo;
