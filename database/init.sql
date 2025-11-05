-- HackSim CTF Platform Database Schema
-- MySQL 8.0+ Compatible

-- Enable UTF8MB4 for full Unicode support
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Users table - Core user data from Discord OAuth2
CREATE TABLE `users` (
  `id` CHAR(36) NOT NULL,
  `discord_id` VARCHAR(50) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `avatar_url` TEXT NULL,
  `xp_total` INT NOT NULL DEFAULT 0,
  `level` INT NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_active` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_discord_id` (`discord_id`),
  UNIQUE KEY `uk_users_email` (`email`),
  INDEX `ix_users_xp_total` (`xp_total` DESC),
  INDEX `ix_users_level` (`level` DESC),
  INDEX `ix_users_last_active` (`last_active` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Challenges table - Challenge templates
CREATE TABLE `challenges` (
  `id` CHAR(36) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `category` ENUM('web', 'crypto', 'binary', 'forensics', 'osint', 'network', 'custom') NOT NULL,
  `difficulty` ENUM('beginner', 'easy', 'medium', 'hard', 'expert', 'legendary') NOT NULL,
  `base_score` INT NOT NULL,
  `template_data` JSON NOT NULL,
  `generator_script` TEXT NULL,
  `validator_script` TEXT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_by` CHAR(36) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_challenges_category` (`category`),
  KEY `ix_challenges_difficulty` (`difficulty`),
  KEY `ix_challenges_is_active` (`is_active`),
  KEY `ix_challenges_created_by` (`created_by`),
  CONSTRAINT `fk_challenges_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Challenge instances table - Generated challenge instances for users
CREATE TABLE `challenge_instances` (
  `id` CHAR(36) NOT NULL,
  `challenge_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `instance_data` JSON NOT NULL,
  `solution_hash` VARCHAR(255) NOT NULL,
  `score` INT NOT NULL,
  `status` ENUM('available', 'in_progress', 'completed', 'expired') NOT NULL DEFAULT 'available',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_challenge_instances_user_challenge` (`user_id`, `challenge_id`),
  KEY `ix_challenge_instances_challenge_id` (`challenge_id`),
  KEY `ix_challenge_instances_user_id` (`user_id`),
  KEY `ix_challenge_instances_status` (`status`),
  KEY `ix_challenge_instances_created_at` (`created_at`),
  CONSTRAINT `fk_challenge_instances_challenge_id` FOREIGN KEY (`challenge_id`) REFERENCES `challenges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_challenge_instances_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Submissions table - User submission attempts
CREATE TABLE `submissions` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `challenge_instance_id` CHAR(36) NOT NULL,
  `submission_data` JSON NOT NULL,
  `result` ENUM('correct', 'incorrect', 'timeout', 'error') NOT NULL,
  `score_earned` INT NOT NULL DEFAULT 0,
  `execution_log` TEXT NULL,
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_submissions_user_id` (`user_id`),
  KEY `ix_submissions_challenge_instance_id` (`challenge_instance_id`),
  KEY `ix_submissions_result` (`result`),
  KEY `ix_submissions_submitted_at` (`submitted_at`),
  CONSTRAINT `fk_submissions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submissions_challenge_instance_id` FOREIGN KEY (`challenge_instance_id`) REFERENCES `challenge_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Achievements table - Achievement definitions
CREATE TABLE `achievements` (
  `id` CHAR(36) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NOT NULL,
  `badge_icon` VARCHAR(100) NOT NULL,
  `criteria` JSON NOT NULL,
  `xp_reward` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_achievements_name` (`name`),
  KEY `ix_achievements_badge_icon` (`badge_icon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User achievements table - User earned achievements
CREATE TABLE `user_achievements` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `achievement_id` CHAR(36) NOT NULL,
  `earned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_achievements_user_achievement` (`user_id`, `achievement_id`),
  KEY `ix_user_achievements_user_id` (`user_id`),
  KEY `ix_user_achievements_achievement_id` (`achievement_id`),
  CONSTRAINT `fk_user_achievements_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_achievements_achievement_id` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Storylines table - Story-driven missions
CREATE TABLE `storylines` (
  `id` CHAR(36) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `chapter_order` INT NOT NULL,
  `required_level` INT NOT NULL DEFAULT 1,
  `challenge_sequence` JSON NULL,
  `rewards` JSON NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_storylines_chapter_order` (`chapter_order`),
  KEY `ix_storylines_required_level` (`required_level`),
  KEY `ix_storylines_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User storyline progress table
CREATE TABLE `user_storyline_progress` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `storyline_id` CHAR(36) NOT NULL,
  `current_chapter` INT NOT NULL DEFAULT 1,
  `is_completed` BOOLEAN NOT NULL DEFAULT FALSE,
  `completed_challenges` JSON NULL,
  `earned_rewards` JSON NULL,
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_storyline_progress_user_storyline` (`user_id`, `storyline_id`),
  KEY `ix_user_storyline_progress_user_id` (`user_id`),
  KEY `ix_user_storyline_progress_storyline_id` (`storyline_id`),
  CONSTRAINT `fk_user_storyline_progress_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_storyline_progress_storyline_id` FOREIGN KEY (`storyline_id`) REFERENCES `storylines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Donations table - Track user donations
CREATE TABLE `donations` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
  `payment_method` VARCHAR(50) NOT NULL,
  `transaction_id` VARCHAR(255) NULL,
  `status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  `message` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_donations_user_id` (`user_id`),
  KEY `ix_donations_status` (`status`),
  KEY `ix_donations_created_at` (`created_at`),
  CONSTRAINT `fk_donations_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin logs table - Track administrative actions
CREATE TABLE `admin_logs` (
  `id` CHAR(36) NOT NULL,
  `admin_id` CHAR(36) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `resource_type` VARCHAR(50) NOT NULL,
  `resource_id` CHAR(36) NULL,
  `details` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_admin_logs_admin_id` (`admin_id`),
  KEY `ix_admin_logs_action` (`action`),
  KEY `ix_admin_logs_resource_type` (`resource_type`),
  KEY `ix_admin_logs_created_at` (`created_at`),
  CONSTRAINT `fk_admin_logs_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System metrics table - Track platform analytics
CREATE TABLE `system_metrics` (
  `id` CHAR(36) NOT NULL,
  `metric_name` VARCHAR(100) NOT NULL,
  `metric_value` DECIMAL(15,4) NOT NULL,
  `metric_unit` VARCHAR(20) NULL,
  `additional_data` JSON NULL,
  `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_system_metrics_metric_name` (`metric_name`),
  KEY `ix_system_metrics_recorded_at` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session table for JWT token management
CREATE TABLE `user_sessions` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_user_sessions_user_id` (`user_id`),
  KEY `ix_user_sessions_token_hash` (`token_hash`),
  KEY `ix_user_sessions_expires_at` (`expires_at`),
  CONSTRAINT `fk_user_sessions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Insert default achievements
INSERT INTO `achievements` (`id`, `name`, `description`, `badge_icon`, `criteria`, `xp_reward`) VALUES
('550e8400-e29b-41d4-a716-446655440001', 'First Steps', 'Complete your first challenge', 'badge-first-steps', '{"type": "first_challenge"}', 50),
('550e8400-e29b-41d4-a716-446655440002', 'Rising Star', 'Reach level 5', 'badge-rising-star', '{"type": "level", "value": 5}', 100),
('550e8400-e29b-41d4-a716-446655440003', 'Challenge Master', 'Complete 10 challenges', 'badge-challenge-master', '{"type": "challenges_completed", "value": 10}', 200),
('550e8400-e29b-41d4-a716-446655440004', 'Speed Demon', 'Complete a challenge in under 5 minutes', 'badge-speed-demon', '{"type": "speed_challenge", "time_seconds": 300}', 75),
('550e8400-e29b-41d4-a716-446655440005', 'Perfectionist', 'Complete 5 challenges without any incorrect submissions', 'badge-perfectionist', '{"type": "perfect_streak", "value": 5}', 150);

-- Insert default storylines
INSERT INTO `storylines` (`id`, `title`, `description`, `chapter_order`, `required_level`, `challenge_sequence`, `rewards`, `is_active`) VALUES
('550e8400-e29b-41d4-a716-446655440011', 'The Beginning', 'Start your journey into cybersecurity', 1, 1, '["web_beginner", "crypto_beginner"]', '{"xp": 100, "badges": ["badge-first-steps"]}', TRUE),
('550e8400-e29b-41d4-a716-446655440012', 'Web Warrior', 'Master the art of web exploitation', 2, 3, '["web_easy", "web_medium", "web_hard"]', '{"xp": 300, "badges": ["badge-web-warrior"]}', TRUE),
('550e8400-e29b-41d4-a716-446655440013', 'Crypto King', 'Become a cryptography expert', 3, 5, '["crypto_easy", "crypto_medium", "crypto_hard", "crypto_expert"]', '{"xp": 500, "badges": ["badge-crypto-king"]}', TRUE);