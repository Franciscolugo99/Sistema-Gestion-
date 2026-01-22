-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: kiosco
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

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

--
-- Table structure for table `app_config`
--

DROP TABLE IF EXISTS `app_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_config` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_config`
--

LOCK TABLES `app_config` WRITE;
/*!40000 ALTER TABLE `app_config` DISABLE KEYS */;
INSERT INTO `app_config` VALUES ('business_address','Av. Siempre Viva 123'),('business_cuit','00-00000000-0'),('business_name','KIOSCO XYZ'),('business_phone','-'),('qr_base_url','https://www.arca.gob.ar/fe/qr/'),('ticket_footer','Gracias por su compra');
/*!40000 ALTER TABLE `app_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (1,1,NULL,NULL,NULL,'test','','ventas',1,'{\"ok\":true}',NULL,NULL,'2025-12-14 21:15:06'),(2,1,NULL,NULL,NULL,'test_manual','','ventas',999,'{\"ok\":true}',NULL,NULL,'2025-12-14 21:17:03'),(3,1,NULL,NULL,NULL,'venta_anulada','','ventas',45,'{\"motivo\":\"\",\"importe\":25000,\"medio_pago\":\"EFECTIVO\"}',NULL,NULL,'2025-12-14 21:20:23'),(4,1,NULL,NULL,NULL,'venta_creada','','ventas',46,'{\"importe\":1200,\"medio_pago\":\"EFECTIVO\",\"descuento\":0,\"caja_id\":27}',NULL,NULL,'2025-12-17 15:14:54'),(5,1,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','6210a9711c58c076dcc3ac17b96eaeb1','TEST','SISTEMA','debug',NULL,'{\"msg\":\"Prueba auditoría OK\",\"ts\":\"2025-12-18T14:40:46-03:00\"}',NULL,NULL,'2025-12-18 14:40:46'),(6,1,NULL,NULL,NULL,'venta_anulada','','ventas',46,'{\"motivo\":\"El cliente la devolvio por que vencimiento\",\"importe\":1200,\"medio_pago\":\"EFECTIVO\"}',NULL,NULL,'2025-12-18 16:48:46'),(7,1,NULL,NULL,NULL,'venta_anulada','','ventas',1,'{\"motivo\":\"test\",\"importe\":2000,\"medio_pago\":\"EFECTIVO\"}',NULL,NULL,'2026-01-01 22:48:17');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_movimientos`
--

DROP TABLE IF EXISTS `caja_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `caja_id` int(11) NOT NULL,
  `tipo` enum('ingreso','egreso') NOT NULL DEFAULT 'ingreso',
  `concepto` varchar(255) NOT NULL,
  `monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `usuario_registro` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_caja_sesion` (`caja_id`),
  KEY `idx_fecha` (`fecha`),
  CONSTRAINT `fk_caja_movimientos_sesion` FOREIGN KEY (`caja_id`) REFERENCES `caja_sesiones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_movimientos`
--

LOCK TABLES `caja_movimientos` WRITE;
/*!40000 ALTER TABLE `caja_movimientos` DISABLE KEYS */;
INSERT INTO `caja_movimientos` VALUES (1,33,'ingreso','cambio',10000.00,'2026-01-01 21:06:09','admin'),(2,49,'egreso','retiro',1000.00,'2026-01-06 17:09:11','admin');
/*!40000 ALTER TABLE `caja_movimientos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_sesiones`
--

DROP TABLE IF EXISTS `caja_sesiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_sesiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `fecha_apertura` datetime NOT NULL,
  `saldo_inicial` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fecha_cierre` datetime DEFAULT NULL,
  `saldo_sistema` decimal(10,2) DEFAULT NULL,
  `saldo_declarado` decimal(10,2) DEFAULT NULL,
  `diferencia` decimal(10,2) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `total_ventas` decimal(10,2) DEFAULT 0.00,
  `total_efectivo` decimal(10,2) DEFAULT 0.00,
  `total_mp` decimal(10,2) DEFAULT 0.00,
  `total_debito` decimal(10,2) DEFAULT 0.00,
  `total_credito` decimal(10,2) DEFAULT 0.00,
  `total_productos` int(11) DEFAULT 0,
  `total_anulaciones` int(11) DEFAULT 0,
  `terminal_id` int(11) NOT NULL DEFAULT 1,
  `total_ingresos` decimal(12,2) DEFAULT 0.00,
  `total_egresos` decimal(12,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_caja_user` (`user_id`),
  KEY `idx_caja_terminal_abierta` (`terminal_id`,`fecha_cierre`),
  CONSTRAINT `fk_caja_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_sesiones`
--

LOCK TABLES `caja_sesiones` WRITE;
/*!40000 ALTER TABLE `caja_sesiones` DISABLE KEYS */;
INSERT INTO `caja_sesiones` VALUES (1,1,'2025-12-04 14:51:14',100000.00,'2025-12-04 16:15:54',100000.00,100000.00,0.00,NULL,0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(2,1,'2025-12-04 16:35:51',10000.00,'2025-12-04 16:53:10',18000.00,0.00,-18000.00,NULL,0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(3,1,'2025-12-04 21:24:17',50000.00,'2025-12-04 21:26:37',60000.00,45000.00,-15000.00,NULL,0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(4,1,'2025-12-04 21:26:50',10000.00,'2025-12-04 22:20:32',11200.00,11000.00,-200.00,'',1200.00,1200.00,0.00,0.00,0.00,1,0,1,0.00,0.00),(5,1,'2025-12-04 22:40:07',1000.00,'2025-12-04 22:40:58',1000.00,1000.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(6,1,'2025-12-04 22:43:30',0.00,'2025-12-04 22:43:56',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(7,1,'2025-12-04 22:45:26',0.00,'2025-12-05 00:11:59',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(8,1,'2025-12-05 00:12:21',10000.00,'2025-12-05 12:26:26',10000.00,10000.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(9,1,'2025-12-05 14:35:35',0.00,'2025-12-05 15:35:05',1200.00,2400.00,1200.00,'',1200.00,1200.00,0.00,0.00,0.00,1,0,1,0.00,0.00),(10,1,'2025-12-05 16:31:58',0.00,'2025-12-05 20:45:53',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(11,1,'2025-12-05 20:49:47',0.00,'2025-12-05 21:00:48',1750.00,1750.00,0.00,'',1750.00,1750.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(12,1,'2025-12-05 21:07:20',0.00,'2025-12-06 21:15:07',18700.00,18000.00,-700.00,'',18700.00,18700.00,0.00,0.00,0.00,7,0,1,0.00,0.00),(13,1,'2025-12-06 21:23:19',0.00,'2025-12-07 00:25:56',24500.00,25000.00,500.00,'',29700.00,24500.00,5200.00,0.00,0.00,10,0,1,0.00,0.00),(14,1,'2025-12-07 00:28:02',0.00,'2025-12-08 01:53:34',3360.00,3360.00,0.00,'',3360.00,3360.00,0.00,0.00,0.00,3,0,1,0.00,0.00),(15,1,'2025-12-08 01:53:38',0.00,'2025-12-08 17:05:13',47400.00,47400.00,0.00,'',47400.00,47400.00,0.00,0.00,0.00,28,0,1,0.00,0.00),(16,1,'2025-12-08 18:25:54',0.00,'2025-12-08 18:27:19',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(17,1,'2025-12-08 20:33:59',0.00,'2025-12-08 21:46:04',69200.00,69200.00,0.00,'',69199.00,66000.00,0.00,0.00,0.00,12,1,1,0.00,0.00),(18,1,'2025-12-08 22:46:55',0.00,'2025-12-08 23:12:50',0.00,0.00,0.00,'',86000.00,0.00,86000.00,0.00,0.00,27,0,1,0.00,0.00),(19,1,'2025-12-08 23:20:07',0.00,'2025-12-10 23:51:15',1200.00,1200.00,0.00,'',1200.00,1200.00,0.00,0.00,0.00,1,0,1,0.00,0.00),(20,1,'2025-12-10 23:51:22',0.00,'2025-12-11 00:02:44',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(21,1,'2025-12-11 00:02:52',200.00,'2025-12-11 00:09:34',200.00,200.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(22,1,'2025-12-13 23:09:04',0.00,'2025-12-13 23:14:17',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(23,1,'2025-12-13 23:14:39',10000.00,'2025-12-13 23:14:50',10000.00,10000.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(24,1,'2025-12-14 00:22:20',10000.00,'2025-12-14 00:31:26',14800.00,14800.00,0.00,'',14600.00,4800.00,9800.00,0.00,0.00,9,0,1,0.00,0.00),(25,1,'2025-12-14 01:30:36',0.00,'2025-12-14 03:27:02',0.00,0.00,0.00,'',24000.00,0.00,24000.00,0.00,0.00,7,0,1,0.00,0.00),(26,1,'2025-12-14 18:20:22',0.00,'2025-12-14 18:34:31',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,4,1,1,0.00,0.00),(27,1,'2025-12-14 21:19:57',0.00,'2025-12-22 11:50:23',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,6,2,1,0.00,0.00),(28,1,'2025-12-22 15:15:27',0.00,'2025-12-29 11:07:53',0.00,0.00,0.00,'',59200.00,0.00,34500.00,24700.00,0.00,26,0,1,0.00,0.00),(29,3,'2025-12-29 11:14:53',10000.00,'2025-12-29 11:16:57',29800.00,29800.00,0.00,'',19800.00,19800.00,0.00,0.00,0.00,7,0,2,0.00,0.00),(30,1,'2025-12-29 11:15:25',0.00,'2025-12-29 11:15:53',5000.00,5000.00,0.00,'',5000.00,5000.00,0.00,0.00,0.00,1,0,1,0.00,0.00),(31,1,'2025-12-29 13:46:13',0.00,'2026-01-01 20:54:14',0.00,0.00,0.00,'camvio de turno',60400.00,0.00,60400.00,0.00,0.00,18,0,1,0.00,0.00),(32,3,'2025-12-29 13:46:53',0.00,'2026-01-05 20:23:05',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,2,0.00,0.00),(33,1,'2026-01-01 21:04:04',0.00,'2026-01-01 21:06:46',15200.00,15000.00,-200.00,'',5200.00,5200.00,0.00,0.00,0.00,5,0,1,0.00,0.00),(34,1,'2026-01-01 21:07:24',0.00,'2026-01-01 21:07:31',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(35,1,'2026-01-01 21:08:51',0.00,'2026-01-01 21:14:32',0.00,0.00,0.00,'',26500.00,0.00,0.00,26500.00,0.00,12,0,1,0.00,0.00),(36,3,'2026-01-01 21:14:46',0.00,'2026-01-01 21:14:49',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(37,1,'2026-01-01 21:20:09',0.00,'2026-01-01 21:51:44',0.00,0.00,0.00,'',71737.50,0.00,71737.50,0.00,0.00,25,0,1,0.00,0.00),(38,1,'2026-01-01 22:28:15',0.00,'2026-01-01 22:45:22',0.00,8200.00,8200.00,'',8200.00,0.00,8200.00,0.00,0.00,4,0,1,0.00,0.00),(39,1,'2026-01-01 23:42:42',0.00,'2026-01-01 23:50:42',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(40,1,'2026-01-03 12:59:56',0.00,'2026-01-03 13:00:20',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(41,1,'2026-01-03 17:29:38',0.00,'2026-01-06 00:14:10',5400.00,5400.00,0.00,'',57600.00,5400.00,3000.00,0.00,0.00,16,0,1,0.00,0.00),(42,1,'2026-01-06 00:41:01',0.00,'2026-01-06 01:05:46',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(43,1,'2026-01-06 01:30:41',0.00,'2026-01-06 01:30:59',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,2,0.00,0.00),(44,1,'2026-01-06 01:45:42',0.00,'2026-01-06 14:22:18',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(45,2,'2026-01-06 14:30:25',0.00,'2026-01-06 15:12:24',0.00,0.00,0.00,'',1600.00,0.00,1600.00,0.00,0.00,1,0,1,0.00,0.00),(46,1,'2026-01-06 16:39:13',0.00,'2026-01-06 16:39:53',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(47,1,'2026-01-06 16:40:30',0.00,'2026-01-06 17:00:49',0.00,0.00,0.00,'',5400.00,0.00,5400.00,0.00,0.00,4,0,1,0.00,0.00),(48,1,'2026-01-06 17:03:23',0.00,'2026-01-06 17:03:29',0.00,0.00,0.00,'',0.00,0.00,0.00,0.00,0.00,0,0,1,0.00,0.00),(49,1,'2026-01-06 17:05:43',0.00,'2026-01-08 17:05:03',600.00,0.00,-600.00,'',1600.00,1600.00,0.00,0.00,0.00,1,0,1,0.00,0.00),(50,1,'2026-01-08 17:26:04',0.00,'2026-01-08 22:45:41',0.00,0.00,0.00,'',28550.00,0.00,28550.00,0.00,0.00,7,0,1,0.00,0.00),(51,1,'2026-01-09 22:43:57',0.00,'2026-01-15 06:14:05',15000.00,15000.00,0.00,'',23350.00,15000.00,8350.00,0.00,0.00,8,0,1,0.00,0.00),(52,1,'2026-01-16 17:14:10',0.00,NULL,NULL,NULL,NULL,NULL,88948.00,34500.00,54448.00,0.00,0.00,20,0,1,0.00,0.00);
/*!40000 ALTER TABLE `caja_sesiones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clientes`
--

DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `cond_iva` varchar(30) DEFAULT NULL,
  `direccion` varchar(200) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clientes`
--

LOCK TABLES `clientes` WRITE;
/*!40000 ALTER TABLE `clientes` DISABLE KEYS */;
INSERT INTO `clientes` VALUES (1,'Marvo SA','24284929787','RI','Av. Siempre Viva 207','marvo@local.com','2612731742',1,'2025-12-10 23:05:48'),(2,'Juan Pérez 2','20123456789','CF','Av. San Martín 1234','juan.perez@example.com','+54 261 555-0199',1,'2025-12-14 03:56:32');
/*!40000 ALTER TABLE `clientes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `compra_items`
--

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
  PRIMARY KEY (`id`),
  KEY `idx_ci_compra` (`compra_id`),
  KEY `idx_ci_producto` (`producto_id`),
  CONSTRAINT `fk_ci_compra` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ci_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `compra_items`
--

LOCK TABLES `compra_items` WRITE;
/*!40000 ALTER TABLE `compra_items` DISABLE KEYS */;
INSERT INTO `compra_items` VALUES (1,1,7,20.000,14500.00,290000.00,''),(10,2,52,30.000,1200.00,36000.00,''),(11,2,56,20.000,1300.00,26000.00,''),(12,2,70,1.500,2000.00,3000.00,'');
/*!40000 ALTER TABLE `compra_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `compras`
--

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
  PRIMARY KEY (`id`),
  KEY `idx_compras_fecha` (`fecha`),
  KEY `idx_compras_estado` (`estado`),
  KEY `idx_compras_proveedor` (`proveedor_id`),
  CONSTRAINT `fk_compras_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `compras`
--

LOCK TABLES `compras` WRITE;
/*!40000 ALTER TABLE `compras` DISABLE KEYS */;
INSERT INTO `compras` VALUES (1,1,'2025-12-15 00:00:00','A','0001-00001234','CONFIRMADA',290000.00,0.00,290000.00,'',NULL,'2025-12-15 00:54:02','2025-12-15 00:54:41'),(2,2,'2026-01-03 00:00:00','asdas','asdas','BORRADOR',65000.00,0.00,65000.00,'',NULL,'2026-01-03 17:28:28','2026-01-08 19:09:49');
/*!40000 ALTER TABLE `compras` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `config_facturacion`
--

DROP TABLE IF EXISTS `config_facturacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `config_facturacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `punto_venta` int(11) NOT NULL DEFAULT 1,
  `tipo_comprobante` varchar(5) NOT NULL DEFAULT 'FA',
  `descripcion` varchar(100) DEFAULT NULL,
  `tipo_default` varchar(5) NOT NULL DEFAULT 'C',
  `proximo_numero` int(11) NOT NULL DEFAULT 1,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `config_facturacion`
--

LOCK TABLES `config_facturacion` WRITE;
/*!40000 ALTER TABLE `config_facturacion` DISABLE KEYS */;
INSERT INTO `config_facturacion` VALUES (1,1,'FA',NULL,'C',8,1);
/*!40000 ALTER TABLE `config_facturacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facturas`
--

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
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `total` decimal(12,2) NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'EMITIDA',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_facturas_numero` (`punto_venta`,`tipo`,`numero`),
  KEY `fk_facturas_venta` (`venta_id`),
  KEY `fk_facturas_cliente` (`cliente_id`),
  CONSTRAINT `fk_facturas_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_facturas_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facturas`
--

LOCK TABLES `facturas` WRITE;
/*!40000 ALTER TABLE `facturas` DISABLE KEYS */;
INSERT INTO `facturas` VALUES (1,39,1,'FA',1,1,'2025-12-10 23:52:51',1200.00,'EMITIDA','2025-12-10 23:52:51'),(2,38,1,'FA',1,2,'2025-12-11 00:14:38',44000.00,'EMITIDA','2025-12-11 00:14:38'),(3,43,1,'FA',1,3,'2025-12-14 03:00:52',24000.00,'EMITIDA','2025-12-14 03:00:52'),(4,40,1,'FA',1,4,'2025-12-14 03:12:31',4800.00,'EMITIDA','2025-12-14 03:12:31'),(5,24,1,'FA',1,5,'2025-12-14 03:13:22',1200.00,'EMITIDA','2025-12-14 03:13:22'),(6,22,1,'FA',1,6,'2025-12-14 03:18:07',24500.00,'EMITIDA','2025-12-14 03:18:07'),(7,80,1,'FA',1,7,'2026-01-06 16:50:30',5400.00,'EMITIDA','2026-01-06 16:50:30');
/*!40000 ALTER TABLE `facturas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `movimientos_stock`
--

DROP TABLE IF EXISTS `movimientos_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movimientos_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `producto_id` int(11) NOT NULL,
  `tipo` enum('VENTA','COMPRA','AJUSTE_POSITIVO','AJUSTE_NEGATIVO','ANULACION','DEVOLUCION') NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=160 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `movimientos_stock`
--

LOCK TABLES `movimientos_stock` WRITE;
/*!40000 ALTER TABLE `movimientos_stock` DISABLE KEYS */;
INSERT INTO `movimientos_stock` VALUES (45,1,'2025-11-30 19:46:45',1,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(46,1,'2025-11-30 19:46:45',3,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(47,2,'2025-11-30 21:09:14',3,'VENTA',2.000,NULL,NULL,NULL,NULL,NULL,NULL),(48,3,'2025-11-30 21:09:24',1,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(49,4,'2025-11-30 21:13:32',1,'VENTA',4.000,NULL,NULL,NULL,NULL,NULL,NULL),(50,4,'2025-11-30 21:13:32',3,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(51,5,'2025-11-30 21:19:55',1,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(52,6,'2025-11-30 22:21:30',52,'VENTA',2.000,NULL,NULL,NULL,NULL,NULL,NULL),(53,6,'2025-11-30 22:21:30',49,'VENTA',2.000,NULL,NULL,NULL,NULL,NULL,NULL),(54,7,'2025-12-01 15:37:36',1,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(55,8,'2025-12-04 16:36:55',3,'VENTA',7.000,NULL,NULL,NULL,NULL,NULL,NULL),(56,8,'2025-12-04 16:36:55',1,'VENTA',2.000,NULL,NULL,NULL,NULL,NULL,NULL),(57,9,'2025-12-04 16:52:16',3,'VENTA',7.000,NULL,NULL,NULL,NULL,NULL,NULL),(58,9,'2025-12-04 16:52:16',1,'VENTA',2.000,NULL,NULL,NULL,NULL,NULL,NULL),(59,10,'2025-12-04 21:25:21',1,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(60,10,'2025-12-04 21:25:21',30,'VENTA',4.000,NULL,NULL,NULL,NULL,NULL,NULL),(61,11,'2025-12-04 21:26:57',1,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(62,44,'2025-12-14 18:20:34',1,'VENTA',4.000,NULL,NULL,NULL,NULL,NULL,NULL),(63,44,'2025-12-14 18:32:09',1,'ANULACION',4.000,NULL,NULL,NULL,NULL,NULL,NULL),(64,33,'2025-12-14 21:10:07',54,'ANULACION',3.000,NULL,NULL,NULL,NULL,NULL,NULL),(65,45,'2025-12-14 21:20:12',70,'VENTA',5.000,NULL,NULL,NULL,NULL,NULL,NULL),(66,45,'2025-12-14 21:20:23',70,'ANULACION',5.000,NULL,NULL,NULL,NULL,NULL,NULL),(67,NULL,'2025-12-15 00:54:41',7,'COMPRA',20.000,NULL,1,'Compra #1',NULL,NULL,NULL),(68,46,'2025-12-17 15:14:54',1,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(69,46,'2025-12-18 16:48:46',1,'ANULACION',1.000,NULL,NULL,'El cliente la devolvio por que vencimiento',NULL,NULL,NULL),(70,55,'2025-12-24 11:45:30',70,'VENTA',1.000,NULL,NULL,NULL,NULL,NULL,NULL),(73,58,'2025-12-24 12:24:55',54,'VENTA',3.000,58,NULL,NULL,NULL,NULL,NULL),(74,59,'2025-12-24 12:29:16',70,'VENTA',1.000,59,NULL,NULL,NULL,NULL,NULL),(75,59,'2025-12-24 12:29:16',54,'VENTA',1.000,59,NULL,NULL,NULL,NULL,NULL),(76,59,'2025-12-24 12:29:16',30,'VENTA',1.000,59,NULL,NULL,NULL,NULL,NULL),(77,59,'2025-12-24 12:29:16',29,'VENTA',1.000,59,NULL,NULL,NULL,NULL,NULL),(78,59,'2025-12-24 12:29:16',52,'VENTA',2.000,59,NULL,NULL,NULL,NULL,NULL),(79,59,'2025-12-24 12:29:16',71,'VENTA',1.000,59,NULL,NULL,NULL,NULL,NULL),(80,59,'2025-12-24 12:29:16',72,'VENTA',1.000,59,NULL,NULL,NULL,NULL,NULL),(81,59,'2025-12-24 12:29:16',59,'VENTA',1.000,59,NULL,NULL,NULL,NULL,NULL),(82,59,'2025-12-24 12:29:16',3,'VENTA',1.000,59,NULL,NULL,NULL,NULL,NULL),(83,60,'2025-12-24 13:14:11',54,'VENTA',3.000,60,NULL,NULL,NULL,NULL,NULL),(84,61,'2025-12-24 13:27:53',74,'VENTA',3.000,61,NULL,NULL,NULL,NULL,NULL),(85,61,'2025-12-24 13:27:53',54,'VENTA',3.000,61,NULL,NULL,NULL,NULL,NULL),(86,61,'2025-12-24 13:27:53',32,'VENTA',1.000,61,NULL,NULL,NULL,NULL,NULL),(87,62,'2025-12-24 13:35:26',54,'VENTA',3.000,62,NULL,NULL,NULL,NULL,NULL),(88,63,'2025-12-29 11:15:43',70,'VENTA',1.000,63,NULL,NULL,NULL,NULL,NULL),(89,64,'2025-12-29 11:16:44',54,'VENTA',4.000,64,NULL,NULL,NULL,NULL,NULL),(90,64,'2025-12-29 11:16:44',70,'VENTA',3.000,64,NULL,NULL,NULL,NULL,NULL),(91,65,'2026-01-01 20:03:34',1,'VENTA',1.000,65,NULL,NULL,NULL,NULL,NULL),(92,65,'2026-01-01 20:03:34',54,'VENTA',3.000,65,NULL,NULL,NULL,NULL,NULL),(93,65,'2026-01-01 20:03:34',72,'VENTA',2.000,65,NULL,NULL,NULL,NULL,NULL),(94,65,'2026-01-01 20:03:34',73,'VENTA',1.000,65,NULL,NULL,NULL,NULL,NULL),(95,65,'2026-01-01 20:03:34',74,'VENTA',2.000,65,NULL,NULL,NULL,NULL,NULL),(96,66,'2026-01-01 20:09:57',70,'VENTA',1.000,66,NULL,NULL,NULL,NULL,NULL),(97,67,'2026-01-01 20:51:12',74,'VENTA',2.000,67,NULL,NULL,NULL,NULL,NULL),(98,67,'2026-01-01 20:51:12',54,'VENTA',3.000,67,NULL,NULL,NULL,NULL,NULL),(99,67,'2026-01-01 20:51:12',12,'VENTA',1.000,67,NULL,NULL,NULL,NULL,NULL),(100,67,'2026-01-01 20:51:12',56,'VENTA',2.000,67,NULL,NULL,NULL,NULL,NULL),(101,68,'2026-01-01 21:05:01',1,'VENTA',5.000,68,NULL,NULL,NULL,NULL,NULL),(102,69,'2026-01-01 21:09:59',52,'VENTA',2.000,69,NULL,NULL,NULL,NULL,NULL),(103,69,'2026-01-01 21:09:59',71,'VENTA',10.000,69,NULL,NULL,NULL,NULL,NULL),(104,70,'2026-01-01 21:21:12',1,'VENTA',5.000,70,NULL,NULL,NULL,NULL,NULL),(105,70,'2026-01-01 21:21:12',72,'VENTA',3.000,70,NULL,NULL,NULL,NULL,NULL),(106,70,'2026-01-01 21:21:12',56,'VENTA',1.000,70,NULL,NULL,NULL,NULL,NULL),(107,71,'2026-01-01 21:36:02',1,'VENTA',4.000,71,NULL,NULL,NULL,NULL,NULL),(108,71,'2026-01-01 21:36:02',54,'VENTA',3.000,71,NULL,NULL,NULL,NULL,NULL),(109,71,'2026-01-01 21:36:02',74,'VENTA',4.000,71,NULL,NULL,NULL,NULL,NULL),(110,71,'2026-01-01 21:36:02',70,'VENTA',4.000,71,NULL,NULL,NULL,NULL,NULL),(111,72,'2026-01-01 21:37:45',71,'VENTA',1.000,72,NULL,NULL,NULL,NULL,NULL),(112,73,'2026-01-01 22:28:30',70,'VENTA',1.000,73,NULL,NULL,NULL,NULL,NULL),(113,74,'2026-01-01 22:28:43',54,'VENTA',3.000,74,NULL,NULL,NULL,NULL,NULL),(114,1,'2026-01-01 22:48:17',1,'ANULACION',1.000,NULL,NULL,'test',NULL,NULL,NULL),(115,1,'2026-01-01 22:48:17',3,'ANULACION',1.000,NULL,NULL,'test',NULL,NULL,NULL),(116,NULL,'2026-01-03 14:27:58',36,'AJUSTE_POSITIVO',20.000,NULL,NULL,'',NULL,20.000,40.000),(117,NULL,'2026-01-03 14:28:06',63,'AJUSTE_POSITIVO',10.000,NULL,NULL,'',NULL,10.000,20.000),(118,NULL,'2026-01-03 14:28:09',19,'AJUSTE_POSITIVO',15.000,NULL,NULL,'',NULL,15.000,30.000),(119,NULL,'2026-01-03 14:28:15',58,'AJUSTE_POSITIVO',20.000,NULL,NULL,'',NULL,20.000,40.000),(120,NULL,'2026-01-03 14:28:19',20,'AJUSTE_POSITIVO',10.000,NULL,NULL,'',NULL,10.000,20.000),(121,NULL,'2026-01-03 14:28:22',51,'AJUSTE_POSITIVO',20.000,NULL,NULL,'',NULL,20.000,40.000),(122,NULL,'2026-01-03 14:28:27',50,'AJUSTE_POSITIVO',30.000,NULL,NULL,'',NULL,30.000,60.000),(123,NULL,'2026-01-03 14:28:36',53,'AJUSTE_POSITIVO',20.000,NULL,NULL,'',NULL,20.000,40.000),(124,75,'2026-01-05 20:35:10',54,'VENTA',3.000,75,NULL,NULL,NULL,75.000,72.000),(125,76,'2026-01-05 21:14:15',54,'VENTA',1.000,76,NULL,NULL,NULL,74.000,73.000),(126,76,'2026-01-05 21:14:15',70,'VENTA',1.000,76,NULL,NULL,NULL,77.600,76.600),(127,76,'2026-01-05 21:14:15',74,'VENTA',1.000,76,NULL,NULL,NULL,79.000,78.000),(128,76,'2026-01-05 21:14:15',20,'VENTA',2.000,76,NULL,NULL,NULL,8.000,6.000),(129,76,'2026-01-05 21:14:15',30,'VENTA',3.000,76,NULL,NULL,NULL,26.000,23.000),(130,76,'2026-01-05 21:14:15',41,'VENTA',1.000,76,NULL,NULL,NULL,3.000,2.000),(131,77,'2026-01-05 21:39:17',74,'VENTA',3.000,77,NULL,NULL,NULL,76.000,73.000),(132,78,'2026-01-05 22:51:53',74,'VENTA',1.000,78,NULL,NULL,NULL,75.000,74.000),(133,79,'2026-01-06 14:30:37',54,'VENTA',1.000,79,NULL,NULL,NULL,73.000,72.000),(134,80,'2026-01-06 16:49:16',30,'VENTA',1.000,80,NULL,NULL,NULL,25.000,24.000),(135,80,'2026-01-06 16:49:16',54,'VENTA',3.000,80,NULL,NULL,NULL,70.000,67.000),(136,81,'2026-01-06 17:07:19',54,'VENTA',1.000,81,NULL,NULL,NULL,69.000,68.000),(137,NULL,'2026-01-06 19:17:29',25,'AJUSTE_POSITIVO',23.000,NULL,NULL,'',NULL,23.000,46.000),(138,82,'2026-01-08 21:13:21',84,'VENTA',4.000,82,NULL,NULL,NULL,21.000,17.000),(139,82,'2026-01-08 21:13:21',85,'VENTA',3.000,82,NULL,NULL,NULL,12.000,9.000),(140,83,'2026-01-10 16:02:42',70,'VENTA',4.000,83,NULL,NULL,NULL,73.600,69.600),(141,83,'2026-01-10 16:02:42',54,'VENTA',4.000,83,NULL,NULL,NULL,66.000,62.000),(142,NULL,'2026-01-14 23:33:38',41,'AJUSTE_POSITIVO',45.000,NULL,NULL,'',NULL,48.000,93.000),(143,NULL,'2026-01-14 23:33:44',56,'AJUSTE_POSITIVO',30.000,NULL,NULL,'',NULL,34.000,64.000),(144,NULL,'2026-01-14 23:34:29',95,'AJUSTE_POSITIVO',10.000,NULL,NULL,'',NULL,10.000,20.000),(145,NULL,'2026-01-14 23:46:57',96,'AJUSTE_POSITIVO',10.000,NULL,NULL,'',NULL,10.000,20.000),(146,NULL,'2026-01-14 23:47:25',34,'AJUSTE_POSITIVO',10.000,NULL,NULL,'',NULL,10.000,20.000),(147,NULL,'2026-01-14 23:49:06',55,'AJUSTE_POSITIVO',10.000,NULL,NULL,'',NULL,10.000,20.000),(148,NULL,'2026-01-14 23:54:56',95,'AJUSTE_POSITIVO',20.000,NULL,NULL,'',NULL,30.000,50.000),(149,NULL,'2026-01-15 00:08:08',94,'AJUSTE_POSITIVO',30.000,NULL,NULL,'',NULL,30.000,60.000),(150,NULL,'2026-01-15 00:09:33',43,'AJUSTE_POSITIVO',10.000,NULL,NULL,'',NULL,10.000,20.000),(151,84,'2026-01-16 17:17:22',97,'VENTA',4.000,84,NULL,NULL,NULL,30.000,26.000),(152,84,'2026-01-16 17:17:22',30,'VENTA',3.000,84,NULL,NULL,NULL,23.000,20.000),(153,84,'2026-01-16 17:17:22',54,'VENTA',4.000,84,NULL,NULL,NULL,62.000,58.000),(154,NULL,'2026-01-16 18:20:52',42,'AJUSTE_POSITIVO',10.000,NULL,NULL,'',NULL,10.000,20.000),(155,85,'2026-01-16 18:55:01',95,'VENTA',3.000,85,NULL,NULL,NULL,27.000,24.000),(156,85,'2026-01-16 18:55:01',9,'VENTA',2.000,85,NULL,NULL,NULL,17.000,15.000),(157,85,'2026-01-16 18:55:01',7,'VENTA',2.000,85,NULL,NULL,NULL,65.000,63.000),(158,85,'2026-01-16 18:55:01',51,'VENTA',1.000,85,NULL,NULL,NULL,19.000,18.000),(159,85,'2026-01-16 18:55:01',71,'VENTA',1.000,85,NULL,NULL,NULL,39.000,38.000);
/*!40000 ALTER TABLE `movimientos_stock` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `before_insert_movimiento_stock` BEFORE INSERT ON `movimientos_stock` FOR EACH ROW BEGIN
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
    WHEN 'DEVOLUCION' THEN
      SET NEW.stock_nuevo = stock_actual + NEW.cantidad;
    ELSE
      SET NEW.stock_nuevo = stock_actual;
  END CASE;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `movimientos_stock_backup_7d`
--

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

--
-- Dumping data for table `movimientos_stock_backup_7d`
--

LOCK TABLES `movimientos_stock_backup_7d` WRITE;
/*!40000 ALTER TABLE `movimientos_stock_backup_7d` DISABLE KEYS */;
INSERT INTO `movimientos_stock_backup_7d` VALUES (1,'2025-11-23 03:03:50',1,'VENTA',1.000,1,NULL),(2,'2025-11-23 03:03:50',3,'VENTA',3.000,1,NULL),(3,'2025-11-23 03:15:42',7,'VENTA',2.000,2,NULL),(4,'2025-11-23 03:15:42',6,'VENTA',4.000,2,NULL),(5,'2025-11-23 03:18:25',6,'VENTA',1.000,3,NULL),(6,'2025-11-23 03:18:25',1,'VENTA',4.000,3,NULL),(7,'2025-11-23 03:18:25',7,'VENTA',1.000,3,NULL),(8,'2025-11-23 17:39:56',1,'VENTA',1.000,6,NULL),(9,'2025-11-23 17:39:56',3,'VENTA',1.000,6,NULL),(10,'2025-11-23 17:46:38',17,'VENTA',1.000,8,NULL),(11,'2025-11-23 17:50:50',41,'VENTA',1.000,10,NULL),(12,'2025-11-23 22:34:03',1,'VENTA',3.000,11,NULL),(13,'2025-11-23 22:34:03',3,'VENTA',1.000,11,NULL),(14,'2025-11-23 22:43:05',1,'VENTA',10.000,12,NULL),(15,'2025-11-23 22:43:05',3,'VENTA',8.000,12,NULL),(16,'2025-11-23 23:01:54',1,'VENTA',1.000,13,NULL),(17,'2025-11-23 23:01:54',3,'VENTA',1.000,13,NULL),(18,'2025-11-23 23:13:12',1,'VENTA',10.000,14,NULL),(19,'2025-11-23 23:13:12',3,'VENTA',1.000,14,NULL),(22,'2025-11-24 00:44:41',1,'VENTA',1.000,16,NULL),(23,'2025-11-24 00:44:41',3,'VENTA',1.000,16,NULL),(27,'2025-11-24 00:51:05',47,'VENTA',1.000,19,NULL),(28,'2025-11-24 00:51:05',1,'VENTA',1.000,19,NULL),(29,'2025-11-24 01:23:14',1,'VENTA',1.000,20,NULL),(30,'2025-11-24 01:23:14',3,'VENTA',1.000,20,NULL),(32,'2025-11-24 01:30:40',1,'VENTA',1.000,22,NULL),(33,'2025-11-24 01:30:40',9,'VENTA',1.000,22,NULL),(34,'2025-11-25 14:53:29',1,'VENTA',1.000,23,NULL),(35,'2025-11-25 14:53:29',3,'VENTA',1.000,23,NULL),(36,'2025-11-25 14:58:05',1,'VENTA',1.000,24,NULL),(37,'2025-11-25 14:58:05',3,'VENTA',1.000,24,NULL),(38,'2025-11-26 17:00:19',1,'VENTA',1.000,25,NULL),(39,'2025-11-26 17:00:39',1,'VENTA',1.000,26,NULL),(40,'2025-11-29 15:48:28',6,'VENTA',5.000,27,NULL);
/*!40000 ALTER TABLE `movimientos_stock_backup_7d` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'Ver costos','ver_costos','2025-12-04 14:59:21'),(2,'Editar productos','editar_productos','2025-12-04 14:59:21'),(3,'Editar stock','editar_stock','2025-12-04 14:59:21'),(4,'Abrir caja','abrir_caja','2025-12-04 14:59:21'),(5,'Cerrar caja','cerrar_caja','2025-12-04 14:59:21'),(6,'Ver reportes','ver_reportes','2025-12-04 14:59:21'),(7,'Administrar usuarios','administrar_usuarios','2025-12-04 14:59:21'),(8,'Ver movimientos','ver_movimientos','2025-12-04 14:59:21'),(9,'Realizar ventas','realizar_ventas','2025-12-04 14:59:21'),(10,'Ver historial de caja','ver_historial_caja','2025-12-05 00:40:49'),(11,'Administrar configuración','administrar_config','2025-12-14 03:37:53'),(14,'Modificar precio en caja','caja_modificar_precio','2025-12-14 04:25:26'),(15,'Anular ventas','anular_venta','2025-12-14 19:33:06'),(16,'Ver auditoría','ver_auditoria','2025-12-14 19:33:06'),(17,'Gestionar backups','gestionar_backups','2025-12-14 19:33:06'),(21,'Ver clientes','ver_clientes','2025-12-22 15:58:16'),(22,'Editar clientes','editar_clientes','2025-12-22 15:58:16'),(23,'Ver facturación','ver_facturacion','2025-12-22 15:58:16'),(24,'Emitir factura','emitir_factura','2025-12-22 15:58:16'),(25,'Editar promociones','editar_promos','2025-12-22 15:58:16'),(26,'Ver productos','ver_productos','2025-12-22 15:58:16'),(27,'Ver stock','ver_stock','2025-12-22 15:58:16');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `productos`
--

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `idx_productos_proveedor_id` (`proveedor_id`),
  CONSTRAINT `fk_productos_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `productos`
--

LOCK TABLES `productos` WRITE;
/*!40000 ALTER TABLE `productos` DISABLE KEYS */;
INSERT INTO `productos` VALUES (1,'1002','Coca Cola 500ml','Gaseosa','','',NULL,NULL,NULL,1200.00,20.000,1000.00,10.000,0,'UNIDAD',0.000,1,'2025-11-23 13:58:33','2026-01-01 22:48:17'),(3,'1003','Alfajor Bon o Bon','Golosinas','Arcor','Deposito Alem',NULL,'1767309577_a54d9ae96560.jpg',NULL,800.00,31.000,500.00,10.000,0,'UNIDAD',0.000,0,'2025-11-23 13:58:33','2026-01-19 21:15:09'),(6,'1001','Botella Coca-Cola','Gaseosa','','',NULL,NULL,NULL,1200.00,20.000,1000.00,10.000,0,'UNIDAD',0.000,0,'2025-11-23 13:58:33','2026-01-08 22:37:18'),(7,'1004','Fernet Branca','bebidas alcoholicas','','',NULL,NULL,NULL,15500.00,65.000,14500.00,3.000,0,'UNIDAD',0.000,1,'2025-11-23 13:58:33','2026-01-16 18:55:01'),(8,'1101','Bolsa de hielo 1 kg','','','',NULL,NULL,NULL,2000.00,98.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-12-08 21:45:35'),(9,'1102','Fernet + Coca 2.25 L','','','',NULL,NULL,NULL,18000.00,17.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-16 18:55:01'),(10,'1103','Smirnoff + Sprite 2.25 L','',NULL,NULL,NULL,NULL,NULL,13200.00,0.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2025-12-14 22:36:26'),(11,'1104','Gancia + Sprite 2.25 L','',NULL,NULL,NULL,NULL,NULL,10500.00,0.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-05 22:17:58'),(12,'1105','Smirnoff','','','',NULL,NULL,NULL,9700.00,6.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-01 20:51:12'),(13,'1106','Fernet Branca 750 cc','','','',NULL,NULL,NULL,14500.00,7.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-15 00:23:44'),(14,'1107','Santa Julia Chenin Dulce 750 cc','',NULL,NULL,NULL,NULL,NULL,6300.00,0.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2025-12-14 22:27:41'),(15,'1108','Santa Julia Malbec 750 cc','',NULL,NULL,NULL,NULL,NULL,5500.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(16,'1109','Argento Estate ORG Malbec 750 cc','','','',NULL,NULL,NULL,4000.00,0.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2025-12-14 23:01:47'),(17,'1110','Pacheco Pereda Cabernet Sauvignon 750 cc','','','',NULL,NULL,NULL,4000.00,19.000,NULL,5.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 17:46:38'),(18,'1111','Pacheco Pereda Familia Selec Malbec 750 cc','',NULL,NULL,NULL,NULL,NULL,4000.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(19,'1112','Cafayate Malbec','',NULL,NULL,NULL,NULL,NULL,4000.00,15.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-03 14:28:09'),(20,'1113','Cafayate Torrontés','',NULL,NULL,NULL,NULL,NULL,4000.00,8.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-05 21:14:15'),(21,'1114','Gancia 950 cc','','','',NULL,NULL,NULL,6800.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-15 05:39:42'),(22,'1115','Leña 1 bolsa','',NULL,NULL,NULL,NULL,NULL,3000.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(23,'1116','Leña 2 bolsas','',NULL,NULL,NULL,NULL,NULL,5500.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(24,'1117','Agua Graciani 2 L','','','',NULL,NULL,NULL,1500.00,30.000,NULL,10.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-15 05:10:20'),(25,'1118','Agua Graciani 500 ml','','','',NULL,NULL,NULL,1000.00,23.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-15 04:16:03'),(26,'1119','Soda Sifón Talca','',NULL,NULL,NULL,NULL,NULL,1500.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(27,'1120','Prity 3 L','',NULL,NULL,NULL,NULL,NULL,2500.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(28,'1121','Vino Ternuva 1 L','',NULL,NULL,NULL,NULL,NULL,2000.00,0.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-05 17:40:05'),(29,'1122','Vino Blanco 1 L','','','',NULL,NULL,NULL,2000.00,10.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-03 14:29:59'),(30,'1123','Vino Toro tinto 1 L','','','',NULL,NULL,NULL,2200.00,23.000,NULL,5.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-16 17:17:22'),(31,'1124','Talca 3 L','',NULL,NULL,NULL,NULL,NULL,2000.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(32,'1125','Jugo Fresh','','','',NULL,NULL,NULL,1500.00,30.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-15 04:18:19'),(33,'1126','Granadina Winner 3 L','','','',NULL,NULL,NULL,1800.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-15 05:39:32'),(34,'1127','Doble Cola','','','',NULL,NULL,NULL,1800.00,10.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-14 23:47:25'),(35,'1128','Speed 473 ml','',NULL,NULL,NULL,NULL,NULL,2800.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(36,'1129','Bonaqua 500 ml','',NULL,NULL,NULL,NULL,NULL,1400.00,20.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-03 14:27:58'),(37,'1130','Secco 500 ml','',NULL,NULL,NULL,NULL,NULL,900.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(38,'1131','Baggio 200 ml','',NULL,NULL,NULL,NULL,NULL,700.00,0.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2025-12-14 23:09:57'),(39,'1132','Terma','','','',NULL,NULL,NULL,2000.00,19.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-12-06 21:24:45'),(40,'1133','Quilmes botella 1 L','','','',NULL,NULL,NULL,3200.00,0.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-08 22:15:11'),(41,'1134','Schneider botella 1 L','','','',NULL,NULL,NULL,3200.00,48.000,NULL,10.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-14 23:33:38'),(42,'1135','Andes botella 1 L','',NULL,NULL,NULL,NULL,NULL,3000.00,10.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-16 18:20:52'),(43,'1136','Quilmes latón 710 cc','',NULL,NULL,NULL,NULL,NULL,3000.00,10.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-15 00:09:33'),(44,'1137','Schneider latón 710 cc','',NULL,NULL,NULL,NULL,NULL,2800.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(45,'1138','Lata Quilmes chica 473 cc','',NULL,NULL,NULL,NULL,NULL,2000.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-11-23 15:06:39'),(46,'1139','361 cerveza 1 L','Bebidas','361','',NULL,NULL,0.00,1900.00,26.000,NULL,10.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2025-11-27 11:15:21'),(47,'1140','361 cerveza 1.5 L','bebidas alcoholica','','',NULL,NULL,NULL,2200.00,45.000,NULL,10.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-08 18:01:11'),(48,'1141','361 cerveza Promo 2 x 1.5 L','Bebidas','361','',NULL,NULL,NULL,4600.00,20.000,NULL,10.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2025-12-14 22:33:14'),(49,'1142','Coca 2.5 L','asdasd','','',NULL,NULL,NULL,4600.00,97.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2025-12-08 21:45:35'),(50,'1143','Coca retornable 2.5 L','',NULL,NULL,NULL,NULL,NULL,3500.00,30.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-03 14:28:27'),(51,'1144','Coca 1.5 L','',NULL,NULL,NULL,NULL,NULL,3500.00,19.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-16 18:55:01'),(52,'1145','Coca 1 L','','','',NULL,NULL,NULL,2500.00,9.000,NULL,5.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-01 21:09:59'),(53,'7790895005374','Coca vidrio 1.25 L','','','',NULL,NULL,NULL,2400.00,20.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-03 17:33:51'),(54,'1147','Coca 500 ml','Bebidas','Coca Cola','',NULL,NULL,NULL,1600.00,62.000,NULL,5.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-16 17:17:22'),(55,'1148','Fanta 2.25 L','',NULL,NULL,NULL,NULL,NULL,4000.00,10.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-14 23:49:06'),(56,'1149','Sprite 2.25 L','','','',NULL,NULL,NULL,4000.00,34.000,NULL,10.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-14 23:33:44'),(57,'1150','Cepita 1.5 L','','','',NULL,NULL,NULL,3000.00,40.000,NULL,10.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-03 14:29:19'),(58,'1151','Cepita 1 L','',NULL,NULL,NULL,NULL,NULL,2300.00,20.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-03 14:28:15'),(59,'1152','Powerade 500 ml','',NULL,NULL,NULL,NULL,NULL,2000.00,-1.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-05 22:09:05'),(60,'1153','Powerade 995 cc','','','',NULL,NULL,NULL,3100.00,0.000,NULL,0.000,0,'UNIDAD',0.000,0,'2025-11-23 15:06:39','2026-01-08 19:58:45'),(61,'1154','Monster','','','',NULL,NULL,NULL,2800.00,10.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-06 01:06:46'),(62,'1155','Lata Coca-Cola 220 cc','','','',NULL,NULL,NULL,1000.00,100.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-08 19:02:27'),(63,'1156','Botella Coca-Cola 237 cc','',NULL,NULL,NULL,NULL,NULL,1200.00,10.000,NULL,0.000,0,'UNIDAD',0.000,1,'2025-11-23 15:06:39','2026-01-08 22:13:01'),(66,'1005','361 cerveza 1','Bebidas','361','',NULL,'1764252989_24215edd.jpg',NULL,190000.00,26.000,17000000.00,5.000,0,'UNIDAD',26.000,0,'2025-11-27 11:16:29','2026-01-15 04:16:47'),(68,'7798140259442','Jabón Facial - Carbón Detox 200ml','Higiene Personal','Aepxia','Insumos S.A',NULL,NULL,NULL,4500.00,25.000,3800.00,10.000,0,'UNIDAD',25.000,1,'2025-11-29 16:31:24','2026-01-05 19:48:57'),(69,'7790150355084','Te verde','Té','La Virginia','',NULL,NULL,NULL,1300.00,25.000,800.00,5.000,0,'UNIDAD',20.000,1,'2025-11-29 16:55:15','2025-12-07 21:23:19'),(70,'200','Carne molida','','','',NULL,NULL,NULL,5000.00,73.600,NULL,0.000,1,'KG',0.000,1,'2025-12-05 16:31:48','2026-01-10 16:02:42'),(71,'201','Bonaqua 1L','Agua Mineral','Bonaqua','Deposito Alem',NULL,NULL,NULL,2500.00,39.000,2300.00,10.000,0,'UNIDAD',50.000,1,'2025-12-08 18:59:42','2026-01-16 18:55:01'),(72,'203','Burger Chica','Rotiseria','-','-',NULL,NULL,NULL,7000.00,94.000,NULL,0.000,0,'UNIDAD',100.000,1,'2025-12-08 21:13:13','2026-01-01 21:21:12'),(73,'204','Papa Chica','Rotiseria','-','-',NULL,NULL,NULL,3500.00,99.000,NULL,0.000,0,'UNIDAD',100.000,1,'2025-12-08 21:13:49','2026-01-01 20:03:34'),(74,'206','Bola de Lomo','Carniceria','-','-',NULL,NULL,NULL,6000.00,75.000,NULL,10.000,1,'KG',0.000,1,'2025-12-08 22:46:27','2026-01-05 22:51:53'),(75,'7790895640469','Aquarius Pomelo 500 ml','bebidas','Coca Cola','Coca Cola',NULL,NULL,NULL,1500.00,20.000,1200.00,5.000,0,'UNIDAD',15.000,1,'2026-01-03 17:35:43','2026-01-15 05:29:00'),(76,'12','Test','bebidas','Coca Cola','Coca Cola',NULL,NULL,NULL,1500.00,15.000,1200.00,5.000,0,'UNIDAD',15.000,1,'2026-01-05 18:01:59','2026-01-05 18:01:59'),(77,'13','Test','bebidas','Coca Cola','Coca Cola',NULL,NULL,NULL,1500.00,15.000,1200.00,5.000,0,'UNIDAD',15.000,1,'2026-01-05 18:02:22','2026-01-05 18:02:22'),(78,'14','Jugo Fresh Pomelo','bebidas','Bagio','Go Bar',NULL,NULL,NULL,1500.00,0.000,NULL,0.000,0,'UNIDAD',0.000,1,'2026-01-05 19:05:18','2026-01-05 19:05:18'),(79,'213123','Fernet 750 Ml','bebidas alcoholicas','Branca','Go Bar',NULL,NULL,NULL,16500.00,0.000,12000.00,0.000,0,'UNIDAD',0.000,0,'2026-01-05 22:19:31','2026-01-15 00:46:30'),(80,'1232','alfajor jorgito','Golosinas','Arcor','Go Bar',NULL,NULL,NULL,1500.00,20.000,1200.00,10.000,0,'UNIDAD',20.000,1,'2026-01-06 00:36:04','2026-01-06 00:36:04'),(81,'1444','test','bebidas','','Deposito Alem',NULL,NULL,NULL,1500.00,10.000,0.00,0.000,1,'KG',0.000,1,'2026-01-08 17:07:32','2026-01-15 05:41:46'),(82,'1445','test 2','bebidas','','Deposito Alem',NULL,NULL,NULL,1500.00,0.000,0.00,0.000,0,'UNIDAD',0.000,0,'2026-01-08 17:08:08','2026-01-08 19:58:52'),(83,'5555','Papas Lays','snacks','Lays','Deposito Alem',NULL,NULL,NULL,2000.00,200.000,1500.00,50.000,1,'G',0.000,1,'2026-01-08 17:25:33','2026-01-16 18:21:41'),(84,'2001','Carne picada común','Carnicería','-','Frigorífico Don Pepe',NULL,NULL,21.00,6500.00,21.000,4800.00,3.000,1,'KG',25.000,1,'2026-01-08 20:26:46','2026-01-08 21:13:21'),(85,'2101','chizito','Snacks','-','Mayorista Central',NULL,NULL,NULL,850.00,12.000,520.00,1.000,1,'G',15.000,1,'2026-01-08 20:28:42','2026-01-08 21:13:21'),(86,'2201','Shampoo a granel','Perfumería','-','Distribuidora Higiene',NULL,NULL,21.00,300.00,20.000,160.00,2.000,1,'ML',20.000,1,'2026-01-08 20:29:37','2026-01-08 20:29:37'),(87,'2301','Vino tinto suelto','Bebidas','Casa','Bodega Local',NULL,NULL,21.00,1800.00,50.000,1200.00,5.000,1,'LT',50.000,1,'2026-01-08 20:30:35','2026-01-08 20:30:35'),(88,'9999','test','test','test','test',NULL,NULL,21.00,2313.00,123.000,123.00,23.000,0,'UNIDAD',123.000,1,'2026-01-08 21:48:39','2026-01-08 21:48:39'),(89,'1','Botella Coca-Cola 2','Gaseosa','','Insumos S.A',NULL,NULL,NULL,1200.00,20.000,1000.00,10.000,0,'UNIDAD',20.000,0,'2026-01-08 21:54:42','2026-01-08 22:28:01'),(90,'2','Botella Coca-Cola 2','Gaseosa','','Insumos S.A',NULL,NULL,NULL,1200.00,20.000,1000.00,10.000,0,'UNIDAD',20.000,0,'2026-01-08 21:59:06','2026-01-08 22:12:56'),(91,'3','Botella Coca-Cola 2','Gaseosa','','Insumos S.A',NULL,NULL,NULL,1200.00,20.000,1000.00,10.000,0,'UNIDAD',20.000,1,'2026-01-08 22:12:28','2026-01-19 21:15:21'),(92,'33','Fernet + Coca 2.25 L 2','Bebidas','Branca','Insumos S.A',NULL,NULL,NULL,18000.00,20.000,NULL,0.000,0,'UNIDAD',20.000,1,'2026-01-08 22:13:46','2026-01-15 00:23:33'),(93,'445','test','test','test','test',NULL,NULL,10.50,2323.00,123.000,232.00,32.000,0,'UNIDAD',123.000,1,'2026-01-08 22:37:03','2026-01-08 22:37:03'),(94,'99','Aquarius Pomelo 500 ml','bebidas','Coca Cola','Coca Cola',NULL,NULL,NULL,1500.00,30.000,1200.00,5.000,0,'UNIDAD',0.000,0,'2026-01-10 15:58:46','2026-01-15 00:08:08'),(95,'999','Agua Graciani 2 L','','','',NULL,NULL,NULL,1500.00,27.000,NULL,10.000,0,'UNIDAD',0.000,1,'2026-01-10 16:35:04','2026-01-16 18:55:01'),(96,'9','Aquarius Pomelo 500 ml','bebidas','Coca Cola','Coca Cola',NULL,NULL,NULL,1500.00,10.000,1200.00,5.000,0,'UNIDAD',0.000,1,'2026-01-10 16:35:28','2026-01-14 23:46:57'),(97,'1234','prueba Agua','Agua Mineral','Aepxia','Deposito Alem',NULL,NULL,21.00,12.00,30.000,32.00,12.000,0,'UNIDAD',34.000,1,'2026-01-15 05:28:45','2026-01-16 17:17:22'),(98,'123','Agua Graciani 2 L','','Aepxia','Coca Cola',NULL,NULL,NULL,1500.00,30.000,1200.00,10.000,0,'UNIDAD',30.000,1,'2026-01-15 18:27:49','2026-01-15 18:27:49');
/*!40000 ALTER TABLE `productos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promo_combo_items`
--

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

--
-- Dumping data for table `promo_combo_items`
--

LOCK TABLES `promo_combo_items` WRITE;
/*!40000 ALTER TABLE `promo_combo_items` DISABLE KEYS */;
INSERT INTO `promo_combo_items` VALUES (38,12,72,2.000),(39,12,73,1.000),(46,17,70,1.000),(47,17,84,1.000);
/*!40000 ALTER TABLE `promo_combo_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promo_productos`
--

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

--
-- Dumping data for table `promo_productos`
--

LOCK TABLES `promo_productos` WRITE;
/*!40000 ALTER TABLE `promo_productos` DISABLE KEYS */;
INSERT INTO `promo_productos` VALUES (8,13,54,3,2,NULL),(21,16,70,4,NULL,25.00);
/*!40000 ALTER TABLE `promo_productos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promos`
--

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

--
-- Dumping data for table `promos`
--

LOCK TABLES `promos` WRITE;
/*!40000 ALTER TABLE `promos` DISABLE KEYS */;
INSERT INTO `promos` VALUES (12,'2 Burger + 1 papa chica','COMBO_FIJO',1,NULL,NULL,'2025-12-08 21:15:55',15000.00),(13,'Coca 500 ml','N_PAGA_M',1,NULL,NULL,'2025-12-08 21:24:01',NULL),(16,'Oferta Carne Molida','NTH_PCT',1,NULL,NULL,'2026-01-01 21:27:17',NULL),(17,'Papas Lays','COMBO_FIJO',1,NULL,NULL,'2026-01-10 22:38:05',2333.00);
/*!40000 ALTER TABLE `promos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `proveedores`
--

DROP TABLE IF EXISTS `proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `proveedores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_proveedores_nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proveedores`
--

LOCK TABLES `proveedores` WRITE;
/*!40000 ALTER TABLE `proveedores` DISABLE KEYS */;
INSERT INTO `proveedores` VALUES (1,'Alem',NULL,NULL,NULL,NULL,1,'2025-12-15 00:54:02'),(2,'asqw',NULL,NULL,NULL,NULL,1,'2026-01-03 17:28:28');
/*!40000 ALTER TABLE `proveedores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permission`
--

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

--
-- Dumping data for table `role_permission`
--

LOCK TABLES `role_permission` WRITE;
/*!40000 ALTER TABLE `role_permission` DISABLE KEYS */;
INSERT INTO `role_permission` VALUES (1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),(1,8),(1,9),(1,10),(1,11),(1,14),(1,15),(1,16),(1,17),(1,21),(1,22),(1,23),(1,24),(1,25),(1,26),(1,27),(2,1),(2,2),(2,3),(2,4),(2,5),(2,6),(2,8),(2,9),(2,11),(2,14),(2,24),(2,25),(3,4),(3,5),(3,9),(4,1),(4,6),(4,8);
/*!40000 ALTER TABLE `role_permission` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

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

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Administrador','admin','2025-12-04 14:59:15'),(2,'Encargado','encargado','2025-12-04 14:59:15'),(3,'Cajero','cajero','2025-12-04 14:59:15'),(4,'Auditor','auditor','2025-12-04 14:59:15');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `terminal_locks`
--

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

--
-- Dumping data for table `terminal_locks`
--

LOCK TABLES `terminal_locks` WRITE;
/*!40000 ALTER TABLE `terminal_locks` DISABLE KEYS */;
INSERT INTO `terminal_locks` VALUES (1,1,'5koeu8tdtkn2484u7miusvbiir','2026-01-22 19:06:43','2026-01-22 19:05:13','2026-01-22 19:04:21');
/*!40000 ALTER TABLE `terminal_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `terminales`
--

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

--
-- Dumping data for table `terminales`
--

LOCK TABLES `terminales` WRITE;
/*!40000 ALTER TABLE `terminales` DISABLE KEYS */;
INSERT INTO `terminales` VALUES (1,'Caja 1','CAJA-01',1,'2025-12-29 09:41:50'),(2,'Caja 2','CAJA-02',1,'2025-12-29 11:08:11');
/*!40000 ALTER TABLE `terminales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

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

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Administrador FLUS','admin@flus.local','admin','$2y$10$b56wFdlZyZvCNuoJo.4Aj.YItKEJz9ObjLAU0ndyUYsiClwBVPaXG',1,1,'2025-12-04 16:07:15',NULL,NULL),(2,'Juan','juan@flus.local','juan','$2y$10$gAW4uGHHsh0IoxiuDW7aP.0zxCBb7FfxEFKhb4OIfwDGAVmhkLGHS',1,1,'2025-12-04 16:42:28',NULL,NULL),(3,'caja1','caja@local.com','caja1','$2y$10$lCa0OFO7A8RjqpLK.8na7OYyLvtUl9CU6mj3IOutU883LlK3CqSWq',4,1,'2025-12-04 17:04:56',NULL,'2026-01-06 15:13:34');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `users_before_update` BEFORE UPDATE ON `users` FOR EACH ROW BEGIN
    SET NEW.updated_at = NOW();
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Temporary table structure for view `v_movimientos_stock_resumen`
--

DROP TABLE IF EXISTS `v_movimientos_stock_resumen`;
/*!50001 DROP VIEW IF EXISTS `v_movimientos_stock_resumen`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_movimientos_stock_resumen` AS SELECT
 1 AS `producto_id`,
  1 AS `codigo`,
  1 AS `nombre`,
  1 AS `total_movimientos`,
  1 AS `total_entradas`,
  1 AS `total_salidas`,
  1 AS `ultimo_movimiento` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_usuarios_completo`
--

DROP TABLE IF EXISTS `v_usuarios_completo`;
/*!50001 DROP VIEW IF EXISTS `v_usuarios_completo`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_usuarios_completo` AS SELECT
 1 AS `id`,
  1 AS `nombre`,
  1 AS `email`,
  1 AS `username`,
  1 AS `activo`,
  1 AS `ultimo_acceso`,
  1 AS `created_at`,
  1 AS `updated_at`,
  1 AS `rol_id`,
  1 AS `rol_nombre`,
  1 AS `dias_sin_acceso`,
  1 AS `estado_texto` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `venta_fiscal`
--

DROP TABLE IF EXISTS `venta_fiscal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_fiscal` (
  `venta_id` int(11) NOT NULL,
  `pto_vta` int(11) DEFAULT NULL,
  `tipo_cmp` int(11) DEFAULT NULL,
  `nro_cmp` int(11) DEFAULT NULL,
  `cae` bigint(20) DEFAULT NULL,
  `cae_vto` date DEFAULT NULL,
  `moneda` varchar(3) DEFAULT NULL,
  `ctz` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`venta_id`),
  CONSTRAINT `venta_fiscal_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `venta_fiscal`
--

LOCK TABLES `venta_fiscal` WRITE;
/*!40000 ALTER TABLE `venta_fiscal` DISABLE KEYS */;
/*!40000 ALTER TABLE `venta_fiscal` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `venta_items`
--

DROP TABLE IF EXISTS `venta_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL DEFAULT 1.000,
  `precio` decimal(10,2) NOT NULL,
  `precio_unit_original` decimal(10,2) DEFAULT NULL,
  `descuento_monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `precio_unit_final` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  KEY `producto_id` (`producto_id`),
  KEY `idx_vi_venta_id` (`venta_id`),
  KEY `idx_vi_producto_id` (`producto_id`),
  KEY `idx_vi_venta` (`venta_id`),
  KEY `idx_vi_producto_venta` (`producto_id`,`venta_id`),
  CONSTRAINT `fk_venta_items_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_venta_items_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `venta_items_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `venta_items_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=139 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `venta_items`
--

LOCK TABLES `venta_items` WRITE;
/*!40000 ALTER TABLE `venta_items` DISABLE KEYS */;
INSERT INTO `venta_items` VALUES (1,1,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(2,1,3,1.000,800.00,NULL,0.00,0.00,800.00),(3,2,3,2.000,800.00,NULL,0.00,0.00,1600.00),(4,3,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(5,4,1,4.000,1200.00,NULL,0.00,0.00,4800.00),(6,4,3,1.000,800.00,NULL,0.00,0.00,800.00),(7,5,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(8,6,52,2.000,2500.00,NULL,0.00,0.00,5000.00),(9,6,49,2.000,4500.00,NULL,0.00,0.00,9000.00),(10,7,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(11,8,3,7.000,800.00,NULL,0.00,0.00,5600.00),(12,8,1,2.000,1200.00,NULL,0.00,0.00,2400.00),(13,9,3,7.000,800.00,NULL,0.00,0.00,5600.00),(14,9,1,2.000,1200.00,NULL,0.00,0.00,2400.00),(15,10,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(16,10,30,4.000,2200.00,NULL,0.00,0.00,8800.00),(17,11,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(18,12,1,1.000,1200.00,1200.00,0.00,1200.00,1200.00),(19,13,70,0.350,5000.00,5000.00,0.00,5000.00,1750.00),(20,14,70,0.400,5000.00,5000.00,0.00,5000.00,2000.00),(21,15,1,3.000,1200.00,1200.00,0.00,1200.00,3600.00),(22,15,70,1.000,5000.00,5000.00,0.00,5000.00,5000.00),(23,16,70,1.000,5000.00,5000.00,0.00,5000.00,5000.00),(24,17,54,1.000,1600.00,1600.00,0.00,1600.00,1600.00),(25,18,54,1.000,1500.00,1600.00,100.00,1500.00,1500.00),(26,19,70,1.000,2500.00,5000.00,2500.00,2500.00,2500.00),(27,19,1,1.000,700.00,1200.00,500.00,700.00,700.00),(28,19,39,1.000,2000.00,2000.00,0.00,2000.00,2000.00),(29,22,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(30,22,3,1.000,800.00,NULL,0.00,0.00,800.00),(31,22,70,5.000,4500.00,NULL,0.00,0.00,22500.00),(32,23,1,1.000,960.00,NULL,0.00,0.00,960.00),(33,23,1,2.000,1200.00,NULL,0.00,0.00,2400.00),(34,24,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(35,28,1,5.000,1200.00,NULL,0.00,0.00,4800.00),(36,28,12,1.000,9700.00,NULL,0.00,0.00,9700.00),(37,28,56,1.000,4000.00,NULL,0.00,0.00,4000.00),(38,29,1,4.000,1200.00,NULL,0.00,0.00,3600.00),(39,29,3,4.000,800.00,NULL,0.00,0.00,2400.00),(40,29,12,1.000,9700.00,NULL,0.00,0.00,9700.00),(41,29,56,1.000,4000.00,NULL,0.00,0.00,4000.00),(42,30,1,4.000,1200.00,NULL,0.00,0.00,3600.00),(43,30,3,5.000,800.00,NULL,0.00,0.00,3200.00),(44,31,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(45,32,8,1.000,2000.00,NULL,0.00,0.00,2000.00),(46,32,7,1.000,15500.00,NULL,0.00,0.00,15500.00),(47,32,49,1.000,4600.00,NULL,0.00,0.00,4600.00),(48,33,54,3.000,1600.00,NULL,0.00,0.00,3200.00),(49,34,49,1.000,4600.00,NULL,0.00,0.00,4600.00),(50,34,7,1.000,15500.00,NULL,0.00,0.00,15500.00),(51,35,54,4.000,1600.00,NULL,0.00,0.00,4800.00),(52,36,49,1.000,4600.00,NULL,0.00,0.00,3954.75),(53,36,7,1.000,15500.00,NULL,0.00,0.00,13325.79),(54,36,8,1.000,2000.00,NULL,0.00,0.00,1719.46),(55,37,74,7.000,6000.00,NULL,0.00,0.00,42000.00),(56,38,30,20.000,2200.00,NULL,0.00,0.00,44000.00),(57,39,1,1.000,1200.00,NULL,0.00,0.00,1200.00),(58,40,54,4.000,1200.00,1600.00,1600.00,1200.00,4800.00),(59,41,70,1.000,5000.00,5000.00,0.00,5000.00,5000.00),(60,42,54,4.000,1200.00,1600.00,1600.00,1200.00,4800.00),(61,43,74,2.000,5400.00,6000.00,1200.00,5400.00,10800.00),(62,43,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(63,43,12,1.000,7080.29,9700.00,2619.71,7080.29,7080.29),(64,43,56,1.000,2919.71,4000.00,1080.29,2919.71,2919.71),(65,44,1,4.000,1200.00,1200.00,0.00,1200.00,4800.00),(66,45,70,5.000,5000.00,5000.00,0.00,5000.00,25000.00),(67,46,1,1.000,1200.00,1200.00,0.00,1200.00,1200.00),(70,55,70,1.000,5000.00,NULL,0.00,0.00,5000.00),(73,58,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(74,59,70,1.000,5000.00,5000.00,0.00,5000.00,5000.00),(75,59,54,1.000,1600.00,1600.00,0.00,1600.00,1600.00),(76,59,30,1.000,2200.00,2200.00,0.00,2200.00,2200.00),(77,59,29,1.000,2000.00,2000.00,0.00,2000.00,2000.00),(78,59,52,2.000,2500.00,2500.00,0.00,2500.00,5000.00),(79,59,71,1.000,2500.00,2500.00,0.00,2500.00,2500.00),(80,59,72,1.000,7000.00,7000.00,0.00,7000.00,7000.00),(81,59,59,1.000,2000.00,2000.00,0.00,2000.00,2000.00),(82,59,3,1.000,800.00,800.00,0.00,800.00,800.00),(83,60,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(84,61,74,3.000,5600.00,6000.00,1200.00,5600.00,16800.00),(85,61,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(86,61,32,1.000,1500.00,1500.00,0.00,1500.00,1500.00),(87,62,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(88,63,70,1.000,5000.00,5000.00,0.00,5000.00,5000.00),(89,64,54,4.000,1200.00,1600.00,1600.00,1200.00,4800.00),(90,64,70,3.000,5000.00,5000.00,0.00,5000.00,15000.00),(91,65,1,1.000,1200.00,1200.00,0.00,1200.00,1200.00),(92,65,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(93,65,72,2.000,6000.00,7000.00,2000.00,6000.00,12000.00),(94,65,73,1.000,3000.00,3500.00,500.00,3000.00,3000.00),(95,65,74,2.000,5400.00,6000.00,1200.00,5400.00,10800.00),(96,66,70,1.000,5000.00,5000.00,0.00,5000.00,5000.00),(97,67,74,2.000,4860.00,6000.00,2280.00,4860.00,9720.00),(98,67,54,3.000,960.00,1600.00,1920.00,960.00,2880.00),(99,67,12,1.000,6372.26,9700.00,3327.74,6372.26,6372.26),(100,67,56,2.000,3113.87,4000.00,1772.26,3113.87,6227.74),(101,68,1,5.000,1140.00,1200.00,300.00,1140.00,5700.00),(102,69,52,2.000,2250.00,2500.00,500.00,2250.00,4500.00),(103,69,71,10.000,2500.00,2500.00,0.00,2500.00,25000.00),(104,70,1,5.000,1140.00,1200.00,300.00,1140.00,5700.00),(105,70,72,3.000,5700.00,7000.00,3900.00,5700.00,17100.00),(106,70,56,1.000,3800.00,4000.00,200.00,3800.00,3800.00),(107,71,1,4.000,1200.00,1200.00,0.00,1200.00,4800.00),(108,71,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(109,71,74,4.000,6000.00,6000.00,0.00,6000.00,24000.00),(110,71,70,4.000,4687.50,5000.00,1250.00,4687.50,18750.00),(111,72,71,1.000,2000.00,2500.00,500.00,2000.00,2000.00),(112,73,70,1.000,5000.00,5000.00,0.00,5000.00,5000.00),(113,74,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(114,75,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(115,76,54,1.000,1600.00,1600.00,0.00,1600.00,1600.00),(116,76,70,1.000,5000.00,5000.00,0.00,5000.00,5000.00),(117,76,74,1.000,6000.00,6000.00,0.00,6000.00,6000.00),(118,76,20,2.000,4000.00,4000.00,0.00,4000.00,8000.00),(119,76,30,3.000,2200.00,2200.00,0.00,2200.00,6600.00),(120,76,41,1.000,3200.00,3200.00,0.00,3200.00,3200.00),(121,77,74,3.000,6000.00,6000.00,0.00,6000.00,18000.00),(122,78,74,1.000,6000.00,6000.00,0.00,6000.00,6000.00),(123,79,54,1.000,1600.00,1600.00,0.00,1600.00,1600.00),(124,80,30,1.000,2200.00,2200.00,0.00,2200.00,2200.00),(125,80,54,3.000,1066.67,1600.00,1600.00,1066.67,3200.00),(126,81,54,1.000,1600.00,1600.00,0.00,1600.00,1600.00),(127,82,84,4.000,6500.00,6500.00,0.00,6500.00,26000.00),(128,82,85,3.000,850.00,850.00,0.00,850.00,2550.00),(129,83,70,4.000,4687.50,5000.00,1250.00,4687.50,18750.00),(130,83,54,4.000,1200.00,1600.00,1600.00,1200.00,4800.00),(131,84,97,4.000,12.00,12.00,0.00,12.00,48.00),(132,84,30,3.000,2200.00,2200.00,0.00,2200.00,6600.00),(133,84,54,4.000,1200.00,1600.00,1600.00,1200.00,4800.00),(134,85,95,3.000,1500.00,1500.00,0.00,1500.00,4500.00),(135,85,9,2.000,18000.00,18000.00,0.00,18000.00,36000.00),(136,85,7,2.000,15500.00,15500.00,0.00,15500.00,31000.00),(137,85,51,1.000,3500.00,3500.00,0.00,3500.00,3500.00),(138,85,71,1.000,2500.00,2500.00,0.00,2500.00,2500.00);
/*!40000 ALTER TABLE `venta_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `venta_pagos`
--

DROP TABLE IF EXISTS `venta_pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `medio_pago` varchar(20) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`),
  KEY `idx_medio` (`medio_pago`),
  CONSTRAINT `fk_venta_pagos_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `venta_pagos`
--

LOCK TABLES `venta_pagos` WRITE;
/*!40000 ALTER TABLE `venta_pagos` DISABLE KEYS */;
INSERT INTO `venta_pagos` VALUES (1,1,'EFECTIVO',2000.00,'2026-01-05 23:59:33'),(2,2,'EFECTIVO',1600.00,'2026-01-05 23:59:33'),(3,3,'EFECTIVO',1200.00,'2026-01-05 23:59:33'),(4,4,'MP',5600.00,'2026-01-05 23:59:33'),(5,5,'EFECTIVO',1200.00,'2026-01-05 23:59:33'),(6,6,'DEBITO',14000.00,'2026-01-05 23:59:33'),(7,7,'EFECTIVO',1200.00,'2026-01-05 23:59:33'),(8,8,'EFECTIVO',8000.00,'2026-01-05 23:59:33'),(9,9,'EFECTIVO',8000.00,'2026-01-05 23:59:33'),(10,10,'EFECTIVO',10000.00,'2026-01-05 23:59:33'),(11,11,'EFECTIVO',1200.00,'2026-01-05 23:59:33'),(12,12,'EFECTIVO',1200.00,'2026-01-05 23:59:33'),(13,13,'EFECTIVO',1750.00,'2026-01-05 23:59:33'),(14,14,'EFECTIVO',2000.00,'2026-01-05 23:59:33'),(15,15,'EFECTIVO',8600.00,'2026-01-05 23:59:33'),(16,16,'EFECTIVO',5000.00,'2026-01-05 23:59:33'),(17,17,'EFECTIVO',1600.00,'2026-01-05 23:59:33'),(18,18,'EFECTIVO',1500.00,'2026-01-05 23:59:33'),(19,19,'MP',5200.00,'2026-01-05 23:59:33'),(20,22,'EFECTIVO',24500.00,'2026-01-05 23:59:33'),(21,23,'EFECTIVO',3360.00,'2026-01-05 23:59:33'),(22,24,'EFECTIVO',1200.00,'2026-01-05 23:59:33'),(23,28,'EFECTIVO',18500.00,'2026-01-05 23:59:33'),(24,29,'EFECTIVO',19700.00,'2026-01-05 23:59:33'),(25,30,'EFECTIVO',6800.00,'2026-01-05 23:59:33'),(26,31,'EFECTIVO',1200.00,'2026-01-05 23:59:33'),(27,32,'EFECTIVO',22100.00,'2026-01-05 23:59:33'),(28,33,'EFECTIVO',3200.00,'2026-01-05 23:59:33'),(29,34,'EFECTIVO',20100.00,'2026-01-05 23:59:33'),(30,35,'EFECTIVO',4800.00,'2026-01-05 23:59:33'),(31,36,'EFECTIVO',19000.00,'2026-01-05 23:59:33'),(32,37,'MP',42000.00,'2026-01-05 23:59:33'),(33,38,'MP',44000.00,'2026-01-05 23:59:33'),(34,39,'EFECTIVO',1200.00,'2026-01-05 23:59:33'),(35,40,'MP',4800.00,'2026-01-05 23:59:33'),(36,41,'MP',5000.00,'2026-01-05 23:59:33'),(37,42,'EFECTIVO',4800.00,'2026-01-05 23:59:33'),(38,43,'MP',24000.00,'2026-01-05 23:59:33'),(39,44,'MP',4800.00,'2026-01-05 23:59:33'),(40,45,'EFECTIVO',25000.00,'2026-01-05 23:59:33'),(41,46,'EFECTIVO',1200.00,'2026-01-05 23:59:33'),(42,55,'EFECTIVO',5000.00,'2026-01-05 23:59:33'),(43,58,'MP',3200.00,'2026-01-05 23:59:33'),(44,59,'MP',28100.00,'2026-01-05 23:59:33'),(45,60,'MP',3200.00,'2026-01-05 23:59:33'),(46,61,'DEBITO',21500.00,'2026-01-05 23:59:33'),(47,62,'DEBITO',3200.00,'2026-01-05 23:59:33'),(48,63,'EFECTIVO',5000.00,'2026-01-05 23:59:33'),(49,64,'EFECTIVO',19800.00,'2026-01-05 23:59:33'),(50,65,'MP',30200.00,'2026-01-05 23:59:33'),(51,66,'MP',5000.00,'2026-01-05 23:59:33'),(52,67,'MP',25200.00,'2026-01-05 23:59:33'),(53,68,'EFECTIVO',5200.00,'2026-01-05 23:59:33'),(54,69,'DEBITO',26500.00,'2026-01-05 23:59:33'),(55,70,'MP',26600.00,'2026-01-05 23:59:33'),(56,71,'MP',43137.50,'2026-01-05 23:59:33'),(57,72,'MP',2000.00,'2026-01-05 23:59:33'),(58,73,'MP',5000.00,'2026-01-05 23:59:33'),(59,74,'MP',3200.00,'2026-01-05 23:59:33'),(60,75,'EFECTIVO',3200.00,'2026-01-05 23:59:33'),(64,78,'EFECTIVO',3000.00,'2026-01-06 01:51:53'),(65,78,'MP',3000.00,'2026-01-06 01:51:53'),(66,79,'MP',1600.00,'2026-01-06 17:30:37'),(67,80,'MP',5400.00,'2026-01-06 19:49:16'),(68,81,'EFECTIVO',1600.00,'2026-01-06 20:07:19'),(69,82,'MP',28550.00,'2026-01-09 00:13:21'),(70,83,'EFECTIVO',15000.00,'2026-01-10 19:02:42'),(71,83,'MP',8350.00,'2026-01-10 19:02:42'),(72,84,'EFECTIVO',7000.00,'2026-01-16 20:17:22'),(73,84,'MP',4448.00,'2026-01-16 20:17:22'),(74,85,'MP',50000.00,'2026-01-16 21:55:01'),(75,85,'EFECTIVO',30000.00,'2026-01-16 21:55:01');
/*!40000 ALTER TABLE `venta_pagos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `venta_promos`
--

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
  `descuento_monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vp_venta_id` (`venta_id`),
  KEY `idx_vp_promo_id` (`promo_id`),
  CONSTRAINT `fk_vp_promo` FOREIGN KEY (`promo_id`) REFERENCES `promos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vp_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `venta_promos`
--

LOCK TABLES `venta_promos` WRITE;
/*!40000 ALTER TABLE `venta_promos` DISABLE KEYS */;
INSERT INTO `venta_promos` VALUES (1,60,NULL,'N_PAGA_M','Coca 500 ml','Promo 3x2 (producto_id=54)',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":0}','2025-12-24 13:14:11'),(2,61,NULL,'NTH_PCT','Oferta Lomo','20% a la N°2',1200.00,'{\"producto_id\":74,\"n\":2,\"porcentaje\":20,\"u_desc\":1}','2025-12-24 13:27:53'),(3,61,NULL,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":0}','2025-12-24 13:27:53'),(4,62,NULL,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":0}','2025-12-24 13:35:26'),(5,64,NULL,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":1}','2025-12-29 11:16:44'),(6,65,NULL,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":0}','2026-01-01 20:03:34'),(7,65,NULL,'NTH_PCT','Oferta Lomo','20% a la N°2',1200.00,'{\"producto_id\":74,\"n\":2,\"porcentaje\":20,\"u_desc\":1}','2026-01-01 20:03:34'),(8,65,12,'COMBO_FIJO','2 Burger + 1 papa chica','Combo fijo x1',2500.00,'{\"combos\":1,\"precio_combo\":15000,\"items\":[{\"producto_id\":72,\"cantidad\":2},{\"producto_id\":73,\"cantidad\":1}]}','2026-01-01 20:03:34'),(9,67,NULL,'NTH_PCT','Oferta Lomo','20% a la N°2',1200.00,'{\"producto_id\":74,\"n\":2,\"porcentaje\":20,\"u_desc\":1}','2026-01-01 20:51:12'),(10,67,NULL,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":0}','2026-01-01 20:51:12'),(11,67,NULL,'COMBO_FIJO','Smirnof+Sprite','Combo fijo x1',3700.00,'{\"combos\":1,\"precio_combo\":10000,\"items\":[{\"producto_id\":56,\"cantidad\":1},{\"producto_id\":12,\"cantidad\":1}]}','2026-01-01 20:51:12'),(12,67,NULL,'DESC_GLOBAL','Descuento total','10%',2800.00,'{\"tipo\":\"porcentaje\",\"valor\":10}','2026-01-01 20:51:12'),(13,68,NULL,'DESC_GLOBAL','Descuento total','-$500,00',500.00,'{\"tipo\":\"monto\",\"valor\":500}','2026-01-01 21:05:01'),(14,69,NULL,'DESC_GLOBAL','Descuento total','-$3.000,00',3000.00,'{\"tipo\":\"monto\",\"valor\":3000}','2026-01-01 21:09:59'),(15,70,NULL,'DESC_GLOBAL','Descuento total','5%',1400.00,'{\"tipo\":\"porcentaje\",\"valor\":5}','2026-01-01 21:21:12'),(16,71,NULL,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":0,\"es_pesable\":false}','2026-01-01 21:36:02'),(17,71,NULL,'NTH_PCT','Oferta Carne Molida','25% a la N°3',1250.00,'{\"producto_id\":70,\"n\":3,\"porcentaje\":25,\"u_desc\":1,\"es_pesable\":true}','2026-01-01 21:36:02'),(18,71,NULL,'DESC_GLOBAL','Descuento total','15%',7612.50,'{\"tipo\":\"porcentaje\",\"valor\":15,\"aplicado_por_user_id\":1}','2026-01-01 21:36:02'),(19,74,NULL,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":0,\"es_pesable\":false}','2026-01-01 22:28:43'),(20,75,13,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":0,\"es_pesable\":false}','2026-01-05 20:35:10'),(21,80,13,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":0,\"es_pesable\":false}','2026-01-06 16:49:16'),(22,83,16,'NTH_PCT','Oferta Carne Molida','25% a la N°4',1250.00,'{\"producto_id\":70,\"n\":4,\"porcentaje\":25,\"u_desc\":1,\"es_pesable\":true}','2026-01-10 16:02:42'),(23,83,13,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":1,\"es_pesable\":false}','2026-01-10 16:02:42'),(24,83,NULL,'DESC_GLOBAL','Descuento total','-$200,00',200.00,'{\"tipo\":\"monto\",\"valor\":200,\"aplicado_por_user_id\":1}','2026-01-10 16:02:42'),(25,84,13,'N_PAGA_M','Coca 500 ml','Promo 3x2',1600.00,'{\"producto_id\":54,\"n\":3,\"m\":2,\"packs\":1,\"resto\":1,\"es_pesable\":false}','2026-01-16 17:17:22');
/*!40000 ALTER TABLE `venta_promos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `venta_tags`
--

DROP TABLE IF EXISTS `venta_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `tag` varchar(50) NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_venta_tag` (`venta_id`,`tag`),
  KEY `idx_venta_tags_venta` (`venta_id`),
  KEY `idx_venta_tags_tag` (`tag`),
  CONSTRAINT `fk_venta_tags_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `venta_tags`
--

LOCK TABLES `venta_tags` WRITE;
/*!40000 ALTER TABLE `venta_tags` DISABLE KEYS */;
/*!40000 ALTER TABLE `venta_tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ventas`
--

DROP TABLE IF EXISTS `ventas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `total` decimal(10,2) NOT NULL,
  `descuento_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `recargo_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `medio_pago` varchar(20) NOT NULL,
  `monto_pagado` decimal(10,2) NOT NULL,
  `vuelto` decimal(10,2) NOT NULL,
  `nota` varchar(255) DEFAULT NULL,
  `caja_id` int(11) DEFAULT NULL,
  `estado` enum('EMITIDA','ANULADA') NOT NULL DEFAULT 'EMITIDA',
  `anulado_en` datetime DEFAULT NULL,
  `anulado_por` int(11) DEFAULT NULL,
  `anulado_motivo` varchar(255) DEFAULT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `ultima_visualizacion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ventas_fecha` (`fecha`),
  KEY `idx_ventas_estado` (`estado`),
  KEY `fk_ventas_anulado_por` (`anulado_por`),
  KEY `idx_ventas_cliente_fecha` (`cliente_id`,`fecha`),
  KEY `idx_ventas_estado_fecha` (`estado`,`fecha`),
  CONSTRAINT `fk_ventas_anulado_por` FOREIGN KEY (`anulado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ventas`
--

LOCK TABLES `ventas` WRITE;
/*!40000 ALTER TABLE `ventas` DISABLE KEYS */;
INSERT INTO `ventas` VALUES (1,'2025-11-30 19:46:45',2000.00,0.00,0.00,'EFECTIVO',4000.00,2000.00,NULL,NULL,'ANULADA','2026-01-01 22:48:17',1,'test',NULL,NULL,NULL),(2,'2025-11-30 21:09:14',1600.00,0.00,0.00,'EFECTIVO',2000.00,400.00,NULL,NULL,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(3,'2025-11-30 21:09:24',1200.00,0.00,0.00,'EFECTIVO',2000.00,800.00,NULL,NULL,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(4,'2025-11-30 21:13:32',5600.00,0.00,0.00,'MP',5600.00,0.00,NULL,NULL,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(5,'2025-11-30 21:19:55',1200.00,0.00,0.00,'EFECTIVO',2000.00,800.00,NULL,NULL,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(6,'2025-11-30 22:21:30',14000.00,0.00,0.00,'DEBITO',15000.00,1000.00,NULL,NULL,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(7,'2025-12-01 15:37:36',1200.00,0.00,0.00,'EFECTIVO',2000.00,800.00,NULL,NULL,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(8,'2025-12-04 16:36:55',8000.00,0.00,0.00,'EFECTIVO',18000.00,10000.00,NULL,NULL,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(9,'2025-12-04 16:52:16',8000.00,0.00,0.00,'EFECTIVO',18000.00,10000.00,NULL,2,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(10,'2025-12-04 21:25:21',10000.00,0.00,0.00,'EFECTIVO',15000.00,5000.00,NULL,3,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(11,'2025-12-04 21:26:57',1200.00,0.00,0.00,'EFECTIVO',2000.00,800.00,NULL,4,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(12,'2025-12-05 15:34:31',1200.00,0.00,0.00,'EFECTIVO',1200.00,0.00,NULL,9,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(13,'2025-12-05 20:57:58',1750.00,0.00,0.00,'EFECTIVO',2000.00,250.00,NULL,11,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(14,'2025-12-05 21:08:17',2000.00,0.00,0.00,'EFECTIVO',2000.00,0.00,NULL,12,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(15,'2025-12-06 20:12:22',8600.00,0.00,0.00,'EFECTIVO',9000.00,400.00,NULL,12,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(16,'2025-12-06 20:19:54',5000.00,0.00,0.00,'EFECTIVO',5000.00,0.00,NULL,12,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(17,'2025-12-06 20:45:58',1600.00,0.00,0.00,'EFECTIVO',1600.00,0.00,NULL,12,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(18,'2025-12-06 21:11:52',1500.00,100.00,0.00,'EFECTIVO',1500.00,0.00,NULL,12,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(19,'2025-12-06 21:24:45',5200.00,3000.00,0.00,'MP',5200.00,0.00,NULL,13,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(22,'2025-12-07 00:19:57',24500.00,2500.00,0.00,'EFECTIVO',25000.00,500.00,NULL,13,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(23,'2025-12-07 01:02:32',3360.00,240.00,0.00,'EFECTIVO',4000.00,640.00,NULL,14,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(24,'2025-12-08 15:20:19',1200.00,0.00,0.00,'EFECTIVO',2000.00,800.00,NULL,15,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(28,'2025-12-08 16:17:51',18500.00,1200.00,0.00,'EFECTIVO',20000.00,1500.00,NULL,15,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(29,'2025-12-08 16:20:24',19700.00,2000.00,0.00,'EFECTIVO',20000.00,300.00,NULL,15,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(30,'2025-12-08 17:03:03',6800.00,2000.00,0.00,'EFECTIVO',7000.00,200.00,NULL,15,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(31,'2025-12-08 17:04:59',1200.00,0.00,0.00,'EFECTIVO',2000.00,800.00,NULL,15,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(32,'2025-12-08 21:22:22',22100.00,0.00,0.00,'EFECTIVO',23000.00,900.00,NULL,17,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(33,'2025-12-08 21:24:48',3200.00,1600.00,0.00,'EFECTIVO',3200.00,0.00,NULL,17,'ANULADA','2025-12-14 21:10:07',1,NULL,NULL,NULL,NULL),(34,'2025-12-08 21:29:50',20100.00,0.00,0.00,'EFECTIVO',21000.00,900.00,NULL,17,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(35,'2025-12-08 21:42:04',4800.00,1600.00,0.00,'EFECTIVO',5000.00,200.00,NULL,17,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(36,'2025-12-08 21:45:35',19000.00,3100.00,0.00,'EFECTIVO',20000.00,1000.00,NULL,17,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(37,'2025-12-08 22:47:10',42000.00,0.00,0.00,'MP',42000.00,0.00,NULL,18,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(38,'2025-12-08 23:12:22',44000.00,0.00,0.00,'MP',45000.00,1000.00,NULL,18,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(39,'2025-12-08 23:20:15',1200.00,0.00,0.00,'EFECTIVO',1200.00,0.00,NULL,19,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(40,'2025-12-14 00:22:53',4800.00,1600.00,0.00,'MP',4800.00,0.00,NULL,24,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(41,'2025-12-14 00:23:40',5000.00,0.00,0.00,'MP',5000.00,0.00,NULL,24,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(42,'2025-12-14 00:28:54',4800.00,1600.00,0.00,'EFECTIVO',4800.00,0.00,NULL,24,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(43,'2025-12-14 01:31:59',24000.00,6500.00,0.00,'MP',24000.00,0.00,NULL,25,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(44,'2025-12-14 18:20:34',4800.00,0.00,0.00,'MP',4800.00,0.00,NULL,26,'ANULADA','2025-12-14 18:32:09',1,NULL,NULL,NULL,NULL),(45,'2025-12-14 21:20:12',25000.00,0.00,0.00,'EFECTIVO',25000.00,0.00,NULL,27,'ANULADA','2025-12-14 21:20:23',1,NULL,NULL,NULL,NULL),(46,'2025-12-17 15:14:54',1200.00,0.00,0.00,'EFECTIVO',1200.00,0.00,NULL,27,'ANULADA','2025-12-18 16:48:46',1,'El cliente la devolvio por que vencimiento',NULL,NULL,NULL),(55,'2025-12-24 11:45:30',5000.00,0.00,0.00,'EFECTIVO',5000.00,0.00,NULL,NULL,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(58,'2025-12-24 12:24:55',3200.00,1600.00,0.00,'MP',3200.00,0.00,NULL,28,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(59,'2025-12-24 12:29:16',28100.00,0.00,0.00,'MP',28100.00,0.00,NULL,28,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(60,'2025-12-24 13:14:11',3200.00,1600.00,0.00,'MP',3200.00,0.00,NULL,28,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(61,'2025-12-24 13:27:53',21500.00,2800.00,0.00,'DEBITO',21500.00,0.00,NULL,28,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(62,'2025-12-24 13:35:26',3200.00,1600.00,0.00,'DEBITO',3200.00,0.00,NULL,28,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(63,'2025-12-29 11:15:43',5000.00,0.00,0.00,'EFECTIVO',5000.00,0.00,NULL,30,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(64,'2025-12-29 11:16:44',19800.00,1600.00,0.00,'EFECTIVO',20000.00,200.00,NULL,29,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(65,'2026-01-01 20:03:34',30200.00,5300.00,0.00,'MP',30200.00,0.00,NULL,31,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(66,'2026-01-01 20:09:57',5000.00,0.00,0.00,'MP',5000.00,0.00,NULL,31,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(67,'2026-01-01 20:51:12',25200.00,9300.00,0.00,'MP',25200.00,0.00,NULL,31,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(68,'2026-01-01 21:05:01',5200.00,800.00,0.00,'EFECTIVO',5200.00,0.00,NULL,33,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(69,'2026-01-01 21:09:59',26500.00,3500.00,0.00,'DEBITO',26500.00,0.00,NULL,35,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(70,'2026-01-01 21:21:12',26600.00,4400.00,0.00,'MP',26600.00,0.00,NULL,37,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(71,'2026-01-01 21:36:02',43137.50,10462.50,0.00,'MP',43137.50,0.00,NULL,37,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(72,'2026-01-01 21:37:45',2000.00,500.00,0.00,'MP',2000.00,0.00,NULL,37,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(73,'2026-01-01 22:28:30',5000.00,0.00,0.00,'MP',5000.00,0.00,NULL,38,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(74,'2026-01-01 22:28:43',3200.00,1600.00,0.00,'MP',3200.00,0.00,NULL,38,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(75,'2026-01-05 20:35:10',3200.00,1600.00,0.00,'EFECTIVO',4000.00,800.00,NULL,41,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(76,'2026-01-05 21:14:15',30400.00,0.00,0.00,'EFECTIVO',30400.00,0.00,NULL,41,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(77,'2026-01-05 21:39:17',18000.00,0.00,0.00,'EFECTIVO',18000.00,0.00,NULL,41,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(78,'2026-01-05 22:51:53',6000.00,0.00,0.00,'EFECTIVO',6000.00,0.00,NULL,41,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(79,'2026-01-06 14:30:37',1600.00,0.00,0.00,'MP',1600.00,0.00,NULL,45,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(80,'2026-01-06 16:49:16',5400.00,1600.00,0.00,'MP',5400.00,0.00,NULL,47,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(81,'2026-01-06 17:07:19',1600.00,0.00,0.00,'EFECTIVO',1600.00,0.00,NULL,49,'ANULADA','2026-01-08 22:32:54',1,NULL,NULL,NULL,NULL),(82,'2026-01-08 21:13:21',28550.00,0.00,0.00,'MP',28550.00,0.00,NULL,50,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(83,'2026-01-10 16:02:42',23350.00,3050.00,0.00,'EFECTIVO',23350.00,0.00,NULL,51,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(84,'2026-01-16 17:17:22',11448.00,1600.00,0.00,'EFECTIVO',11448.00,0.00,NULL,52,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL),(85,'2026-01-16 18:55:01',77500.00,0.00,0.00,'MP',80000.00,2500.00,NULL,52,'EMITIDA',NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `ventas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Final view structure for view `v_movimientos_stock_resumen`
--

/*!50001 DROP VIEW IF EXISTS `v_movimientos_stock_resumen`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_movimientos_stock_resumen` AS select `p`.`id` AS `producto_id`,`p`.`codigo` AS `codigo`,`p`.`nombre` AS `nombre`,count(`ms`.`id`) AS `total_movimientos`,sum(case when `ms`.`tipo` in ('COMPRA','AJUSTE_POSITIVO','ANULACION','DEVOLUCION') then `ms`.`cantidad` else 0 end) AS `total_entradas`,sum(case when `ms`.`tipo` in ('VENTA','AJUSTE_NEGATIVO') then `ms`.`cantidad` else 0 end) AS `total_salidas`,max(`ms`.`fecha`) AS `ultimo_movimiento` from (`productos` `p` left join `movimientos_stock` `ms` on(`ms`.`producto_id` = `p`.`id`)) group by `p`.`id`,`p`.`codigo`,`p`.`nombre` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_usuarios_completo`
--

/*!50001 DROP VIEW IF EXISTS `v_usuarios_completo`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_usuarios_completo` AS select `u`.`id` AS `id`,`u`.`nombre` AS `nombre`,`u`.`email` AS `email`,`u`.`username` AS `username`,`u`.`activo` AS `activo`,`u`.`ultimo_acceso` AS `ultimo_acceso`,`u`.`created_at` AS `created_at`,`u`.`updated_at` AS `updated_at`,`r`.`id` AS `rol_id`,`r`.`nombre` AS `rol_nombre`,case when `u`.`ultimo_acceso` is null then NULL else to_days(current_timestamp()) - to_days(`u`.`ultimo_acceso`) end AS `dias_sin_acceso`,case when `u`.`activo` = 1 then 'Activo' when `u`.`activo` = 0 then 'Inactivo' else 'Eliminado' end AS `estado_texto` from (`users` `u` join `roles` `r` on(`r`.`id` = `u`.`role_id`)) order by `u`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-01-22 19:11:47
