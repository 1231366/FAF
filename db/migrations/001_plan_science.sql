-- Phase 1: scientific training engine support.
-- Adds periodization/zone metadata to training_plans, and the neural_trend
-- column that checkin_engine.php has been reading/writing without it existing
-- (silently breaking the adaptive replan loop on every completed check-in).

ALTER TABLE `training_plans`
  ADD COLUMN `phase` ENUM('BASE','BUILD','PEAK','TAPER') DEFAULT NULL AFTER `display_order`,
  ADD COLUMN `intensity_zone` TINYINT DEFAULT NULL COMMENT 'Z1-Z5 physiological zone' AFTER `phase`,
  ADD COLUMN `is_deload` TINYINT(1) DEFAULT 0 AFTER `intensity_zone`,
  ADD COLUMN `workout_category` ENUM('EASY','INTERVAL','TEMPO','LONG','STRENGTH','REST') DEFAULT NULL AFTER `is_deload`;

ALTER TABLE `user_profiles`
  ADD COLUMN `neural_trend` INT NOT NULL DEFAULT 0 COMMENT 'Rolling effort trend used to trigger adaptive replanning' AFTER `consistency_score`;
