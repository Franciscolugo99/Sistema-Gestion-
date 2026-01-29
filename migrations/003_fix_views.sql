-- migrations/003_fix_views.sql
-- Portabilidad: recrea views sin DEFINER (SQL SECURITY INVOKER)

DROP VIEW IF EXISTS `v_movimientos_stock_resumen`;
DROP VIEW IF EXISTS `v_usuarios_completo`;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `v_movimientos_stock_resumen` AS
SELECT
  `p`.`id` AS `producto_id`,
  `p`.`codigo` AS `codigo`,
  `p`.`nombre` AS `nombre`,
  COUNT(`ms`.`id`) AS `total_movimientos`,
  SUM(CASE WHEN `ms`.`tipo` IN ('COMPRA','AJUSTE_POSITIVO','ANULACION','DEVOLUCION') THEN `ms`.`cantidad` ELSE 0 END) AS `total_entradas`,
  SUM(CASE WHEN `ms`.`tipo` IN ('VENTA','AJUSTE_NEGATIVO') THEN `ms`.`cantidad` ELSE 0 END) AS `total_salidas`,
  MAX(`ms`.`fecha`) AS `ultimo_movimiento`
FROM (`productos` `p`
LEFT JOIN `movimientos_stock` `ms` ON (`ms`.`producto_id` = `p`.`id`))
GROUP BY `p`.`id`, `p`.`codigo`, `p`.`nombre`;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `v_usuarios_completo` AS
SELECT
  `u`.`id` AS `id`,
  `u`.`nombre` AS `nombre`,
  `u`.`email` AS `email`,
  `u`.`username` AS `username`,
  `u`.`activo` AS `activo`,
  `u`.`ultimo_acceso` AS `ultimo_acceso`,
  `u`.`created_at` AS `created_at`,
  `u`.`updated_at` AS `updated_at`,
  `r`.`id` AS `rol_id`,
  `r`.`nombre` AS `rol_nombre`,
  CASE
    WHEN `u`.`ultimo_acceso` IS NULL THEN NULL
    ELSE TO_DAYS(CURRENT_TIMESTAMP()) - TO_DAYS(`u`.`ultimo_acceso`)
  END AS `dias_sin_acceso`,
  CASE
    WHEN `u`.`activo` = 1 THEN 'Activo'
    WHEN `u`.`activo` = 0 THEN 'Inactivo'
    ELSE 'Eliminado'
  END AS `estado_texto`
FROM (`users` `u`
JOIN `roles` `r` ON (`r`.`id` = `u`.`role_id`))
ORDER BY `u`.`id` ASC;
