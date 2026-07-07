-- Phase 5: race database for onboarding lookup (search by name, auto-fill
-- date + distance instead of manual entry).

CREATE TABLE IF NOT EXISTS `races` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `race_date` date NOT NULL,
  `distance_km` decimal(6,2) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(60) DEFAULT NULL,
  `source_url` varchar(255) DEFAULT NULL,
  `scraped_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_race` (`name`, `race_date`),
  KEY `idx_name` (`name`),
  KEY `idx_date` (`race_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `user_profiles`
  ADD COLUMN `race_name` VARCHAR(150) DEFAULT NULL COMMENT 'Nome da prova escolhida no onboarding, se houver' AFTER `race_date`;
