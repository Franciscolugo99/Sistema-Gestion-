/* FLUS - Ventas mejoras (idempotente)
   - Agrega columnas si faltan
   - Crea tabla venta_tags
   - Crea indices si faltan
   - Crea/rehace vista v_ventas_completo (terminal_id mapeado desde caja_id o terminal_id)
*/

SET @db := DATABASE();

-- ========= Columnas en ventas (si faltan) =========
-- cliente_id
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='cliente_id');
SET @sql := IF(@c=0, 'ALTER TABLE ventas ADD COLUMN cliente_id INT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- notas
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='notas');
SET @sql := IF(@c=0, 'ALTER TABLE ventas ADD COLUMN notas TEXT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ultima_visualizacion
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='ultima_visualizacion');
SET @sql := IF(@c=0, 'ALTER TABLE ventas ADD COLUMN ultima_visualizacion DATETIME NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ========= Tabla venta_tags =========
CREATE TABLE IF NOT EXISTS venta_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  venta_id INT NOT NULL,
  tag VARCHAR(50) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_venta_tags_venta
    FOREIGN KEY (venta_id) REFERENCES ventas(id)
    ON DELETE CASCADE,
  UNIQUE KEY uk_venta_tag (venta_id, tag),
  KEY idx_venta_tags_venta (venta_id),
  KEY idx_venta_tags_tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========= Índices (solo si faltan) =========
-- idx_ventas_cliente_fecha
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND INDEX_NAME='idx_ventas_cliente_fecha');
SET @sql := IF(@c=0, 'CREATE INDEX idx_ventas_cliente_fecha ON ventas (cliente_id, fecha)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- idx_ventas_estado_fecha (solo si existe columna estado)
SET @has_estado := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='estado');
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND INDEX_NAME='idx_ventas_estado_fecha');
SET @sql := IF(@has_estado>0 AND @c=0, 'CREATE INDEX idx_ventas_estado_fecha ON ventas (estado, fecha)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- venta_items indices
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='venta_items' AND INDEX_NAME='idx_vi_venta');
SET @sql := IF(@c=0, 'CREATE INDEX idx_vi_venta ON venta_items (venta_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='venta_items' AND INDEX_NAME='idx_vi_producto_venta');
SET @sql := IF(@c=0, 'CREATE INDEX idx_vi_producto_venta ON venta_items (producto_id, venta_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ========= Vista v_ventas_completo (robusta) =========
-- Elegir columna de terminal: caja_id preferida, si no terminal_id, si no NULL
SET @has_caja := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='caja_id');
SET @has_term := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='terminal_id');
SET @termexpr := IF(@has_caja>0, 'v.caja_id', IF(@has_term>0, 'v.terminal_id', 'NULL'));

-- descuento_total: si existe columna en ventas, usarla; si no, sumar venta_items.descuento_monto
SET @has_desc_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='descuento_total');
SET @descexpr := IF(@has_desc_col>0,
  'v.descuento_total',
  '(SELECT COALESCE(SUM(vi2.descuento_monto),0) FROM venta_items vi2 WHERE vi2.venta_id = v.id)'
);

-- medio_pago/estado/monto_pagado/vuelto: si faltan, NULL (para compat)
SET @has_medio := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='medio_pago');
SET @has_estado2 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='estado');
SET @has_pagado := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='monto_pagado');
SET @has_vuelto := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='vuelto');

SET @medioexpr := IF(@has_medio>0, 'v.medio_pago', 'NULL');
SET @estadoexpr := IF(@has_estado2>0, 'v.estado', 'NULL');
SET @pagadoexpr := IF(@has_pagado>0, 'v.monto_pagado', 'NULL');
SET @vueltoexpr := IF(@has_vuelto>0, 'v.vuelto', 'NULL');

-- notas: si existe notas úsala, si no nota, si no NULL
SET @has_notas := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='notas');
SET @has_nota  := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ventas' AND COLUMN_NAME='nota');
SET @notasexpr := IF(@has_notas>0, 'v.notas', IF(@has_nota>0, 'v.nota', 'NULL'));

DROP VIEW IF EXISTS v_ventas_completo;

SET @vsql := CONCAT(
'CREATE VIEW v_ventas_completo AS
SELECT
  v.id,
  v.fecha,
  ', @medioexpr, ' AS medio_pago,
  ', @estadoexpr, ' AS estado,
  v.total,
  ', @vueltoexpr, ' AS vuelto,
  ', @pagadoexpr, ' AS monto_pagado,
  v.cliente_id,
  ', @termexpr, ' AS terminal_id,
  NULL AS usuario_id,
  NULL AS vendedor_id,
  ', @notasexpr, ' AS notas,
  v.ultima_visualizacion,
  COALESCE(ia.items_count,0) AS items_count,
  ', @descexpr, ' AS descuento_total,
  CASE
    WHEN ia.productos_nombres IS NULL OR LENGTH(ia.productos_nombres)=0 THEN NULL
    ELSE SUBSTRING_INDEX(ia.productos_nombres, CHAR(44,32), 3)
  END AS productos_preview,
  ta.tags
FROM ventas v
LEFT JOIN (
  SELECT
    vi.venta_id,
    COUNT(*) AS items_count,
    GROUP_CONCAT(p.nombre ORDER BY vi.id SEPARATOR CHAR(44,32)) AS productos_nombres
  FROM venta_items vi
  LEFT JOIN productos p ON p.id = vi.producto_id
  GROUP BY vi.venta_id
) ia ON ia.venta_id = v.id
LEFT JOIN (
  SELECT
    vt.venta_id,
    GROUP_CONCAT(vt.tag ORDER BY vt.tag SEPARATOR CHAR(44,32)) AS tags
  FROM venta_tags vt
  GROUP BY vt.venta_id
) ta ON ta.venta_id = v.id'
);

PREPARE vstmt FROM @vsql;
EXECUTE vstmt;
DEALLOCATE PREPARE vstmt;
