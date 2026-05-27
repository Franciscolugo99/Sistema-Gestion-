-- FLUS 4.x - backfill seguro de permisos operativos del rol cajero.
-- No revoca permisos personalizados: solo asegura el minimo funcional por slug.

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'abrir_caja',
  'cerrar_caja',
  'realizar_ventas',
  'ver_clientes',
  'ver_stock',
  'registrar_cargo_cc'
)
WHERE r.slug = 'cajero';

