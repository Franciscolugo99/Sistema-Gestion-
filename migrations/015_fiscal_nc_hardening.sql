-- Migración 015: endurecimiento fiscal NC
-- No destructiva. Agrega unicidad fuerte en fiscal_request_uid
-- y el permiso emitir_nota_credito.
--
-- REQUIERE: 012_venta_anulaciones_fiscal.sql aplicada (columna fiscal_request_uid existe).

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. UNIQUE en venta_anulaciones.fiscal_request_uid
-- ─────────────────────────────────────────────────────────────────────────────
-- Blindaje contra doble-click, retry por timeout y reenvío manual.
-- La columna ya tiene un índice regular (idx_va_request_uid) desde 012.
-- Reemplazamos ese índice por uno UNIQUE; si ya existe, lo omitimos.
--
-- Lógica: en instalaciones que tienen filas con fiscal_request_uid = NULL,
-- el UNIQUE funciona porque MariaDB/MySQL permiten múltiples NULL en columnas UNIQUE.
-- Solo fallaría si hay dos filas con el mismo UUID no-NULL, lo cual indicaría
-- ya un bug de integridad que este índice viene a prevenir.

SET @ux_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA   = DATABASE()
      AND TABLE_NAME     = 'venta_anulaciones'
      AND INDEX_NAME     = 'ux_va_fiscal_request_uid'
      AND NON_UNIQUE     = 0
);

SET @sql_add_ux = IF(
    @ux_exists = 0,
    'ALTER TABLE `venta_anulaciones` ADD UNIQUE KEY `ux_va_fiscal_request_uid` (`fiscal_request_uid`)',
    'SELECT 1 -- ux_va_fiscal_request_uid ya existe, skip'
);

-- Eliminar el índice regular previo si existe (se reemplaza por el UNIQUE).
SET @idx_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'venta_anulaciones'
      AND INDEX_NAME   = 'idx_va_request_uid'
      AND NON_UNIQUE   = 1
);

SET @sql_drop_idx = IF(
    @idx_exists > 0,
    'ALTER TABLE `venta_anulaciones` DROP INDEX `idx_va_request_uid`',
    'SELECT 1 -- idx_va_request_uid no existe o ya fue reemplazado, skip'
);

PREPARE _stmt FROM @sql_drop_idx;  EXECUTE _stmt;  DEALLOCATE PREPARE _stmt;
PREPARE _stmt FROM @sql_add_ux;    EXECUTE _stmt;  DEALLOCATE PREPARE _stmt;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Permiso emitir_nota_credito
-- ─────────────────────────────────────────────────────────────────────────────
-- Separa la emisión de NC de la emisión de facturas ordinarias.
-- Una NC es una operación fiscal de mayor impacto: puede revertir IVA ya declarado,
-- afectar CC y reponer stock. Tenerla bajo un permiso propio permite asignarla
-- solo a operadores autorizados sin dar acceso a emitir facturas nuevas.

INSERT INTO `permissions` (`nombre`, `slug`, `created_at`)
VALUES ('Emitir nota de crédito', 'emitir_nota_credito', NOW())
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

-- Dar el permiso automáticamente a todos los roles que ya tienen emitir_factura.
-- Lógica: quien podía emitir facturas antes de esta migración sigue pudiendo
-- emitir NC sin reconfiguración manual.
INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT rp.`role_id`, p_nc.`id`
FROM `role_permission` rp
JOIN `permissions` p_factura ON p_factura.`id` = rp.`permission_id`
                             AND p_factura.`slug` = 'emitir_factura'
JOIN `permissions` p_nc      ON p_nc.`slug` = 'emitir_nota_credito';
