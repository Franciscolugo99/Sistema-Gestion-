INSERT INTO permissions (slug, nombre, created_at)
VALUES ('ver_diagnostico', 'Ver diagnostico', NOW())
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre);

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT rp.role_id, p_diag.id
FROM role_permission rp
JOIN permissions p_legacy
  ON p_legacy.id = rp.permission_id
 AND p_legacy.slug = 'gestionar_backups'
JOIN permissions p_diag
  ON p_diag.slug = 'ver_diagnostico';
