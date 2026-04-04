-- Repara instalaciones donde quedaron faltando permisos nuevos en DB
-- aunque ya existan en install.sql / codigo / migraciones previas.

INSERT INTO `permissions` (`nombre`, `slug`, `created_at`)
VALUES
  ('Emitir nota de crédito', 'emitir_nota_credito', NOW()),
  ('Anular items de venta', 'anular_items_venta', NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`);

-- Admin debe conservar acceso total.
INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p
WHERE LOWER(r.`slug`) IN ('admin', 'administrador')
  AND p.`slug` IN ('emitir_nota_credito', 'anular_items_venta');

-- Si un rol ya podia emitir factura, tambien debe poder emitir NC.
INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT rp.`role_id`, pNc.`id`
FROM `permissions` pEmit
JOIN `permissions` pNc
  ON pNc.`slug` = 'emitir_nota_credito'
JOIN `role_permission` rp
  ON rp.`permission_id` = pEmit.`id`
WHERE pEmit.`slug` = 'emitir_factura';

-- Si un rol ya podia anular la venta completa, tambien debe poder anular items.
INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT rp.`role_id`, pItems.`id`
FROM `permissions` pVenta
JOIN `permissions` pItems
  ON pItems.`slug` = 'anular_items_venta'
JOIN `role_permission` rp
  ON rp.`permission_id` = pVenta.`id`
WHERE pVenta.`slug` = 'anular_venta';
