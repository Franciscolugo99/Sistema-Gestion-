-- Ajusta la unicidad de NC por anulacion para convivir con demo/homologacion/produccion.
-- No destructiva a nivel de datos: solo cambia el indice unico.

ALTER TABLE `facturas`
  ADD COLUMN IF NOT EXISTS `modo` VARCHAR(20) NOT NULL DEFAULT 'demo' AFTER `estado`;

UPDATE `facturas`
SET `modo` = 'demo'
WHERE `modo` IS NULL OR TRIM(`modo`) = '';

ALTER TABLE `facturas`
  MODIFY COLUMN `modo` VARCHAR(20) NOT NULL DEFAULT 'demo';

ALTER TABLE `facturas`
  DROP INDEX `ux_facturas_nc_por_anulacion`;

ALTER TABLE `facturas`
  ADD UNIQUE KEY `ux_facturas_nc_por_anulacion` (`venta_anulacion_id`, `modo`);
