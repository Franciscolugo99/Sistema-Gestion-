CREATE TABLE IF NOT EXISTS `user_sessions` (
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
