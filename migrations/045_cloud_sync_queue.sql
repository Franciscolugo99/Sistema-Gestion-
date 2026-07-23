-- FLUS 4.2.4 - cola local para sincronizacion cloud offline-first.
-- Guarda eventos operativos locales y permite reintentos idempotentes contra FLUS Web.

CREATE TABLE IF NOT EXISTS `cloud_sync_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_uid` varchar(120) NOT NULL,
  `event_type` varchar(60) NOT NULL,
  `occurred_at` datetime NOT NULL,
  `summary_json` longtext DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `status` enum('pending','failed','sent') NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(190) DEFAULT NULL,
  `next_attempt_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_cloud_sync_queue_event_uid` (`event_uid`),
  KEY `idx_cloud_sync_queue_status_next` (`status`,`next_attempt_at`),
  KEY `idx_cloud_sync_queue_occurred_at` (`occurred_at`),
  KEY `idx_cloud_sync_queue_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
