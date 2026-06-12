-- FLUS 4.1.1 - estado idempotente y trazabilidad webhook de Mercado Pago.

CREATE TABLE IF NOT EXISTS `mercadopago_integraciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `environment` varchar(20) NOT NULL,
  `token_fingerprint` char(64) NOT NULL,
  `user_id` varchar(40) NOT NULL,
  `store_id` varchar(80) DEFAULT NULL,
  `store_external_id` varchar(60) NOT NULL,
  `store_name` varchar(80) NOT NULL,
  `pos_id` varchar(80) DEFAULT NULL,
  `pos_external_id` varchar(40) NOT NULL,
  `pos_name` varchar(80) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending_store',
  `last_error` varchar(1000) DEFAULT NULL,
  `setup_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mp_integracion_environment` (`environment`),
  KEY `idx_mp_integracion_store_external` (`store_external_id`),
  KEY `idx_mp_integracion_pos_external` (`pos_external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `mercadopago_webhook_eventos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` char(64) NOT NULL,
  `environment` varchar(20) NOT NULL,
  `event_id` varchar(100) DEFAULT NULL,
  `event_type` varchar(50) DEFAULT NULL,
  `action_name` varchar(100) DEFAULT NULL,
  `resource_id` varchar(100) DEFAULT NULL,
  `request_id` varchar(100) DEFAULT NULL,
  `signature_valid` tinyint(1) NOT NULL DEFAULT 0,
  `live_mode` tinyint(1) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'received',
  `order_status` varchar(40) DEFAULT NULL,
  `external_reference` varchar(120) DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mp_webhook_event_key` (`event_key`),
  KEY `idx_mp_webhook_resource` (`resource_id`),
  KEY `idx_mp_webhook_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
