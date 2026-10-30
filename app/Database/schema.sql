-- Freezer / Store Profile Management System
-- Database Schema for MySQL 8.0+

CREATE DATABASE IF NOT EXISTS `freezer_management` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `freezer_management`;

-- 1. Users Table (Role-Based Access Control)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('super_admin') NOT NULL DEFAULT 'super_admin',
    `phone` VARCHAR(30) NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `last_login` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Freezer Types Master Table
CREATE TABLE IF NOT EXISTS `freezer_types` (
    `code` VARCHAR(10) PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Brands / Manufacturers Master Table
CREATE TABLE IF NOT EXISTS `brands` (
    `code` VARCHAR(10) PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Stores / Outlets Table
CREATE TABLE IF NOT EXISTS `stores` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `outlet_id` VARCHAR(50) NOT NULL UNIQUE,
    `store_name` VARCHAR(150) NOT NULL,
    `customer_name` VARCHAR(150) NULL,
    `distributor_name` VARCHAR(150) NULL,
    `contact_person` VARCHAR(100) NULL,
    `phone_number` VARCHAR(50) NULL,
    `email` VARCHAR(150) NULL,
    `aadhar_number` VARCHAR(12) NULL,
    `fssai_number` VARCHAR(20) NULL,
    `gst_number` VARCHAR(20) NULL,
    `address` TEXT NOT NULL,
    `city` VARCHAR(100) NOT NULL,
    `state` VARCHAR(100) NOT NULL,
    `pincode` VARCHAR(10) NOT NULL,
    `zone` VARCHAR(50) DEFAULT 'WEST',
    `location_type` VARCHAR(50) DEFAULT 'Commercial Store',
    `latitude` DECIMAL(10, 8) NULL,
    `longitude` DECIMAL(11, 8) NULL,
    `gps_accuracy` DECIMAL(8, 2) NULL,
    `gps_captured_at` DATETIME NULL,
    `image_store_front` VARCHAR(255) NULL,
    `image_aadhar_card` VARCHAR(255) NULL COMMENT 'Aadhaar front',
    `image_aadhar_back` VARCHAR(255) NULL COMMENT 'Aadhaar back',
    `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
    `created_by` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_stores_city` (`city`),
    INDEX `idx_stores_state` (`state`),
    INDEX `idx_stores_pincode` (`pincode`),
    INDEX `idx_stores_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Freezers Table (with Unique ID and Uploaded Images)
CREATE TABLE IF NOT EXISTS `freezers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `unique_freezer_id` VARCHAR(100) NOT NULL UNIQUE,
    `store_id` INT NOT NULL,
    `freezer_type` VARCHAR(10) NOT NULL,
    `company_code` VARCHAR(10) NOT NULL,
    `model_number` VARCHAR(50) NOT NULL,
    `capacity_litres` INT NULL,
    `freezer_condition` VARCHAR(50) DEFAULT 'WORKING CONDITION',
    `location_code` VARCHAR(20) NOT NULL,
    `pincode_last3` VARCHAR(6) NOT NULL,
    `install_month_year` VARCHAR(10) NOT NULL,
    `unit_sequence` VARCHAR(20) NOT NULL,
    `installation_date` DATE NOT NULL,
    `image_left_side` VARCHAR(255) NULL,
    `image_right_side` VARCHAR(255) NULL,
    `image_iot_device` VARCHAR(255) NULL,
    `qr_code_path` VARCHAR(255) NULL,
    `qr_code_data` TEXT NULL,
    `status` ENUM('Active', 'Maintenance', 'Decommissioned', 'Under Inspection') DEFAULT 'Active',
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_freezers_store` (`store_id`),
    INDEX `idx_freezers_type` (`freezer_type`),
    INDEX `idx_freezers_company` (`company_code`),
    INDEX `idx_freezers_status` (`status`),
    INDEX `idx_freezers_install` (`installation_date`),
    CONSTRAINT `fk_freezers_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_freezers_type` FOREIGN KEY (`freezer_type`) REFERENCES `freezer_types` (`code`) ON UPDATE CASCADE,
    CONSTRAINT `fk_freezers_brand` FOREIGN KEY (`company_code`) REFERENCES `brands` (`code`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. IoT Devices Table
CREATE TABLE IF NOT EXISTS `iot_devices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `freezer_id` INT NOT NULL UNIQUE,
    `device_serial` VARCHAR(100) NOT NULL UNIQUE,
    `imei_or_mac` VARCHAR(100) NULL,
    `sim_number` VARCHAR(30) NULL,
    `sim_company` VARCHAR(50) NULL,
    `device_type` VARCHAR(50) DEFAULT 'Temperature & GPS',
    `device_owner` ENUM('ReeferON Own Device', 'Outsourced Device') DEFAULT 'ReeferON Own Device',
    `oem_model` VARCHAR(100) DEFAULT 'RF-T100',
    `battery_percentage` INT DEFAULT 98,
    `power_status` ENUM('ON', 'OFF') DEFAULT 'ON',
    `last_ping_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_iot_freezer` FOREIGN KEY (`freezer_id`) REFERENCES `freezers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. IoT Telemetry Table (Future-ready temperature logs)
CREATE TABLE IF NOT EXISTS `iot_telemetry` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `freezer_id` INT NOT NULL,
    `temperature` DECIMAL(5, 2) NOT NULL,
    `target_min_temp` DECIMAL(5, 2) DEFAULT -24.00,
    `target_max_temp` DECIMAL(5, 2) DEFAULT -18.00,
    `is_alert` TINYINT(1) DEFAULT 0,
    `compressor_running` TINYINT(1) DEFAULT 1,
    `door_open` TINYINT(1) DEFAULT 0,
    `power_status` ENUM('ON', 'OFF') DEFAULT 'ON',
    `battery_level` INT DEFAULT 98,
    `recorded_at` DATETIME NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_telemetry_freezer_time` (`freezer_id`, `recorded_at`),
    CONSTRAINT `fk_telemetry_freezer` FOREIGN KEY (`freezer_id`) REFERENCES `freezers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Freezer placement history (asset can move between stores; Unique ID stays fixed)
CREATE TABLE IF NOT EXISTS `freezer_placements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `freezer_id` INT NOT NULL,
    `store_id` INT NOT NULL,
    `started_at` DATE NOT NULL,
    `ended_at` DATE NULL,
    `transfer_reason` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_placement_freezer` (`freezer_id`),
    INDEX `idx_placement_store` (`store_id`),
    INDEX `idx_placement_open` (`freezer_id`, `ended_at`),
    CONSTRAINT `fk_placement_freezer` FOREIGN KEY (`freezer_id`) REFERENCES `freezers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_placement_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Bi-annual KYC (H1 Jan-Jun / H2 Jul-Dec) at current store
CREATE TABLE IF NOT EXISTS `kyc_verifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `freezer_id` INT NOT NULL,
    `store_id` INT NOT NULL,
    `cycle_year` SMALLINT NOT NULL,
    `cycle_half` TINYINT NOT NULL COMMENT '1=Jan-Jun, 2=Jul-Dec',
    `verified_at` DATE NOT NULL,
    `result` ENUM('PASS','FAIL','PENDING') DEFAULT 'PASS',
    `notes` TEXT NULL,
    `image_store_front` VARCHAR(255) NULL,
    `image_freezer` VARCHAR(255) NULL,
    `latitude` DECIMAL(10, 8) NULL,
    `longitude` DECIMAL(11, 8) NULL,
    `verified_by` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_kyc_cycle` (`freezer_id`, `cycle_year`, `cycle_half`),
    INDEX `idx_kyc_store` (`store_id`),
    INDEX `idx_kyc_verified` (`verified_at`),
    CONSTRAINT `fk_kyc_freezer` FOREIGN KEY (`freezer_id`) REFERENCES `freezers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_kyc_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Audit Logs Table
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `user_name` VARCHAR(100) NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT NULL,
    `freezer_id_code` VARCHAR(100) NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(50) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Seed Data: Freezer Types
INSERT INTO `freezer_types` (`code`, `name`, `description`) VALUES
('DF', 'Deep Freezer', 'Standard horizontal or multi-door deep freezer unit'),
('DCF', 'Deep Chest Freezer', 'Chest type deep freezer with top opening door'),
('UDF', 'Upright Deep Freezer', 'Vertical standing freezer with front opening doors'),
('FRZ', 'General Freezer', 'General multi-purpose commercial freezing unit')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- Initial Seed Data: Brands
INSERT INTO `brands` (`code`, `name`, `description`) VALUES
('RW', 'Rockwell', 'Rockwell Industries commercial refrigeration equipment'),
('VL', 'Voltas', 'Voltas commercial chest & upright cooling systems'),
('BS', 'Blue Star', 'Blue Star commercial deep freezers & coolers'),
('HV', 'Havells', 'Havells industrial and retail cooling units'),
('LG', 'LG Electronics', 'LG commercial refrigeration appliances'),
('OT', 'Other / OEM', 'Other or custom commercial cooling manufacturer')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- Initial Seed Data: Default Users (Pass: Admin@123 for all seeded demo users)
-- bcrypt hash for 'Admin@123': $2y$10$wN1g1k3RZZk9i2bQ3jY32.g1U7T9a.oGg9q6rV7K6lO6HlI4P1l7y
INSERT INTO `users` (`name`, `email`, `password`, `role`, `phone`, `status`) VALUES
('Harshit SuperAdmin', 'admin@reeferon.com', '$2y$10$fB8Qd9P93tJ32K97n/aO3e0Q5Fk2G8M5Yl8R1bE1aJ7eC9fG7u8O.', 'super_admin', '9876543210', 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);
