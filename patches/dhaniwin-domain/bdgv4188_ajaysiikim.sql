-- bdgv4188_ajaysiikim database dump for phpMyAdmin
-- Host: localhost
-- Database: bdgv4188_ajaysiikim

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET START TRANSACTION;
SET time_zone = "+05:30";

-- Table structure for table `api_responses`
CREATE TABLE IF NOT EXISTS `api_responses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `endpoint` VARCHAR(190) NOT NULL UNIQUE,
  `method` VARCHAR(20) NOT NULL DEFAULT 'ALL',
  `content` LONGTEXT NOT NULL,
  `content_type` VARCHAR(80) NOT NULL DEFAULT 'application/json',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `api_users`
CREATE TABLE IF NOT EXISTS `api_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL UNIQUE,
  `username` VARCHAR(120) NOT NULL,
  `nickname` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(60) NULL,
  `wallet_balance` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `game_balance` DECIMAL(18,4) NOT NULL DEFAULT 4.45,
  `can_bet` TINYINT(1) NOT NULL DEFAULT 1,
  `password` VARCHAR(255) NULL,
  `token` VARCHAR(255) NULL,
  `token_expire` BIGINT NULL,
  `referrer_id` BIGINT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `raw_json` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `api_settings`
CREATE TABLE IF NOT EXISTS `api_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(160) NOT NULL UNIQUE,
  `setting_value` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `admin_audit`
CREATE TABLE IF NOT EXISTS `admin_audit` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `action` VARCHAR(120) NOT NULL,
  `target` VARCHAR(190) NULL,
  `payload` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `lottery_results`
CREATE TABLE IF NOT EXISTS `lottery_results` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `game_code` VARCHAR(80) NOT NULL,
  `lottery_code` VARCHAR(80) NOT NULL,
  `issue_number` VARCHAR(80) NOT NULL,
  `premium` VARCHAR(255) NOT NULL,
  `number_value` VARCHAR(40) NULL,
  `color` VARCHAR(80) NULL,
  `sum_value` INT NULL,
  `source` VARCHAR(40) NOT NULL DEFAULT 'auto',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_lottery_result` (`game_code`, `issue_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `lottery_bets`
CREATE TABLE IF NOT EXISTS `lottery_bets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_no` VARCHAR(80) NOT NULL UNIQUE,
  `user_id` BIGINT NOT NULL,
  `game_code` VARCHAR(80) NOT NULL,
  `lottery_code` VARCHAR(80) NOT NULL,
  `issue_number` VARCHAR(80) NOT NULL,
  `amount` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `bet_multiple` DECIMAL(18,4) NOT NULL DEFAULT 1,
  `bet_count` INT NOT NULL DEFAULT 1,
  `stake_amount` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `bet_content` LONGTEXT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `result_premium` VARCHAR(255) NULL,
  `win_amount` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `profit_amount` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `settled_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_lottery_bets_game_issue` (`game_code`, `issue_number`),
  INDEX `idx_lottery_bets_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `payment_methods`
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `method_name` VARCHAR(120) NOT NULL,
  `method_type` VARCHAR(40) NOT NULL DEFAULT 'UPI',
  `account_name` VARCHAR(160) NULL,
  `account_value` VARCHAR(220) NULL,
  `qr_text` TEXT NULL,
  `min_amount` DECIMAL(18,4) NOT NULL DEFAULT 100,
  `max_amount` DECIMAL(18,4) NOT NULL DEFAULT 50000,
  `sort_order` INT NOT NULL DEFAULT 0,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `recharge_orders`
CREATE TABLE IF NOT EXISTS `recharge_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_no` VARCHAR(80) NOT NULL UNIQUE,
  `user_id` BIGINT NOT NULL,
  `method_id` INT NULL,
  `method_name` VARCHAR(120) NULL,
  `amount` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `status` VARCHAR(40) NOT NULL DEFAULT 'Pending',
  `utr` VARCHAR(120) NULL,
  `payment_type` VARCHAR(20) NOT NULL DEFAULT 'UPI',
  `screenshot_url` VARCHAR(255) NULL,
  `raw_json` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `withdraw_orders`
CREATE TABLE IF NOT EXISTS `withdraw_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_no` VARCHAR(80) NOT NULL UNIQUE,
  `user_id` BIGINT NOT NULL,
  `withdraw_type` VARCHAR(80) NOT NULL DEFAULT 'UPI',
  `amount` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `status` VARCHAR(40) NOT NULL DEFAULT 'Pending',
  `payment_type` VARCHAR(20) NOT NULL DEFAULT 'UPI',
  `remarks` VARCHAR(255) NULL,
  `account_json` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `wheel_spins`
CREATE TABLE IF NOT EXISTS `wheel_spins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `wheel_type` VARCHAR(40) NOT NULL DEFAULT 'invited',
  `reward_type` INT NOT NULL DEFAULT 1,
  `prize_amount` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `is_win` TINYINT(1) NOT NULL DEFAULT 1,
  `raw_json` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_wheel_spins_user` (`user_id`),
  INDEX `idx_wheel_spins_type` (`wheel_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `site_blocks`
CREATE TABLE IF NOT EXISTS `site_blocks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `block_type` VARCHAR(40) NOT NULL DEFAULT 'ip',
  `block_value` VARCHAR(190) NOT NULL,
  `reason` TEXT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_site_block` (`block_type`, `block_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `site_popups`
CREATE TABLE IF NOT EXISTS `site_popups` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(190) NOT NULL,
  `content` TEXT NULL,
  `image_url` TEXT NULL,
  `jump_type` INT NOT NULL DEFAULT 3,
  `jump_link` TEXT NULL,
  `jump_page` INT NOT NULL DEFAULT 12,
  `frequency` INT NOT NULL DEFAULT 3,
  `sort_order` INT NOT NULL DEFAULT 100,
  `is_force` TINYINT(1) NOT NULL DEFAULT 0,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `admin_staff`
CREATE TABLE IF NOT EXISTS `admin_staff` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `staff_name` VARCHAR(120) NOT NULL,
  `staff_role` VARCHAR(40) NOT NULL DEFAULT 'agent',
  `phone` VARCHAR(80) NULL,
  `share_code` VARCHAR(80) NULL,
  `commission_rate` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `admin_roles`
CREATE TABLE IF NOT EXISTS `admin_roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_name` VARCHAR(50) NOT NULL UNIQUE,
  `role_label` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `admin_permissions`
CREATE TABLE IF NOT EXISTS `admin_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `permission_key` VARCHAR(50) NOT NULL UNIQUE,
  `permission_label` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `role_permissions`
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` INT NOT NULL,
  `permission_id` INT NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `admin_users`
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(120) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role_id` INT NOT NULL,
  `email` VARCHAR(190) NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `admin_login_history`
CREATE TABLE IF NOT EXISTS `admin_login_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` TEXT NULL,
  `status` VARCHAR(20) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `admin_activity_logs`
CREATE TABLE IF NOT EXISTS `admin_activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT NOT NULL,
  `action` VARCHAR(120) NOT NULL,
  `target` VARCHAR(190) NULL,
  `before_state` LONGTEXT NULL,
  `after_state` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `usdt_methods`
CREATE TABLE IF NOT EXISTS `usdt_methods` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `wallet_name` VARCHAR(100) NOT NULL,
  `wallet_address` VARCHAR(255) NOT NULL,
  `network` VARCHAR(50) NOT NULL DEFAULT 'TRC20',
  `qr_text` TEXT NULL,
  `min_amount` DECIMAL(18,4) NOT NULL DEFAULT 10,
  `max_amount` DECIMAL(18,4) NOT NULL DEFAULT 10000,
  `sort_order` INT NOT NULL DEFAULT 0,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `user_control`
CREATE TABLE IF NOT EXISTS `user_control` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL UNIQUE,
  `win_rate_percent` INT NOT NULL DEFAULT 50,
  `total_bets_checked` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `agent_commissions`
CREATE TABLE IF NOT EXISTS `agent_commissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `from_user_id` BIGINT NOT NULL,
  `bet_order_no` VARCHAR(80) NOT NULL,
  `commission_level` INT NOT NULL,
  `bet_amount` DECIMAL(18,4) NOT NULL,
  `commission_amount` DECIMAL(18,4) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `wallet_logs`
CREATE TABLE IF NOT EXISTS `wallet_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(18,4) NOT NULL,
  `balance_before` DECIMAL(18,4) NOT NULL,
  `balance_after` DECIMAL(18,4) NOT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `result_queue`
CREATE TABLE IF NOT EXISTS `result_queue` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `game_code` VARCHAR(80) NOT NULL,
  `issue_number` VARCHAR(80) NOT NULL,
  `premium` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_res_queue` (`game_code`, `issue_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `game_settings`
CREATE TABLE IF NOT EXISTS `game_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `game_code` VARCHAR(80) NOT NULL UNIQUE,
  `house_edge_percent` DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  `kill_switch` TINYINT(1) NOT NULL DEFAULT 0,
  `default_mode` VARCHAR(20) NOT NULL DEFAULT 'auto_hedge',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `gift_codes`
CREATE TABLE IF NOT EXISTS `gift_codes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(80) NOT NULL UNIQUE,
  `prize_amount` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `max_redeem` INT NOT NULL DEFAULT 1,
  `redeemed_count` INT NOT NULL DEFAULT 0,
  `expired_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `support_tickets`
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'open',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `ticket_replies`
CREATE TABLE IF NOT EXISTS `ticket_replies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `sender_type` VARCHAR(20) NOT NULL,
  `sender_id` BIGINT NOT NULL,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- SEED DATA
-- --------------------------------------------------------

-- Seed Default Settings
INSERT IGNORE INTO `api_settings` (`setting_key`, `setting_value`) VALUES
('site_status', 'online'),
('login_enabled', '1'),
('register_enabled', '1'),
('bet_enabled', '1'),
('recharge_enabled', '1'),
('withdraw_enabled', '1'),
('settlement_mode', 'auto'),
('use_snapshot_history', '0'),
('force_local_api', '1'),
('block_enabled', '1'),
('api_url', '/api'),
('draw_url', '/'),
('upi_display_name', 'Dhani Win'),
('upi_id', 'dhaniwin@upi'),
('support_url', '/workOrder'),
('amount_coding', '4.11'),
('first_recharge_bonus_enabled', '1'),
('first_recharge_bonus_percent', '10'),
('first_recharge_bonus_max', '500'),
('invited_wheel_enabled', '1'),
('invited_wheel_spin_count', '2'),
('invited_wheel_prizes', '0.41,0.72,10,27,57,77,87,177,377,500'),
('recharge_wheel_enabled', '1'),
('recharge_wheel_spin_count', '1'),
('recharge_wheel_prizes', '6,16,37,56,77,166,366,666,777,1666'),
('recharge_wheel_reward_up_amount', '29999'),
('agent_rebate_enabled', '1'),
('agent_commission_rate', '0'),
('share_content', 'Invite your friends to join Dhaniwin and unlock bonus #inviteLink#'),
('share_domain', 'https://dhaniwin1.club9.eu.cc'),
('invite_code', 'Q8BRYAN'),
('popup_enabled', '1'),
('home_popup_title', 'free 500'),
('home_popup_image', '/img/6006/other/111109657-38344-file_20260510111109590.webp'),
('default_wallet_balance', '0'),
('default_game_balance', '4.45'),
('wheel_allow_daily_extra_spin', '1');

-- Seed Roles
INSERT IGNORE INTO `admin_roles` (`id`, `role_name`, `role_label`) VALUES
(1, 'super_admin', 'Super Admin'),
(2, 'admin', 'Admin'),
(3, 'finance_manager', 'Finance Manager'),
(4, 'support_manager', 'Support Manager'),
(5, 'game_manager', 'Game Manager'),
(6, 'sub_admin', 'Sub Admin');

-- Seed Permissions
INSERT IGNORE INTO `admin_permissions` (`id`, `permission_key`, `permission_label`) VALUES
(1, 'dashboard', 'Access Dashboard'),
(2, 'user_management', 'Manage Users'),
(3, 'finance', 'Manage Deposits & Withdrawals'),
(4, 'support', 'Support Ticketing'),
(5, 'game_control', 'Control Games & Results'),
(6, 'agent_management', 'Manage Agents & Commissions'),
(7, 'reports', 'Generate Reports'),
(8, 'settings', 'Site Settings & Maintenance'),
(9, 'security', 'IP Block & Security Logs'),
(10, 'user_control', 'Targeted User Win-Rate Control');

-- Link Role Permissions to Super Admin
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8), (1, 9), (1, 10);

-- Seed Default Admin User (Password: admin123)
INSERT INTO `admin_users` (`username`, `password_hash`, `role_id`, `email`, `status`) 
SELECT 'admin', '$2y$10$9z/bxmGxc8I.KoAKY7zbfuskQzgQMoQWikdiVeEdlxtYVwDWvGZ8O', 1, 'admin@dhani.win', 1
FROM dual WHERE NOT EXISTS (SELECT 1 FROM `admin_users` WHERE `username` = 'admin');

-- Seed Default User (Password: admin123)
INSERT IGNORE INTO `api_users` (`user_id`, `username`, `nickname`, `phone`, `wallet_balance`, `game_balance`, `can_bet`, `password`) VALUES
(132257, 'local_member', 'MemberNNGKLPHA', '919119098026', 0.0000, 4.4500, 1, 'admin123');

-- Seed Default Payment Method
INSERT IGNORE INTO `payment_methods` (`id`, `method_name`, `method_type`, `account_name`, `account_value`, `qr_text`, `min_amount`, `max_amount`, `sort_order`, `enabled`) VALUES
(400101, 'PhonePe', 'UPI', 'Dhani Win', 'dhaniwin@upi', 'upi://pay?pa=dhaniwin@upi&pn=Dhani%20Win&cu=INR', 100.0000, 50000.0000, 10, 1);

COMMIT;
