-- migrations/007_support_modules_schema.sql
-- Versiona tablas/permisos de modulos de soporte para upgrades sobre instalaciones existentes.

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS cc_habilitado TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Si el cliente tiene cuenta corriente habilitada',
  ADD COLUMN IF NOT EXISTS cc_limite DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Limite de credito en pesos',
  ADD COLUMN IF NOT EXISTS cc_saldo DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo cacheado de cuenta corriente',
  ADD COLUMN IF NOT EXISTS cc_fecha_ultimo_pago DATE DEFAULT NULL COMMENT 'Fecha del ultimo pago recibido',
  ADD COLUMN IF NOT EXISTS cc_notas TEXT DEFAULT NULL COMMENT 'Notas internas sobre la cuenta corriente';

ALTER TABLE clientes
  ADD INDEX idx_clientes_cc (cc_habilitado, cc_saldo);

ALTER TABLE clientes
  ADD INDEX idx_clientes_cc_alerta (cc_habilitado, cc_limite, cc_saldo);

CREATE TABLE IF NOT EXISTS cuenta_corriente_movimientos (
  id INT(11) NOT NULL AUTO_INCREMENT,
  cliente_id INT(11) NOT NULL,
  tipo ENUM('CARGO','PAGO','AJUSTE','AJUSTE_POS','AJUSTE_NEG','ANULACION','REVERSA') NOT NULL,
  estado ENUM('ACTIVO','ANULADO') NOT NULL DEFAULT 'ACTIVO',
  monto DECIMAL(12,2) NOT NULL,
  saldo_anterior DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  saldo_posterior DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  venta_id INT(11) DEFAULT NULL,
  concepto VARCHAR(255) DEFAULT NULL,
  medio_pago VARCHAR(50) DEFAULT NULL,
  referencia VARCHAR(100) DEFAULT NULL,
  reversa_de_id INT(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT(11) DEFAULT NULL,
  autorizado_por INT(11) DEFAULT NULL COMMENT 'Usuario que autorizo exceder limite',
  caja_id INT(11) DEFAULT NULL,
  caja_movimiento_id INT(11) DEFAULT NULL COMMENT 'ID del movimiento de caja generado al cobrar',
  terminal_id INT(11) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ccm_cliente (cliente_id),
  KEY idx_ccm_fecha (created_at),
  KEY idx_ccm_reversa (reversa_de_id),
  KEY idx_cc_mov_estado (estado),
  KEY idx_ccm_created_by (created_by),
  KEY idx_cc_mov_caja_mov (caja_movimiento_id),
  CONSTRAINT fk_ccm_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
  CONSTRAINT fk_ccm_reversa FOREIGN KEY (reversa_de_id) REFERENCES cuenta_corriente_movimientos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventario_sesiones (
  id INT(11) NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(120) NOT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  categoria_id INT(11) DEFAULT NULL,
  estado ENUM('ABIERTA','CERRADA','APLICADA') NOT NULL DEFAULT 'ABIERTA',
  created_by INT(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by INT(11) DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  cierre_motivo VARCHAR(255) DEFAULT NULL,
  applied_by INT(11) DEFAULT NULL,
  applied_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_estado (estado),
  KEY idx_created_at (created_at),
  KEY idx_categoria (categoria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE inventario_sesiones
  ADD COLUMN IF NOT EXISTS categoria_id INT(11) DEFAULT NULL AFTER descripcion;

CREATE TABLE IF NOT EXISTS inventario_conteos (
  id INT(11) NOT NULL AUTO_INCREMENT,
  sesion_id INT(11) NOT NULL,
  producto_id INT(11) NOT NULL,
  cantidad DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  ubicacion VARCHAR(120) DEFAULT NULL,
  notas VARCHAR(255) DEFAULT NULL,
  created_by INT(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sesion (sesion_id),
  KEY idx_producto (producto_id),
  KEY idx_created_at (created_at),
  CONSTRAINT fk_inv_conteos_sesion FOREIGN KEY (sesion_id) REFERENCES inventario_sesiones (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS producto_precios_hist (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_id INT(10) UNSIGNED NOT NULL,
  tipo ENUM('VENTA','COSTO') DEFAULT 'VENTA',
  precio_anterior DECIMAL(12,2) NOT NULL,
  precio_nuevo DECIMAL(12,2) NOT NULL,
  diferencia DECIMAL(12,2) NOT NULL,
  diferencia_pct DECIMAL(8,2) DEFAULT NULL,
  motivo VARCHAR(255) DEFAULT NULL,
  user_id INT(10) UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_producto (producto_id),
  KEY idx_tipo (tipo),
  KEY idx_created (created_at),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS producto_reposicion (
  producto_id INT(10) UNSIGNED NOT NULL,
  stock_minimo DECIMAL(12,3) DEFAULT NULL,
  stock_maximo DECIMAL(12,3) DEFAULT NULL,
  punto_reorden DECIMAL(12,3) DEFAULT NULL,
  proveedor_id INT(10) UNSIGNED DEFAULT NULL,
  dias_reposicion INT(11) DEFAULT 7,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (producto_id),
  KEY idx_proveedor (proveedor_id),
  KEY idx_minimo (stock_minimo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS caja_auditoria (
  caja_id INT(11) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  nota TEXT DEFAULT NULL,
  audited_by INT(11) DEFAULT NULL,
  audited_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (caja_id),
  KEY idx_status (status),
  KEY idx_audited_by (audited_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS factura_manual_items (
  id INT(11) NOT NULL AUTO_INCREMENT,
  venta_id INT(11) NOT NULL,
  codigo VARCHAR(80) DEFAULT NULL,
  descripcion VARCHAR(255) NOT NULL,
  cantidad DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  precio_unitario DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  iva_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 21.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_factura_manual_items_venta (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (slug, nombre, created_at)
VALUES
  ('ver_proveedores', 'Ver proveedores', NOW()),
  ('editar_proveedores', 'Editar proveedores', NOW()),
  ('ver_cuenta_corriente', 'Ver cuenta corriente', NOW()),
  ('registrar_cargo_cc', 'Caja: vender en CC', NOW()),
  ('registrar_pago_cc', 'Registrar pago CC', NOW()),
  ('ajustar_cc', 'Ajustar cuenta corriente', NOW()),
  ('habilitar_cc', 'Habilitar cuenta corriente', NOW()),
  ('vender_excedido_cc', 'Vender excedido en CC', NOW()),
  ('anular_movimiento_cc', 'Anular movimiento CC', NOW()),
  ('recalcular_saldo_cc', 'Recalcular saldo CC', NOW()),
  ('gestionar_stock', 'Gestionar stock', NOW()),
  ('ver_diagnostico', 'Ver diagnostico', NOW())
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre);

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
  ON p.slug IN (
    'ver_proveedores',
    'editar_proveedores',
    'ver_cuenta_corriente',
    'registrar_cargo_cc',
    'registrar_pago_cc',
    'ajustar_cc',
    'habilitar_cc',
    'vender_excedido_cc',
    'anular_movimiento_cc',
    'recalcular_saldo_cc',
    'gestionar_stock',
    'ver_diagnostico'
  )
WHERE LOWER(r.slug) IN ('admin', 'administrador');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT rp.role_id, p_diag.id
FROM role_permission rp
JOIN permissions p_legacy
  ON p_legacy.id = rp.permission_id
 AND p_legacy.slug = 'gestionar_backups'
JOIN permissions p_diag
  ON p_diag.slug = 'ver_diagnostico';
