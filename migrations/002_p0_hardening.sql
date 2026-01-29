-- migrations/002_p0_hardening.sql
-- Hardening P0: ventas.uuid + limpiar FKs duplicadas (sin PREPARE)

ALTER TABLE `ventas` ADD COLUMN `uuid` VARCHAR(36) NULL;
ALTER TABLE `ventas` ADD UNIQUE KEY `uq_ventas_uuid` (`uuid`);

ALTER TABLE `venta_items` DROP FOREIGN KEY `venta_items_ibfk_1`;
ALTER TABLE `venta_items` DROP FOREIGN KEY `venta_items_ibfk_2`;
