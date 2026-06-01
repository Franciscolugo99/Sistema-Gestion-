
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `app_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_config` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `module` varchar(40) NOT NULL DEFAULT '',
  `entity` varchar(40) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`before_json` is null or json_valid(`before_json`)),
  `after_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`after_json` is null or json_valid(`after_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_created_at` (`created_at`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity`),
  KEY `fk_audit_user` (`user_id`),
  KEY `idx_audit_entity_entityid` (`entity`,`entity_id`),
  KEY `idx_audit_user_created` (`user_id`,`created_at`),
  KEY `idx_audit_module_created` (`module`,`created_at`),
  KEY `idx_audit_request_id` (`request_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `caja_auditoria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_auditoria` (
  `caja_id` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PENDIENTE',
  `nota` text DEFAULT NULL,
  `audited_by` int(11) DEFAULT NULL,
  `audited_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`caja_id`),
  KEY `idx_status` (`status`),
  KEY `idx_audited_by` (`audited_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `caja_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `caja_id` int(11) NOT NULL,
  `tipo` enum('ingreso','egreso') NOT NULL DEFAULT 'ingreso',
  `medio_pago` varchar(30) DEFAULT NULL COMMENT 'EFECTIVO, MP, DEBITO, CREDITO, TRANSFERENCIA',
  `concepto` varchar(255) NOT NULL,
  `monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `usuario_registro` varchar(100) DEFAULT NULL,
  `cc_movimiento_id` int(11) DEFAULT NULL COMMENT 'Referencia al movimiento de CC que genero este ingreso',
  PRIMARY KEY (`id`),
  KEY `idx_caja_sesion` (`caja_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_caja_mov_cc_mov` (`cc_movimiento_id`),
  CONSTRAINT `fk_caja_movimientos_sesion` FOREIGN KEY (`caja_id`) REFERENCES `caja_sesiones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `caja_sesiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_sesiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `fecha_apertura` datetime NOT NULL,
  `saldo_inicial` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fecha_cierre` datetime DEFAULT NULL,
  `cerrado_por_user_id` int(11) DEFAULT NULL,
  `saldo_sistema` decimal(10,2) DEFAULT NULL,
  `saldo_declarado` decimal(10,2) DEFAULT NULL,
  `diferencia` decimal(10,2) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `cierre_motivo` varchar(40) DEFAULT NULL,
  `cierre_fondo_siguiente` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cierre_retiro_efectivo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_ventas` decimal(10,2) DEFAULT 0.00,
  `total_efectivo` decimal(10,2) DEFAULT 0.00,
  `total_mp` decimal(10,2) DEFAULT 0.00,
  `total_debito` decimal(10,2) DEFAULT 0.00,
  `total_credito` decimal(10,2) DEFAULT 0.00,
  `total_transferencia` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Total de ingresos por transferencia bancaria',
  `total_productos` int(11) DEFAULT 0,
  `total_anulaciones` int(11) DEFAULT 0,
  `terminal_id` int(11) NOT NULL DEFAULT 1,
  `total_ingresos` decimal(12,2) DEFAULT 0.00,
  `total_egresos` decimal(12,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_caja_user` (`user_id`),
  KEY `idx_caja_cerrado_por` (`cerrado_por_user_id`),
  KEY `idx_caja_terminal_abierta` (`terminal_id`,`fecha_cierre`),
  CONSTRAINT `fk_caja_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `razon_social` varchar(255) DEFAULT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `cond_iva` varchar(30) DEFAULT NULL,
  `tipo_cliente` varchar(30) DEFAULT NULL,
  `direccion` varchar(200) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `zona_reparto` varchar(60) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `cc_habilitado` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Si el cliente tiene cuenta corriente habilitada',
  `cc_limite` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Límite de crédito en pesos',
  `cc_saldo` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo actual (CACHE - fuente de verdad es cc_movimientos)',
  `cc_fecha_ultimo_pago` date DEFAULT NULL COMMENT 'Fecha del último pago recibido',
  `cc_notas` text DEFAULT NULL COMMENT 'Notas internas sobre la cuenta corriente del cliente',
  PRIMARY KEY (`id`),
  KEY `idx_clientes_cc` (`cc_habilitado`,`cc_saldo`),
  KEY `idx_clientes_cc_alerta` (`cc_habilitado`,`cc_limite`,`cc_saldo`),
  KEY `idx_clientes_nombre` (`nombre`),
  KEY `idx_clientes_cuit` (`cuit`),
  KEY `idx_clientes_activo` (`activo`),
  KEY `idx_clientes_activo_nombre` (`activo`,`nombre`),
  KEY `idx_clientes_tipo_zona` (`tipo_cliente`,`zona_reparto`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ventas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `cliente_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `vendedor_id` int(11) DEFAULT NULL,
  `caja_id` int(11) DEFAULT NULL,
  `terminal_id` int(11) DEFAULT NULL,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_bruto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `descuento_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `descuento_monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ajuste_precio_aplicado` tinyint(1) NOT NULL DEFAULT 0,
  `ajuste_precio_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ajuste_precio_redondeo_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `medio_pago` varchar(30) NOT NULL DEFAULT 'EFECTIVO',
  `monto_pagado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `monto_cc` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vuelto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estado` enum('EMITIDA','PARCIALMENTE_ANULADA','ANULADA') NOT NULL DEFAULT 'EMITIDA',
  `facturada` tinyint(1) NOT NULL DEFAULT 0,
  `anulado_motivo` varchar(255) DEFAULT NULL,
  `anulado_por` int(11) DEFAULT NULL,
  `anulado_en` datetime DEFAULT NULL,
  `ticket_token` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ventas_uuid` (`uuid`),
  KEY `idx_ventas_fecha` (`fecha`),
  KEY `idx_ventas_cliente` (`cliente_id`),
  KEY `idx_ventas_user` (`user_id`),
  KEY `idx_ventas_usuario` (`usuario_id`),
  KEY `idx_ventas_caja` (`caja_id`),
  KEY `idx_ventas_terminal` (`terminal_id`),
  KEY `idx_ventas_estado` (`estado`),
  KEY `idx_ventas_facturada` (`facturada`),
  KEY `idx_ventas_ajuste_precio` (`ajuste_precio_aplicado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `venta_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(12,3) NOT NULL DEFAULT 0.000,
  `precio` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `precio_unit_base` decimal(12,2) DEFAULT NULL,
  `precio_unit_original` decimal(12,2) DEFAULT NULL,
  `descuento_monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `precio_unit_final` decimal(12,2) DEFAULT NULL,
  `ajuste_precio_tipo` varchar(30) DEFAULT NULL,
  `ajuste_precio_origen` varchar(40) DEFAULT NULL,
  `ajuste_precio_nombre` varchar(100) DEFAULT NULL,
  `ajuste_precio_pct` decimal(8,3) DEFAULT NULL,
  `ajuste_precio_unit_monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ajuste_precio_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ajuste_precio_regla_unit_monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ajuste_precio_redondeo_modo` varchar(30) DEFAULT NULL,
  `ajuste_precio_redondeo_unit_monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ajuste_precio_redondeo_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_venta_items_venta` (`venta_id`),
  KEY `idx_venta_items_producto` (`producto_id`),
  KEY `idx_venta_items_ajuste_precio` (`ajuste_precio_tipo`,`ajuste_precio_origen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `venta_pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `medio_pago` varchar(30) NOT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cc_cliente_id` int(11) DEFAULT NULL,
  `cc_movimiento_id` int(11) DEFAULT NULL,
  `mp_order_id` varchar(80) DEFAULT NULL,
  `mp_payment_id` varchar(80) DEFAULT NULL,
  `mp_external_reference` varchar(120) DEFAULT NULL,
  `mp_origin` varchar(20) DEFAULT NULL,
  `mp_verified` tinyint(1) NOT NULL DEFAULT 0,
  `mp_manual_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_venta_pagos_venta` (`venta_id`),
  KEY `idx_venta_pagos_medio` (`medio_pago`),
  KEY `idx_venta_pagos_cc_cliente` (`cc_cliente_id`),
  KEY `idx_venta_pagos_cc_movimiento` (`cc_movimiento_id`),
  KEY `idx_venta_pagos_mp_order` (`mp_order_id`),
  KEY `idx_venta_pagos_mp_payment` (`mp_payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `venta_promos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_promos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `promo_id` int(11) DEFAULT NULL,
  `promo_tipo` varchar(20) NOT NULL,
  `promo_nombre` varchar(120) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `descuento_monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`meta` is null or json_valid(`meta`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_venta_promos_venta` (`venta_id`),
  KEY `idx_venta_promos_promo` (`promo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `compra_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compra_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `compra_id` int(10) unsigned NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(12,3) NOT NULL DEFAULT 0.000,
  `costo_unitario` decimal(12,2) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `comentario` varchar(255) DEFAULT NULL,
  `descuento` decimal(12,2) NOT NULL DEFAULT 0.00,
  `descuento_tipo` varchar(10) NOT NULL DEFAULT 'MONTO',
  `descuento_porc` decimal(5,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_ci_compra` (`compra_id`),
  KEY `idx_ci_producto` (`producto_id`),
  CONSTRAINT `fk_ci_compra` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ci_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `compras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compras` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `proveedor_id` int(10) unsigned DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `tipo_comp` varchar(20) DEFAULT NULL,
  `nro_comp` varchar(40) DEFAULT NULL,
  `estado` varchar(15) NOT NULL DEFAULT 'BORRADOR',
  `total_neto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_iva` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `obs` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_bruto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `descuento_tipo` varchar(10) NOT NULL DEFAULT 'MONTO',
  `descuento_valor` decimal(12,2) NOT NULL DEFAULT 0.00,
  `descuento_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_compras_fecha` (`fecha`),
  KEY `idx_compras_estado` (`estado`),
  KEY `idx_compras_proveedor` (`proveedor_id`),
  CONSTRAINT `fk_compras_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `config_facturacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `config_facturacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `punto_venta` int(11) NOT NULL DEFAULT 1,
  `tipo_comprobante` varchar(5) NOT NULL DEFAULT 'FA',
  `cond_iva` varchar(50) DEFAULT 'RI' COMMENT 'RI=Resp.Inscripto, MT=Monotributo, EX=Exento',
  `descripcion` varchar(100) DEFAULT NULL,
  `tipo_default` varchar(5) NOT NULL DEFAULT 'C',
  `proximo_numero` int(11) NOT NULL DEFAULT 1,
  `modo` enum('demo','produccion') DEFAULT 'demo',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `razon_social` varchar(255) DEFAULT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `domicilio` varchar(500) DEFAULT NULL,
  `inicio_actividades` date DEFAULT NULL,
  `creado_en` datetime DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_punto_venta` (`punto_venta`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cuenta_corriente_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cuenta_corriente_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `tipo` enum('CARGO','PAGO','AJUSTE','AJUSTE_POS','AJUSTE_NEG','ANULACION','REVERSA') NOT NULL,
  `estado` enum('ACTIVO','ANULADO') NOT NULL DEFAULT 'ACTIVO',
  `monto` decimal(12,2) NOT NULL,
  `saldo_anterior` decimal(12,2) NOT NULL DEFAULT 0.00,
  `saldo_posterior` decimal(12,2) NOT NULL DEFAULT 0.00,
  `venta_id` int(11) DEFAULT NULL,
  `concepto` varchar(255) DEFAULT NULL,
  `medio_pago` varchar(50) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `reversa_de_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `autorizado_por` int(11) DEFAULT NULL COMMENT 'Usuario que autorizo exceder limite',
  `caja_id` int(11) DEFAULT NULL,
  `caja_movimiento_id` int(11) DEFAULT NULL COMMENT 'ID del movimiento de caja generado al cobrar',
  `terminal_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ccm_cliente` (`cliente_id`),
  KEY `idx_ccm_fecha` (`created_at`),
  KEY `idx_ccm_reversa` (`reversa_de_id`),
  KEY `idx_cc_mov_estado` (`estado`),
  KEY `idx_ccm_created_by` (`created_by`),
  KEY `idx_cc_mov_caja_mov` (`caja_movimiento_id`),
  CONSTRAINT `fk_ccm_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_ccm_reversa` FOREIGN KEY (`reversa_de_id`) REFERENCES `cuenta_corriente_movimientos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `facturas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `facturas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `tipo` varchar(5) NOT NULL,
  `punto_venta` int(11) NOT NULL DEFAULT 1,
  `numero` int(11) DEFAULT NULL,
  `importe_neto` decimal(12,2) DEFAULT 0.00,
  `importe_iva` decimal(12,2) DEFAULT 0.00,
  `importe_exento` decimal(12,2) DEFAULT 0.00,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `total` decimal(12,2) NOT NULL,
  `cae` varchar(20) DEFAULT NULL,
  `cae_vto` varchar(10) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'EMITIDA',
  `modo` enum('demo','produccion') DEFAULT 'demo',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_facturas_numero` (`punto_venta`,`tipo`,`numero`),
  KEY `fk_facturas_venta` (`venta_id`),
  KEY `fk_facturas_cliente` (`cliente_id`),
  KEY `idx_facturas_cae` (`cae`),
  CONSTRAINT `fk_facturas_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_facturas_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `inventario_conteos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventario_conteos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sesion_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL DEFAULT 0.000,
  `stock_sistema_snapshot` decimal(10,3) DEFAULT NULL,
  `ubicacion` varchar(120) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sesion` (`sesion_id`),
  KEY `idx_producto` (`producto_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_inv_conteos_sesion` FOREIGN KEY (`sesion_id`) REFERENCES `inventario_sesiones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `inventario_sesiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventario_sesiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `categoria_nombre` varchar(100) DEFAULT NULL,
  `estado` enum('ABIERTA','CERRADA','APLICADA') NOT NULL DEFAULT 'ABIERTA',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `cierre_motivo` varchar(255) DEFAULT NULL,
  `applied_by` int(11) DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `movimientos_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movimientos_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `producto_id` int(11) NOT NULL,
  `tipo` enum('VENTA','COMPRA','AJUSTE_POSITIVO','AJUSTE_NEGATIVO','ANULACION','ANULACION_VENTA','ANULACION_COMPRA','DEVOLUCION') NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `referencia_venta_id` int(11) DEFAULT NULL,
  `referencia_compra_id` int(10) unsigned DEFAULT NULL,
  `comentario` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL COMMENT 'Quién realizó el movimiento',
  `stock_anterior` decimal(10,3) DEFAULT NULL COMMENT 'Stock antes del movimiento',
  `stock_nuevo` decimal(10,3) DEFAULT NULL COMMENT 'Stock después del movimiento',
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  KEY `referencia_venta_id` (`referencia_venta_id`),
  KEY `idx_movimientos_fecha` (`fecha`),
  KEY `idx_movimientos_tipo_fecha` (`tipo`,`fecha`),
  KEY `idx_movimientos_producto_fecha` (`producto_id`,`fecha`),
  KEY `idx_mov_fecha` (`fecha`),
  KEY `idx_mov_tipo_fecha` (`tipo`,`fecha`),
  KEY `idx_mov_prod_fecha` (`producto_id`,`fecha`),
  KEY `idx_mov_venta_id` (`venta_id`),
  KEY `idx_mov_ref_compra` (`referencia_compra_id`),
  KEY `idx_mov_usuario` (`usuario_id`),
  CONSTRAINT `fk_mov_ref_compra` FOREIGN KEY (`referencia_compra_id`) REFERENCES `compras` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_movimientos_stock_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `movimientos_stock_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `movimientos_stock_ibfk_2` FOREIGN KEY (`referencia_venta_id`) REFERENCES `ventas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=189 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE TRIGGER `before_insert_movimiento_stock` BEFORE INSERT ON `movimientos_stock` FOR EACH ROW BEGIN

  DECLARE stock_actual DECIMAL(10,3);

  

  
  SELECT stock INTO stock_actual

  FROM productos

  WHERE id = NEW.producto_id;

  

  
  SET NEW.stock_anterior = stock_actual;

  

  
  CASE NEW.tipo

    WHEN 'COMPRA' THEN

      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;

    WHEN 'VENTA' THEN

      SET NEW.stock_nuevo = stock_actual - NEW.cantidad;

    WHEN 'AJUSTE_POSITIVO' THEN

      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;

    WHEN 'AJUSTE_NEGATIVO' THEN

      SET NEW.stock_nuevo = stock_actual - NEW.cantidad;

    WHEN 'ANULACION' THEN

      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;

    WHEN 'ANULACION_VENTA' THEN

      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;

    WHEN 'ANULACION_COMPRA' THEN

      SET NEW.stock_nuevo = stock_actual - NEW.cantidad;

    WHEN 'DEVOLUCION' THEN

      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;

    ELSE

      SET NEW.stock_nuevo = stock_actual;

  END CASE;

END;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `movimientos_stock_backup_7d`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movimientos_stock_backup_7d` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `producto_id` int(11) NOT NULL,
  `tipo` enum('VENTA','COMPRA','AJUSTE_POSITIVO','AJUSTE_NEGATIVO') NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `referencia_venta_id` int(11) DEFAULT NULL,
  `comentario` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  KEY `referencia_venta_id` (`referencia_venta_id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `producto_precios_hist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `producto_precios_hist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` int(10) unsigned NOT NULL,
  `tipo` enum('VENTA','COSTO') DEFAULT 'VENTA',
  `precio_anterior` decimal(12,2) NOT NULL,
  `precio_nuevo` decimal(12,2) NOT NULL,
  `diferencia` decimal(12,2) NOT NULL,
  `diferencia_pct` decimal(8,2) DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_producto` (`producto_id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `producto_reposicion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `producto_reposicion` (
  `producto_id` int(10) unsigned NOT NULL,
  `stock_minimo` decimal(12,3) DEFAULT NULL,
  `stock_maximo` decimal(12,3) DEFAULT NULL,
  `punto_reorden` decimal(12,3) DEFAULT NULL,
  `proveedor_id` int(10) unsigned DEFAULT NULL,
  `dias_reposicion` int(11) DEFAULT 7,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`producto_id`),
  KEY `idx_proveedor` (`proveedor_id`),
  KEY `idx_minimo` (`stock_minimo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `categoria` varchar(100) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `proveedor` varchar(150) DEFAULT NULL,
  `proveedor_id` int(10) unsigned DEFAULT NULL,
  `imagen` varchar(255) DEFAULT NULL,
  `iva` decimal(5,2) DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `stock` decimal(10,3) NOT NULL DEFAULT 0.000,
  `costo` decimal(10,2) DEFAULT NULL,
  `stock_minimo` decimal(10,3) NOT NULL DEFAULT 0.000,
  `es_pesable` tinyint(1) NOT NULL DEFAULT 0,
  `unidad_venta` enum('UNIDAD','KG','G','LT','ML') NOT NULL DEFAULT 'UNIDAD',
  `stock_inicial` decimal(10,3) NOT NULL DEFAULT 0.000,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_alta` datetime DEFAULT current_timestamp(),
  `fecha_modificacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `iva_porcentaje` decimal(5,2) DEFAULT 21.00 COMMENT 'Porcentaje de IVA: 0, 10.5, 21, 27',
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `idx_productos_proveedor_id` (`proveedor_id`),
  KEY `idx_prod_categoria` (`categoria`),
  CONSTRAINT `fk_productos_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=104 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promo_combo_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promo_combo_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `promo_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad_requerida` decimal(10,3) NOT NULL DEFAULT 1.000,
  PRIMARY KEY (`id`),
  KEY `promo_id` (`promo_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `promo_combo_items_ibfk_1` FOREIGN KEY (`promo_id`) REFERENCES `promos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promo_combo_items_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promo_productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promo_productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `promo_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `n` int(11) NOT NULL,
  `m` int(11) DEFAULT NULL,
  `porcentaje` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_promo_producto` (`promo_id`,`producto_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `promo_productos_ibfk_1` FOREIGN KEY (`promo_id`) REFERENCES `promos` (`id`),
  CONSTRAINT `promo_productos_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `promos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `tipo` enum('N_PAGA_M','NTH_PCT','COMBO_FIJO') NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `precio_combo` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `proveedores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `razon_social` varchar(150) DEFAULT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `contacto_nombre` varchar(100) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `dias_pago` tinyint(3) unsigned DEFAULT 0,
  `descuento_habitual` decimal(5,2) DEFAULT 0.00,
  `notas` text DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_proveedores_nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `role_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permission` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permission_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `role_permission_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `terminal_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `terminal_locks` (
  `terminal_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`terminal_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `terminales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `terminales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `codigo` varchar(40) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tesoreria_cuentas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tesoreria_cuentas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `tipo` varchar(30) NOT NULL DEFAULT 'OTRO',
  `sucursal_id` int(11) DEFAULT NULL,
  `sucursal_nombre` varchar(120) DEFAULT NULL,
  `saldo_inicial` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estado` varchar(20) NOT NULL DEFAULT 'ACTIVA',
  `observaciones` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tes_cuentas_tipo` (`tipo`),
  KEY `idx_tes_cuentas_estado` (`estado`),
  KEY `idx_tes_cuentas_sucursal` (`sucursal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tesoreria_categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tesoreria_categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'EGRESO',
  `estado` varchar(20) NOT NULL DEFAULT 'ACTIVA',
  `orden` int(11) NOT NULL DEFAULT 100,
  `observaciones` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_tes_categorias_slug` (`slug`),
  KEY `idx_tes_categorias_tipo` (`tipo`),
  KEY `idx_tes_categorias_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tesoreria_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tesoreria_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_uid` varchar(64) DEFAULT NULL,
  `tipo` varchar(20) NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'ACTIVO',
  `cuenta_origen_id` int(11) DEFAULT NULL,
  `cuenta_destino_id` int(11) DEFAULT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `sucursal_id` int(11) DEFAULT NULL,
  `sucursal_nombre` varchar(120) DEFAULT NULL,
  `fecha` datetime NOT NULL,
  `importe` decimal(12,2) NOT NULL DEFAULT 0.00,
  `concepto` varchar(180) NOT NULL,
  `referencia` varchar(120) DEFAULT NULL,
  `observaciones` varchar(255) DEFAULT NULL,
  `entidad_tipo` varchar(40) DEFAULT NULL,
  `entidad_id` int(11) DEFAULT NULL,
  `obligacion_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_tes_mov_request_uid` (`request_uid`),
  KEY `idx_tes_mov_tipo_fecha` (`tipo`,`fecha`),
  KEY `idx_tes_mov_estado` (`estado`),
  KEY `idx_tes_mov_cuenta_origen` (`cuenta_origen_id`),
  KEY `idx_tes_mov_cuenta_destino` (`cuenta_destino_id`),
  KEY `idx_tes_mov_categoria` (`categoria_id`),
  KEY `idx_tes_mov_sucursal` (`sucursal_id`),
  KEY `idx_tes_mov_obligacion` (`obligacion_id`),
  KEY `idx_tes_mov_entidad` (`entidad_tipo`,`entidad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tesoreria_obligaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tesoreria_obligaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `external_key` varchar(191) DEFAULT NULL,
  `descripcion` varchar(180) NOT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `sucursal_id` int(11) DEFAULT NULL,
  `sucursal_nombre` varchar(120) DEFAULT NULL,
  `fecha_vencimiento` date NOT NULL,
  `importe_estimado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `importe_pagado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estado` varchar(20) NOT NULL DEFAULT 'PENDIENTE',
  `cuenta_sugerida_id` int(11) DEFAULT NULL,
  `movimiento_pago_id` int(11) DEFAULT NULL,
  `observaciones` varchar(255) DEFAULT NULL,
  `entidad_tipo` varchar(40) DEFAULT NULL,
  `entidad_id` int(11) DEFAULT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `compra_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_tes_obl_external_key` (`external_key`),
  KEY `idx_tes_obl_estado_vto` (`estado`,`fecha_vencimiento`),
  KEY `idx_tes_obl_categoria` (`categoria_id`),
  KEY `idx_tes_obl_sucursal` (`sucursal_id`),
  KEY `idx_tes_obl_cuenta` (`cuenta_sugerida_id`),
  KEY `idx_tes_obl_mov_pago` (`movimiento_pago_id`),
  KEY `idx_tes_obl_entidad` (`entidad_tipo`,`entidad_id`),
  KEY `idx_tes_obl_proveedor` (`proveedor_id`),
  KEY `idx_tes_obl_compra` (`compra_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `user_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_sessions` (
  `session_id` varchar(128) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('ACTIVE','REVOKED','LOGGED_OUT') NOT NULL DEFAULT 'ACTIVE',
  `login_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_path` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `selected_terminal_id` int(11) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL,
  `revoked_reason` varchar(255) DEFAULT NULL,
  `logout_at` datetime DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_user_sessions_user` (`user_id`),
  KEY `idx_user_sessions_status_seen` (`status`,`last_seen_at`),
  KEY `idx_user_sessions_terminal` (`selected_terminal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ultimo_acceso` datetime DEFAULT NULL COMMENT 'Fecha y hora del último inicio de sesión',
  `updated_at` datetime DEFAULT NULL COMMENT 'Fecha de última actualización',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  KEY `role_id` (`role_id`),
  KEY `idx_users_search` (`nombre`,`username`,`email`),
  KEY `idx_users_role` (`role_id`,`activo`),
  KEY `idx_users_role_activo` (`role_id`,`activo`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
CREATE TRIGGER `users_before_update` BEFORE UPDATE ON `users` FOR EACH ROW BEGIN

    SET NEW.updated_at = NOW();

END;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- Seed minimo para instalacion limpia
INSERT INTO `app_config` (`k`, `v`) VALUES
  ('business_address', ''),
  ('business_cuit', ''),
  ('business_name', 'FLUS Demo'),
  ('business_phone', ''),
  ('facturacion_habilitada', '0'),
  ('facturacion_modo', 'demo'),
  ('qr_base_url', 'https://www.arca.gob.ar/fe/qr/'),
  ('ticket_footer', 'Gracias por su compra')
ON DUPLICATE KEY UPDATE `v` = VALUES(`v`);

INSERT INTO `roles` (`id`, `nombre`, `slug`, `created_at`) VALUES
  (1, 'Administrador', 'admin', NOW()),
  (2, 'Encargado', 'encargado', NOW()),
  (3, 'Cajero', 'cajero', NOW()),
  (4, 'Auditor', 'auditor', NOW()),
  (5, 'Operador', 'operador', NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `slug` = VALUES(`slug`);

INSERT INTO `permissions` (`id`, `nombre`, `slug`, `created_at`) VALUES
  (1, 'Ver costos', 'ver_costos', NOW()),
  (2, 'Editar productos', 'editar_productos', NOW()),
  (3, 'Editar stock', 'editar_stock', NOW()),
  (4, 'Abrir caja', 'abrir_caja', NOW()),
  (5, 'Cerrar caja', 'cerrar_caja', NOW()),
  (6, 'Ver reportes', 'ver_reportes', NOW()),
  (7, 'Administrar usuarios', 'administrar_usuarios', NOW()),
  (8, 'Ver movimientos', 'ver_movimientos', NOW()),
  (9, 'Realizar ventas', 'realizar_ventas', NOW()),
  (10, 'Ver historial de caja', 'ver_historial_caja', NOW()),
  (11, 'Administrar configuracion', 'administrar_config', NOW()),
  (14, 'Modificar precio en caja', 'caja_modificar_precio', NOW()),
  (15, 'Anular ventas', 'anular_venta', NOW()),
  (16, 'Ver auditoria', 'ver_auditoria', NOW()),
  (17, 'Gestionar backups', 'gestionar_backups', NOW()),
  (21, 'Ver clientes', 'ver_clientes', NOW()),
  (22, 'Editar clientes', 'editar_clientes', NOW()),
  (23, 'Ver facturacion', 'ver_facturacion', NOW()),
  (24, 'Emitir factura', 'emitir_factura', NOW()),
  (25, 'Editar promociones', 'editar_promos', NOW()),
  (26, 'Ver productos', 'ver_productos', NOW()),
  (27, 'Ver stock', 'ver_stock', NOW()),
  (41, 'Ver proveedores', 'ver_proveedores', NOW()),
  (42, 'Editar proveedores', 'editar_proveedores', NOW()),
  (43, 'Emitir nota de crédito', 'emitir_nota_credito', NOW()),
  (44, 'Ver cuenta corriente', 'ver_cuenta_corriente', NOW()),
  (45, 'Caja: vender en CC', 'registrar_cargo_cc', NOW()),
  (46, 'Registrar pago CC', 'registrar_pago_cc', NOW()),
  (47, 'Ajustar cuenta corriente', 'ajustar_cc', NOW()),
  (48, 'Habilitar cuenta corriente', 'habilitar_cc', NOW()),
  (49, 'Vender excedido en CC', 'vender_excedido_cc', NOW()),
  (50, 'Anular movimiento CC', 'anular_movimiento_cc', NOW()),
  (51, 'Recalcular saldo CC', 'recalcular_saldo_cc', NOW()),
  (52, 'Gestionar stock', 'gestionar_stock', NOW()),
  (53, 'Ver diagnostico', 'ver_diagnostico', NOW()),
  (54, 'Anular items de venta', 'anular_items_venta', NOW()),
  (55, 'Ver tesoreria', 'ver_tesoreria', NOW()),
  (56, 'Gestionar tesoreria', 'gestionar_tesoreria', NOW()),
  (57, 'Ver reportes de tesoreria', 'ver_reportes_tesoreria', NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `slug` = VALUES(`slug`);

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT 1, p.`id`
FROM `permissions` p;

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT 2, p.`id`
FROM `permissions` p
WHERE p.`slug` IN (
  'ver_costos','editar_productos','editar_stock','abrir_caja','cerrar_caja','ver_reportes','ver_movimientos',
  'realizar_ventas','ver_historial_caja','administrar_config','caja_modificar_precio','emitir_factura',
  'emitir_nota_credito','editar_promos','ver_productos','ver_stock','ver_proveedores','editar_proveedores','ver_facturacion',
  'ver_tesoreria','gestionar_tesoreria','ver_reportes_tesoreria'
);

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT 3, p.`id`
FROM `permissions` p
WHERE p.`slug` IN (
  'abrir_caja','cerrar_caja','realizar_ventas','ver_clientes','ver_stock','registrar_cargo_cc'
);

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT 4, p.`id`
FROM `permissions` p
WHERE p.`slug` IN ('ver_costos','ver_reportes','ver_movimientos','ver_auditoria','ver_historial_caja','ver_diagnostico','ver_tesoreria','ver_reportes_tesoreria');

INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT 5, p.`id`
FROM `permissions` p
WHERE p.`slug` IN (
  'realizar_ventas','cerrar_caja','ver_clientes','registrar_pago_cc','registrar_cargo_cc',
  'ver_cuenta_corriente','editar_stock','ver_stock','ver_proveedores','editar_proveedores'
);

INSERT INTO `users` (`id`, `nombre`, `email`, `username`, `password_hash`, `role_id`, `activo`, `created_at`, `ultimo_acceso`, `updated_at`) VALUES
  (1, 'Administrador FLUS', 'admin@flus.local', 'admin', '$2y$10$yPokhUEft2w2kngTRjoBkuaq7cwygVwwfYA.oY.lKVH7Sxytlkkde', 1, 1, NOW(), NULL, NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `email` = VALUES(`email`),
  `username` = VALUES(`username`),
  `role_id` = VALUES(`role_id`),
  `activo` = VALUES(`activo`);

INSERT INTO `terminales` (`id`, `nombre`, `codigo`, `activo`, `created_at`) VALUES
  (1, 'Caja 1', 'CAJA-01', 1, NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `codigo` = VALUES(`codigo`),
  `activo` = VALUES(`activo`);

INSERT INTO `tesoreria_categorias` (`nombre`, `slug`, `tipo`, `orden`, `created_at`, `updated_at`) VALUES
  ('Alquiler', 'alquiler', 'EGRESO', 10, NOW(), NOW()),
  ('Impuestos', 'impuestos', 'EGRESO', 20, NOW(), NOW()),
  ('Servicios', 'servicios', 'EGRESO', 30, NOW(), NOW()),
  ('Sueldos', 'sueldos', 'EGRESO', 40, NOW(), NOW()),
  ('Mantenimiento', 'mantenimiento', 'EGRESO', 50, NOW(), NOW()),
  ('Marketing', 'marketing', 'EGRESO', 60, NOW(), NOW()),
  ('Comisiones', 'comisiones', 'EGRESO', 70, NOW(), NOW()),
  ('Retiros', 'retiros', 'EGRESO', 80, NOW(), NOW()),
  ('Ajustes', 'ajustes', 'AMBOS', 90, NOW(), NOW()),
  ('Otros', 'otros', 'AMBOS', 100, NOW(), NOW()),
  ('Aporte de capital', 'aporte-capital', 'INGRESO', 110, NOW(), NOW()),
  ('Ingreso extraordinario', 'ingreso-extraordinario', 'INGRESO', 120, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `tipo` = VALUES(`tipo`),
  `orden` = VALUES(`orden`),
  `updated_at` = NOW();

INSERT INTO `config_facturacion` (`id`, `punto_venta`, `tipo_comprobante`, `cond_iva`, `descripcion`, `tipo_default`, `proximo_numero`, `modo`, `activo`, `razon_social`, `cuit`, `domicilio`, `inicio_actividades`, `creado_en`, `actualizado_en`) VALUES
  (1, 1, 'FA', 'RI', 'Punto de venta 1', 'C', 1, 'demo', 1, NULL, NULL, NULL, NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `punto_venta` = VALUES(`punto_venta`),
  `tipo_comprobante` = VALUES(`tipo_comprobante`),
  `cond_iva` = VALUES(`cond_iva`),
  `tipo_default` = VALUES(`tipo_default`),
  `modo` = VALUES(`modo`),
  `activo` = VALUES(`activo`),
  `actualizado_en` = VALUES(`actualizado_en`);

-- Usuario inicial:
--   usuario: admin
--   clave:   flusadmin123

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
