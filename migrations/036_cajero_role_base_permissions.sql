-- FLUS 4.x - deja el rol cajero con la base operativa acordada.
-- Instalaciones existentes pueden tener permisos extra por ediciones previas;
-- esta migracion sincroniza solo el rol con slug 'cajero'.

DELETE rp
FROM role_permission rp
INNER JOIN roles r ON r.id = rp.role_id
INNER JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug = 'cajero'
  AND p.slug NOT IN (
    'realizar_ventas',
    'abrir_caja',
    'cerrar_caja',
    'ver_clientes',
    'ver_stock',
    'registrar_cargo_cc'
  );

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'realizar_ventas',
  'abrir_caja',
  'cerrar_caja',
  'ver_clientes',
  'ver_stock',
  'registrar_cargo_cc'
)
WHERE r.slug = 'cajero';

