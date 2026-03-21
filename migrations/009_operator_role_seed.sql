-- Crea el rol Operador para negocios chicos y le asigna permisos operativos sin abrir administracion sensible.

INSERT INTO `roles` (`nombre`, `slug`, `created_at`)
VALUES ('Operador', 'operador', NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`);

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p
WHERE r.`slug` = 'operador'
  AND p.`slug` IN (
    'realizar_ventas',
    'cerrar_caja',
    'ver_clientes',
    'registrar_pago_cc',
    'registrar_cargo_cc',
    'ver_cuenta_corriente',
    'editar_stock',
    'ver_stock',
    'ver_proveedores',
    'editar_proveedores'
  );
