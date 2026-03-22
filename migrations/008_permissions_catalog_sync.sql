-- Sincroniza el catalogo actual de permisos y garantiza acceso total al rol admin.

INSERT INTO `permissions` (`nombre`, `slug`, `created_at`) VALUES
  ('Ver costos', 'ver_costos', NOW()),
  ('Editar productos', 'editar_productos', NOW()),
  ('Editar stock', 'editar_stock', NOW()),
  ('Abrir caja', 'abrir_caja', NOW()),
  ('Cerrar caja', 'cerrar_caja', NOW()),
  ('Ver reportes', 'ver_reportes', NOW()),
  ('Administrar usuarios', 'administrar_usuarios', NOW()),
  ('Ver movimientos', 'ver_movimientos', NOW()),
  ('Realizar ventas', 'realizar_ventas', NOW()),
  ('Ver historial de caja', 'ver_historial_caja', NOW()),
  ('Administrar configuracion', 'administrar_config', NOW()),
  ('Modificar precio en caja', 'caja_modificar_precio', NOW()),
  ('Anular ventas', 'anular_venta', NOW()),
  ('Ver auditoria', 'ver_auditoria', NOW()),
  ('Gestionar backups', 'gestionar_backups', NOW()),
  ('Ver clientes', 'ver_clientes', NOW()),
  ('Editar clientes', 'editar_clientes', NOW()),
  ('Ver facturacion', 'ver_facturacion', NOW()),
  ('Emitir factura', 'emitir_factura', NOW()),
  ('Editar promociones', 'editar_promos', NOW()),
  ('Ver productos', 'ver_productos', NOW()),
  ('Ver stock', 'ver_stock', NOW()),
  ('Ver proveedores', 'ver_proveedores', NOW()),
  ('Editar proveedores', 'editar_proveedores', NOW()),
  ('Ver cuenta corriente', 'ver_cuenta_corriente', NOW()),
  ('Caja: vender en CC', 'registrar_cargo_cc', NOW()),
  ('Registrar pago CC', 'registrar_pago_cc', NOW()),
  ('Ajustar cuenta corriente', 'ajustar_cc', NOW()),
  ('Habilitar cuenta corriente', 'habilitar_cc', NOW()),
  ('Vender excedido en CC', 'vender_excedido_cc', NOW()),
  ('Anular movimiento CC', 'anular_movimiento_cc', NOW()),
  ('Recalcular saldo CC', 'recalcular_saldo_cc', NOW()),
  ('Gestionar stock', 'gestionar_stock', NOW()),
  ('Ver diagnostico', 'ver_diagnostico', NOW()),
  ('Anular items de venta', 'anular_items_venta', NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`);

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p
WHERE LOWER(r.`slug`) IN ('admin', 'administrador');

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT rp.`role_id`, pDiag.`id`
FROM `permissions` pDiag
JOIN `permissions` pBackups
  ON pBackups.`slug` = 'gestionar_backups'
JOIN `role_permission` rp
  ON rp.`permission_id` = pBackups.`id`
WHERE pDiag.`slug` = 'ver_diagnostico';
