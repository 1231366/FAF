-- Baseline: reconstructed dump of the schema as it lives in faf_running today.
-- Only ever executed against a fresh database — migrate.php marks this as
-- already-applied without running it when the `users` table already exists.

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `diagnostic_completed` tinyint(1) DEFAULT 0,
  `google_id` varchar(255) DEFAULT NULL,
  `profile_pic` text DEFAULT NULL,
  `circle_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_user_circle` (`circle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `circles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `leader_id` int(11) NOT NULL,
  `streak_count` int(11) DEFAULT 0 COMMENT 'O Foguinho do Clã',
  `last_streak_update` date DEFAULT NULL,
  `country_code` varchar(3) DEFAULT 'PT',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `leader_id` (`leader_id`),
  CONSTRAINT `circles_ibfk_1` FOREIGN KEY (`leader_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `users` ADD CONSTRAINT `fk_user_circle` FOREIGN KEY (`circle_id`) REFERENCES `circles` (`id`);

CREATE TABLE IF NOT EXISTS `user_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `weight` int(11) DEFAULT NULL,
  `height` int(11) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `fitness_level` enum('Zero','Regular','Pro') DEFAULT 'Zero',
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `training_volume` varchar(50) DEFAULT NULL,
  `target_distance` int(11) DEFAULT NULL,
  `race_date` date DEFAULT NULL,
  `target_pace` varchar(10) DEFAULT NULL,
  `ref_dist` float DEFAULT 5,
  `ref_pace` varchar(10) DEFAULT NULL,
  `available_days` varchar(50) DEFAULT NULL,
  `prep_cycle` int(11) DEFAULT 12,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `consistency_score` decimal(5,2) DEFAULT 0.00 COMMENT '% de plano cumprido',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `user_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `training_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `day_name` varchar(20) DEFAULT NULL,
  `workout_date` date DEFAULT NULL,
  `week_number` int(11) DEFAULT NULL,
  `workout_type` varchar(50) DEFAULT NULL,
  `distance` float DEFAULT NULL,
  `pace` varchar(10) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_completed` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','completed','skipped','rescheduled') DEFAULT 'pending',
  `real_distance` decimal(5,2) DEFAULT NULL,
  `real_pace` varchar(10) DEFAULT NULL,
  `effort_level` enum('easy','perfect','hard') DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `training_plans_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `friendships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `friend_id` int(11) DEFAULT NULL,
  `status` enum('pending','accepted') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_friendship` (`user_id`,`friend_id`),
  KEY `friend_id` (`friend_id`),
  CONSTRAINT `friendships_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `friendships_ibfk_2` FOREIGN KEY (`friend_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `circle_feed` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `circle_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `type` enum('system','user_action','alert') DEFAULT 'user_action',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `circle_id` (`circle_id`),
  CONSTRAINT `circle_feed_ibfk_1` FOREIGN KEY (`circle_id`) REFERENCES `circles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
