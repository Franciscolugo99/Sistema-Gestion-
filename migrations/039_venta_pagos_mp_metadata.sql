-- FLUS 4.1.0 - trazabilidad de cobros Mercado Pago en venta_pagos.

SET @vp_mp_order_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_pagos' AND COLUMN_NAME = 'mp_order_id'
);
SET @sql_add_vp_mp_order = IF(
    @vp_mp_order_exists = 0,
    'ALTER TABLE `venta_pagos` ADD COLUMN `mp_order_id` VARCHAR(80) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vp_mp_order; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vp_mp_payment_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_pagos' AND COLUMN_NAME = 'mp_payment_id'
);
SET @sql_add_vp_mp_payment = IF(
    @vp_mp_payment_exists = 0,
    'ALTER TABLE `venta_pagos` ADD COLUMN `mp_payment_id` VARCHAR(80) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vp_mp_payment; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vp_mp_ref_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_pagos' AND COLUMN_NAME = 'mp_external_reference'
);
SET @sql_add_vp_mp_ref = IF(
    @vp_mp_ref_exists = 0,
    'ALTER TABLE `venta_pagos` ADD COLUMN `mp_external_reference` VARCHAR(120) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vp_mp_ref; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vp_mp_origin_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_pagos' AND COLUMN_NAME = 'mp_origin'
);
SET @sql_add_vp_mp_origin = IF(
    @vp_mp_origin_exists = 0,
    'ALTER TABLE `venta_pagos` ADD COLUMN `mp_origin` VARCHAR(20) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vp_mp_origin; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vp_mp_verified_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_pagos' AND COLUMN_NAME = 'mp_verified'
);
SET @sql_add_vp_mp_verified = IF(
    @vp_mp_verified_exists = 0,
    'ALTER TABLE `venta_pagos` ADD COLUMN `mp_verified` TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vp_mp_verified; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @vp_mp_reason_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_pagos' AND COLUMN_NAME = 'mp_manual_reason'
);
SET @sql_add_vp_mp_reason = IF(
    @vp_mp_reason_exists = 0,
    'ALTER TABLE `venta_pagos` ADD COLUMN `mp_manual_reason` VARCHAR(255) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_vp_mp_reason; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_vp_mp_order_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_pagos' AND INDEX_NAME = 'idx_venta_pagos_mp_order'
);
SET @sql_add_idx_vp_mp_order = IF(
    @idx_vp_mp_order_exists = 0,
    'ALTER TABLE `venta_pagos` ADD INDEX `idx_venta_pagos_mp_order` (`mp_order_id`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_vp_mp_order; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_vp_mp_payment_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_pagos' AND INDEX_NAME = 'idx_venta_pagos_mp_payment'
);
SET @sql_add_idx_vp_mp_payment = IF(
    @idx_vp_mp_payment_exists = 0,
    'ALTER TABLE `venta_pagos` ADD INDEX `idx_venta_pagos_mp_payment` (`mp_payment_id`)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql_add_idx_vp_mp_payment; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
