-- migrations/004_facturas_unique_scope.sql
-- Separa la numeracion de facturas por modo para evitar choques entre demo, homologacion y produccion.

ALTER TABLE facturas
  ADD COLUMN IF NOT EXISTS modo VARCHAR(20) NOT NULL DEFAULT 'demo' AFTER estado;

UPDATE facturas
SET modo = 'demo'
WHERE modo IS NULL OR TRIM(modo) = '';

ALTER TABLE facturas
  MODIFY COLUMN modo VARCHAR(20) NOT NULL DEFAULT 'demo';

ALTER TABLE facturas
  DROP INDEX ux_facturas_numero;

ALTER TABLE facturas
  ADD UNIQUE KEY ux_facturas_numero (punto_venta, tipo, modo, numero);