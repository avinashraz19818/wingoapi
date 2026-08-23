-- ==============================================================================
-- WinGo Automated Lottery & Betting Engine - MySQL Database Schema
-- Compatible with: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+, phpMyAdmin
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ------------------------------------------------------------------------------
-- 1. Table: wingo_games (Game Configurations & Settings)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wingo_games` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `game_code` VARCHAR(50) NOT NULL UNIQUE,  -- 'WinGo_30S', 'WinGo_1M', 'WinGo_3M', 'WinGo_5M', 'WinGo_10M'
  `name` VARCHAR(100) NOT NULL,
  `interval_seconds` INT NOT NULL,          -- 30, 60, 180, 300, 600
  `lock_seconds` INT NOT NULL DEFAULT 5,    -- Seconds before end when betting is locked
  `external_api_url` VARCHAR(255) NOT NULL, -- External draw source
  `status` TINYINT DEFAULT 1,               -- 1 = Active, 0 = Inactive
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. Table: wingo_results (Historical Draw Results - Source of Truth)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wingo_results` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `game_code` VARCHAR(50) NOT NULL,
  `issue_number` VARCHAR(50) NOT NULL,
  `number` TINYINT NOT NULL,                -- Drawn number (0 to 9)
  `color` VARCHAR(30) NOT NULL,             -- 'green', 'red', 'violet', 'green,violet', 'red,violet'
  `premium` VARCHAR(50) DEFAULT NULL,      -- Lottery ticket premium code
  `sum` INT DEFAULT 0,                      -- Sum of digits
  `draw_time` DATETIME NOT NULL,            -- Draw timestamp
  `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_game_issue` (`game_code`, `issue_number`),
  INDEX `idx_game_time` (`game_code`, `draw_time`),
  INDEX `idx_issue` (`issue_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. Table: wingo_current_issue (Real-time Issue & Countdown Tracking)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wingo_current_issue` (
  `game_code` VARCHAR(50) PRIMARY KEY,
  `current_issue` VARCHAR(50) NOT NULL,
  `current_start` DATETIME NOT NULL,
  `current_end` DATETIME NOT NULL,
  `next_issue` VARCHAR(50) NOT NULL,
  `next_start` DATETIME NOT NULL,
  `next_end` DATETIME NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. Table: wingo_bets (User Bets & Settlement Records)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wingo_bets` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `game_code` VARCHAR(50) NOT NULL,
  `issue_number` VARCHAR(50) NOT NULL,
  `bet_type` VARCHAR(20) NOT NULL,          -- 'number', 'color', 'big_small', 'odd_even'
  `bet_value` VARCHAR(20) NOT NULL,         -- '0'-'9', 'green', 'red', 'violet', 'big', 'small', 'odd', 'even'
  `amount` DECIMAL(12,2) NOT NULL,
  `odds` DECIMAL(6,2) NOT NULL,
  `status` ENUM('pending','won','lost','cancelled') DEFAULT 'pending',
  `payout` DECIMAL(12,2) DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `settled_at` DATETIME NULL,
  INDEX `idx_user_game_issue` (`user_id`, `game_code`, `issue_number`),
  INDEX `idx_issue_status` (`issue_number`, `status`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. Table: shonu_kaichila (User Balance / Wallet Table - in999 standard)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shonu_kaichila` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `balakedara` BIGINT UNIQUE NOT NULL,      -- User ID
  `motta` DECIMAL(14,2) NOT NULL DEFAULT 10000.00, -- Available Balance
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_balakedara` (`balakedara`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. Table: wingo_sync_logs (Diagnostic and Audit Log for Cron sync)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wingo_sync_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `game_code` VARCHAR(50) NOT NULL,
  `status` VARCHAR(20) NOT NULL,            -- 'SUCCESS', 'FAILED', 'FALLBACK'
  `records_fetched` INT DEFAULT 0,
  `records_inserted` INT DEFAULT 0,
  `message` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Seed Default Game Configs
-- ------------------------------------------------------------------------------
INSERT INTO `wingo_games` (`game_code`, `name`, `interval_seconds`, `lock_seconds`, `external_api_url`) VALUES
('WinGo_30S', 'WinGo 30 Seconds', 30, 5, 'https://draw.ar-lottery01.com/WinGo/WinGo_30S/GetHistoryIssuePage.json'),
('WinGo_1M', 'WinGo 1 Minute', 60, 5, 'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json'),
('WinGo_3M', 'WinGo 3 Minutes', 180, 10, 'https://draw.ar-lottery01.com/WinGo/WinGo_3M/GetHistoryIssuePage.json'),
('WinGo_5M', 'WinGo 5 Minutes', 300, 15, 'https://draw.ar-lottery01.com/WinGo/WinGo_5M/GetHistoryIssuePage.json'),
('WinGo_10M', 'WinGo 10 Minutes', 600, 30, 'https://draw.ar-lottery01.com/WinGo/WinGo_10M/GetHistoryIssuePage.json')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `interval_seconds`=VALUES(`interval_seconds`), `external_api_url`=VALUES(`external_api_url`);

-- ------------------------------------------------------------------------------
-- Seed Default Demo Player (User ID 1001 with 10,000 credits)
-- ------------------------------------------------------------------------------
INSERT INTO `shonu_kaichila` (`balakedara`, `motta`) VALUES
(1001, 10000.00)
ON DUPLICATE KEY UPDATE `motta` = `motta`;

SET FOREIGN_KEY_CHECKS = 1;
