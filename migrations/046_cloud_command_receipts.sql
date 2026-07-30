-- FLUS 4.2.10 - recibos idempotentes para comandos cloud.
-- Cada command_uid se registra una sola vez antes de modificar datos locales.

CREATE TABLE IF NOT EXISTS `cloud_command_receipts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `command_uid` varchar(120) NOT NULL,
  `command_type` varchar(60) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'received',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `result_json` longtext DEFAULT NULL,
  `last_error` varchar(120) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `acked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_cloud_command_receipts_uid` (`command_uid`),
  KEY `idx_cloud_command_receipts_status` (`status`,`updated_at`),
  KEY `idx_cloud_command_receipts_type` (`command_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
