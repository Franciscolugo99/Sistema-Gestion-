-- Repara instalaciones actualizadas donde permisos operativos existen en codigo
-- pero quedaron faltando en la base o en el rol administrador.

INSERT INTO `permissions` (`nombre`, `slug`, `created_at`)
SELECT 'Emitir nota de credito', 'emitir_nota_credito', NOW()
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `slug` = 'emitir_nota_credito');

INSERT INTO `permissions` (`nombre`, `slug`, `created_at`)
SELECT 'Ver tesoreria', 'ver_tesoreria', NOW()
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `slug` = 'ver_tesoreria');

INSERT INTO `permissions` (`nombre`, `slug`, `created_at`)
SELECT 'Gestionar tesoreria', 'gestionar_tesoreria', NOW()
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `slug` = 'gestionar_tesoreria');

INSERT INTO `permissions` (`nombre`, `slug`, `created_at`)
SELECT 'Ver reportes de tesoreria', 'ver_reportes_tesoreria', NOW()
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `slug` = 'ver_reportes_tesoreria');

INSERT INTO `role_permission` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p
WHERE LOWER(r.`slug`) IN ('admin', 'administrador')
  AND p.`slug` IN (
    'emitir_nota_credito',
    'ver_tesoreria',
    'gestionar_tesoreria',
    'ver_reportes_tesoreria'
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `role_permission` rp
    WHERE rp.`role_id` = r.`id`
      AND rp.`permission_id` = p.`id`
  );
