-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 14, 2026 at 11:36 AM
-- Server version: 8.4.7
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `freezer_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `app_notifications`
--

DROP TABLE IF EXISTS `app_notifications`;
CREATE TABLE IF NOT EXISTS `app_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `freezer_id` int NOT NULL,
  `unique_freezer_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_by_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_app_notif_status` (`status`),
  KEY `idx_app_notif_type` (`type`),
  KEY `idx_app_notif_freezer` (`freezer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asset_tags`
--

DROP TABLE IF EXISTS `asset_tags`;
CREATE TABLE IF NOT EXISTS `asset_tags` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tag_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('unused','assigned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unused',
  `freezer_id` int DEFAULT NULL,
  `qr_code_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qr_code_data` text COLLATE utf8mb4_unicode_ci,
  `batch_label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tag_code` (`tag_code`),
  KEY `idx_asset_tags_status` (`status`),
  KEY `idx_asset_tags_freezer` (`freezer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `asset_tags`
--

INSERT INTO `asset_tags` (`id`, `tag_code`, `status`, `freezer_id`, `qr_code_path`, `qr_code_data`, `batch_label`, `created_by`, `assigned_at`, `created_at`, `updated_at`) VALUES
(1, 'RO-SO-JGP2H3', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-JGP2H3.svg', 'http://localhost/DF application/tag.php?t=RO-SO-JGP2H3', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:21', '2026-11-12 13:33:21'),
(2, 'RO-SO-SF9PP6', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-SF9PP6.svg', 'http://localhost/DF application/tag.php?t=RO-SO-SF9PP6', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:21', '2026-11-12 13:33:21'),
(3, 'RO-SO-ATH826', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-ATH826.svg', 'http://localhost/DF application/tag.php?t=RO-SO-ATH826', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:21', '2026-11-12 13:33:21'),
(4, 'RO-SO-AFPY9H', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-AFPY9H.svg', 'http://localhost/DF application/tag.php?t=RO-SO-AFPY9H', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:21', '2026-11-12 13:33:21'),
(5, 'RO-SO-MEP8ZV', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-MEP8ZV.svg', 'http://localhost/DF application/tag.php?t=RO-SO-MEP8ZV', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:21', '2026-11-12 13:33:21'),
(6, 'RO-SO-KY6KJQ', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-KY6KJQ.svg', 'http://localhost/DF application/tag.php?t=RO-SO-KY6KJQ', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:22', '2026-11-12 13:33:22'),
(7, 'RO-SO-BMW93B', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-BMW93B.svg', 'http://localhost/DF application/tag.php?t=RO-SO-BMW93B', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:22', '2026-11-12 13:33:22'),
(8, 'RO-SO-N2S2ZS', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-N2S2ZS.svg', 'http://localhost/DF application/tag.php?t=RO-SO-N2S2ZS', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:22', '2026-11-12 13:33:22'),
(9, 'RO-SO-5BCRK4', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-5BCRK4.svg', 'http://localhost/DF application/tag.php?t=RO-SO-5BCRK4', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:22', '2026-11-12 13:33:22'),
(10, 'RO-SO-PPCPYR', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-SO-PPCPYR.svg', 'http://localhost/DF application/tag.php?t=RO-SO-PPCPYR', 'SOUTH-Bhopal-20261112-080321', 1, NULL, '2026-11-12 13:33:22', '2026-11-12 13:33:22'),
(11, 'RO-WE-HKEEW5', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-HKEEW5.svg', 'http://localhost/DF application/tag.php?t=RO-WE-HKEEW5', 'WEST-20261112-080449', 1, NULL, '2026-11-12 13:34:50', '2026-11-12 13:34:50'),
(12, 'RO-WE-GXS4ZN', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-GXS4ZN.svg', 'http://localhost/DF application/tag.php?t=RO-WE-GXS4ZN', 'WEST-20261112-080449', 1, NULL, '2026-11-12 13:34:50', '2026-11-12 13:34:50'),
(13, 'RO-WE-VPTMZJ', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-VPTMZJ.svg', 'http://localhost/DF application/tag.php?t=RO-WE-VPTMZJ', 'WEST-20261112-080449', 1, NULL, '2026-11-12 13:34:50', '2026-11-12 13:34:50'),
(14, 'RO-WE-MKYTCR', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-MKYTCR.svg', 'http://localhost/DF application/tag.php?t=RO-WE-MKYTCR', 'WEST-20261112-080449', 1, NULL, '2026-11-12 13:34:50', '2026-11-12 13:34:50'),
(15, 'RO-WE-XUZ8LH', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-XUZ8LH.svg', 'http://localhost/DF application/tag.php?t=RO-WE-XUZ8LH', 'WEST-20261112-080449', 1, NULL, '2026-11-12 13:34:50', '2026-11-12 13:34:50'),
(16, 'RO-WE-FPACFW', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-FPACFW.svg', 'http://localhost/DF application/tag.php?t=RO-WE-FPACFW', 'WEST-20261112-080449', 1, NULL, '2026-11-12 13:34:50', '2026-11-12 13:34:50'),
(17, 'RO-WE-MPB2CD', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-MPB2CD.svg', 'http://localhost/DF application/tag.php?t=RO-WE-MPB2CD', 'WEST-20261112-080449', 1, NULL, '2026-11-12 13:34:50', '2026-11-12 13:34:50'),
(18, 'RO-WE-BPXAYV', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-BPXAYV.svg', 'http://localhost/DF application/tag.php?t=RO-WE-BPXAYV', 'WEST-20261112-080449', 1, NULL, '2026-11-12 13:34:50', '2026-11-12 13:34:50'),
(19, 'RO-WE-VH6789', 'unused', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-VH6789.svg', 'http://localhost/DF application/tag.php?t=RO-WE-VH6789', 'WEST-20261112-080449', 1, NULL, '2026-11-12 13:34:50', '2026-11-12 13:34:50'),
(20, 'RO-WE-L48WE4', 'assigned', 1, 'uploads/qrcodes/qr_TAG-RO-WE-L48WE4.svg', 'http://localhost/DF application/tag.php?t=RO-WE-L48WE4', 'WEST-20261112-080449', 1, '2026-11-12 16:02:57', '2026-11-12 13:34:51', '2026-11-12 16:02:57'),
(21, 'RO-WE-6QL2G8', 'assigned', 2, 'uploads/qrcodes/qr_TAG-RO-WE-6QL2G8.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-WE-6QL2G8', 'DUMMY-WEST-20261112-190554-1', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '2026-11-13 00:35:55'),
(22, 'RO-SO-EA5ND2', 'assigned', 3, 'uploads/qrcodes/qr_TAG-RO-SO-EA5ND2.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-SO-EA5ND2', 'DUMMY-SOUTH-20261112-190555-2', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '2026-11-13 00:35:55'),
(23, 'RO-NO-BXS5XS', 'assigned', 4, 'uploads/qrcodes/qr_TAG-RO-NO-BXS5XS.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-NO-BXS5XS', 'DUMMY-NORTH-20261112-190555-3', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '2026-11-13 00:35:55'),
(24, 'RO-EA-WFF939', 'assigned', 5, 'uploads/qrcodes/qr_TAG-RO-EA-WFF939.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-EA-WFF939', 'DUMMY-EAST-20261112-190555-4', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '2026-11-13 00:35:55'),
(25, 'RO-WE-VY5QHF', 'assigned', 6, 'uploads/qrcodes/qr_TAG-RO-WE-VY5QHF.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-WE-VY5QHF', 'DUMMY-WEST-20261112-190555-5', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '2026-11-13 00:35:55');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `user_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` int DEFAULT NULL,
  `freezer_id_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `user_name`, `action`, `entity_type`, `entity_id`, `freezer_id_code`, `details`, `ip_address`, `created_at`) VALUES
(1, 10, 'Aman Singh', 'CREATE_PROFILE', 'FREEZER', 1, 'DF-RW-SH00293-THA1-1126-462010-76XZK6', 'Freezer DF-RW-SH00293-THA1-1126-462010-76XZK6 created for store ID 1', '::1', '2026-11-12 16:02:57'),
(2, NULL, 'System', 'CREATE_PROFILE', 'FREEZER', 2, 'DF-RW-SFR550DDU-AMD1-0325-380009-A7QJGX', 'Freezer DF-RW-SFR550DDU-AMD1-0325-380009-A7QJGX created for store ID 2', '127.0.0.1', '2026-11-13 00:35:55'),
(3, NULL, 'System', 'KYC_VERIFY', 'FREEZER', 2, 'DF-RW-SFR550DDU-AMD1-0325-380009-A7QJGX', 'KYC 2025-H2 = PASS at store #2', '127.0.0.1', '2026-11-13 00:35:55'),
(4, NULL, 'System', 'CREATE_PROFILE', 'FREEZER', 3, 'DF-RW-SFR400-PUN1-0525-411001-UH3CKM', 'Freezer DF-RW-SFR400-PUN1-0525-411001-UH3CKM created for store ID 3', '127.0.0.1', '2026-11-13 00:35:55'),
(5, NULL, 'System', 'KYC_VERIFY', 'FREEZER', 3, 'DF-RW-SFR400-PUN1-0525-411001-UH3CKM', 'KYC 2025-H2 = PASS at store #3', '127.0.0.1', '2026-11-13 00:35:55'),
(6, NULL, 'System', 'CREATE_PROFILE', 'FREEZER', 4, 'DF-RW-SFR550DDU-DEL1-0125-110001-W35PHW', 'Freezer DF-RW-SFR550DDU-DEL1-0125-110001-W35PHW created for store ID 4', '127.0.0.1', '2026-11-13 00:35:55'),
(7, NULL, 'System', 'CREATE_PROFILE', 'FREEZER', 5, 'DF-RW-SFR300-KOL1-0725-700091-4PXAEN', 'Freezer DF-RW-SFR300-KOL1-0725-700091-4PXAEN created for store ID 5', '127.0.0.1', '2026-11-13 00:35:55'),
(8, NULL, 'System', 'KYC_VERIFY', 'FREEZER', 5, 'DF-RW-SFR300-KOL1-0725-700091-4PXAEN', 'KYC 2025-H2 = FAIL at store #5', '127.0.0.1', '2026-11-13 00:35:55'),
(9, NULL, 'System', 'CREATE_PROFILE', 'FREEZER', 6, 'DF-RW-SFR550DDU-AMD2-0925-380054-UEH4QC', 'Freezer DF-RW-SFR550DDU-AMD2-0925-380054-UEH4QC created for store ID 6', '127.0.0.1', '2026-11-13 00:35:55'),
(10, NULL, 'System', 'KYC_VERIFY', 'FREEZER', 6, 'DF-RW-SFR550DDU-AMD2-0925-380054-UEH4QC', 'KYC 2025-H2 = PASS at store #6', '127.0.0.1', '2026-11-13 00:35:55');

-- --------------------------------------------------------

--
-- Table structure for table `brands`
--

DROP TABLE IF EXISTS `brands`;
CREATE TABLE IF NOT EXISTS `brands` (
  `code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `brands`
--

INSERT INTO `brands` (`code`, `name`, `description`, `is_active`, `created_at`) VALUES
('BS', 'Blue Star', 'Blue Star commercial deep freezers & coolers', 1, '2026-10-26 17:00:06'),
('HV', 'Havells', 'Havells industrial and retail cooling units', 1, '2026-10-26 17:00:06'),
('LG', 'LG Electronics', 'LG commercial refrigeration appliances', 1, '2026-10-26 17:00:06'),
('OT', 'Other / OEM', 'Other or custom commercial cooling manufacturer', 1, '2026-10-26 17:00:06'),
('RW', 'Rockwell', 'Rockwell commercial refrigeration', 1, '2026-10-26 17:00:06'),
('VL', 'Voltas', 'Voltas commercial cooling', 1, '2026-10-26 17:00:06');

-- --------------------------------------------------------

--
-- Table structure for table `freezers`
--

DROP TABLE IF EXISTS `freezers`;
CREATE TABLE IF NOT EXISTS `freezers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `unique_freezer_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` int NOT NULL,
  `freezer_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacity_litres` int DEFAULT NULL,
  `freezer_condition` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'WORKING CONDITION',
  `location_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pincode_last3` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL,
  `install_month_year` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_sequence` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `installation_date` date NOT NULL,
  `image_left_side` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_right_side` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_iot_device` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qr_code_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qr_code_data` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Active','Maintenance','Decommissioned','Under Inspection') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sim_number` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sim_company` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_sim` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_by_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_freezer_id` (`unique_freezer_id`),
  KEY `idx_freezers_store` (`store_id`),
  KEY `idx_freezers_type` (`freezer_type`),
  KEY `idx_freezers_company` (`company_code`),
  KEY `idx_freezers_status` (`status`),
  KEY `idx_freezers_install` (`installation_date`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `freezers`
--

INSERT INTO `freezers` (`id`, `unique_freezer_id`, `store_id`, `freezer_type`, `company_code`, `model_number`, `capacity_litres`, `freezer_condition`, `location_code`, `pincode_last3`, `install_month_year`, `unit_sequence`, `installation_date`, `image_left_side`, `image_right_side`, `image_iot_device`, `qr_code_path`, `qr_code_data`, `status`, `notes`, `created_by`, `created_at`, `updated_at`, `sim_number`, `sim_company`, `image_sim`, `deleted_at`, `created_by_name`) VALUES
(1, 'DF-RW-SH00293-THA1-1126-462010-76XZK6', 1, 'DF', 'RW', 'SH00293', 550, 'WORKING CONDITION', 'THA1', '462010', '1126', '76XZK6', '2026-11-12', 'uploads/freezers/RO-WE-L48WE4-20261112-Sheraton-Traares-Oshiwara-teat-testing-Freezer-Left-Side.jpg', 'uploads/freezers/RO-WE-L48WE4-20261112-Sheraton-Traares-Oshiwara-teat-testing-Freezer-Right-Side.jpg', NULL, 'uploads/qrcodes/qr_TAG-RO-WE-L48WE4.svg', 'http://localhost/DF application/tag.php?t=RO-WE-L48WE4', 'Active', '', 10, '2026-11-12 16:02:57', '2026-11-12 16:02:57', '89919225034008649001', 'Airtel', 'uploads/freezers/RO-WE-L48WE4-20261112-Sheraton-Traares-Oshiwara-teat-testing-SIM-Number.jpg', NULL, 'Aman Singh'),
(2, 'DF-RW-SFR550DDU-AMD1-0325-380009-A7QJGX', 2, 'DF', 'RW', 'SFR550DDU', 550, 'WORKING CONDITION', 'AMD1', '380009', '0325', 'A7QJGX', '2025-03-12', NULL, NULL, NULL, 'uploads/qrcodes/qr_TAG-RO-WE-6QL2G8.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-WE-6QL2G8', 'Active', 'DUMMY sample #1 — West / Ahmedabad', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '9999900001', 'Airtel', NULL, NULL, 'Harshit SuperAdmin'),
(3, 'DF-RW-SFR400-PUN1-0525-411001-UH3CKM', 3, 'DF', 'RW', 'SFR400', 400, 'WORKING CONDITION', 'PUN1', '411001', '0525', 'UH3CKM', '2025-05-08', NULL, NULL, NULL, 'uploads/qrcodes/qr_TAG-RO-SO-EA5ND2.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-SO-EA5ND2', 'Active', 'DUMMY sample #2 — South / Pune', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '9999900002', 'Airtel', NULL, NULL, 'Harshit SuperAdmin'),
(4, 'DF-RW-SFR550DDU-DEL1-0125-110001-W35PHW', 4, 'DF', 'RW', 'SFR550DDU', 550, 'WORKING CONDITION', 'DEL1', '110001', '0125', 'W35PHW', '2025-01-20', NULL, NULL, NULL, 'uploads/qrcodes/qr_TAG-RO-NO-BXS5XS.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-NO-BXS5XS', 'Active', 'DUMMY sample #3 — North / Delhi · KYC due soon', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '9999900003', 'Airtel', NULL, NULL, 'Harshit SuperAdmin'),
(5, 'DF-RW-SFR300-KOL1-0725-700091-4PXAEN', 5, 'DF', 'RW', 'SFR300', 300, 'WORKING CONDITION', 'KOL1', '700091', '0725', '4PXAEN', '2025-07-04', NULL, NULL, NULL, 'uploads/qrcodes/qr_TAG-RO-EA-WFF939.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-EA-WFF939', 'Active', 'DUMMY sample #4 — East / Kolkata · FAIL sample', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '9999900004', 'Airtel', NULL, NULL, 'Harshit SuperAdmin'),
(6, 'DF-RW-SFR550DDU-AMD2-0925-380054-UEH4QC', 6, 'DF', 'RW', 'SFR550DDU', 550, 'WORKING CONDITION', 'AMD2', '380054', '0925', 'UEH4QC', '2025-09-18', NULL, NULL, NULL, 'uploads/qrcodes/qr_TAG-RO-WE-VY5QHF.svg', 'http://localhost:8000c:/wamp64/www/DF application/scripts/tag.php?t=RO-WE-VY5QHF', 'Active', 'DUMMY sample #5 — West / Ahmedabad mall', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55', '9999900005', 'Airtel', NULL, NULL, 'Harshit SuperAdmin');

-- --------------------------------------------------------

--
-- Table structure for table `freezer_placements`
--

DROP TABLE IF EXISTS `freezer_placements`;
CREATE TABLE IF NOT EXISTS `freezer_placements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `freezer_id` int NOT NULL,
  `store_id` int NOT NULL,
  `started_at` date NOT NULL,
  `ended_at` date DEFAULT NULL,
  `transfer_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_placement_freezer` (`freezer_id`),
  KEY `idx_placement_store` (`store_id`),
  KEY `idx_placement_open` (`freezer_id`,`ended_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `freezer_placements`
--

INSERT INTO `freezer_placements` (`id`, `freezer_id`, `store_id`, `started_at`, `ended_at`, `transfer_reason`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, 1, '2026-11-12', NULL, 'Initial placement', 'Created with profile', 10, '2026-11-12 16:02:57'),
(2, 2, 2, '2025-03-12', NULL, 'Initial placement', 'Created with profile', 7, '2026-11-13 00:35:55'),
(3, 3, 3, '2025-05-08', NULL, 'Initial placement', 'Created with profile', 7, '2026-11-13 00:35:55'),
(4, 4, 4, '2025-01-20', NULL, 'Initial placement', 'Created with profile', 7, '2026-11-13 00:35:55'),
(5, 5, 5, '2025-07-04', NULL, 'Initial placement', 'Created with profile', 7, '2026-11-13 00:35:55'),
(6, 6, 6, '2025-09-18', NULL, 'Initial placement', 'Created with profile', 7, '2026-11-13 00:35:55');

-- --------------------------------------------------------

--
-- Table structure for table `freezer_types`
--

DROP TABLE IF EXISTS `freezer_types`;
CREATE TABLE IF NOT EXISTS `freezer_types` (
  `code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `freezer_types`
--

INSERT INTO `freezer_types` (`code`, `name`, `description`, `is_active`, `created_at`) VALUES
('DCF', 'Deep Chest Freezer', 'Chest type freezer', 1, '2026-10-26 17:00:06'),
('DF', 'Deep Freezer', 'Standard deep freezer', 1, '2026-10-26 17:00:06'),
('FRZ', 'General Freezer', 'General multi-purpose commercial freezing unit', 1, '2026-10-26 17:00:06'),
('UDF', 'Upright Deep Freezer', 'Vertical standing freezer with front opening doors', 1, '2026-10-26 17:00:06');

-- --------------------------------------------------------

--
-- Table structure for table `iot_devices`
--

DROP TABLE IF EXISTS `iot_devices`;
CREATE TABLE IF NOT EXISTS `iot_devices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `freezer_id` int NOT NULL,
  `device_serial` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `imei_or_mac` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sim_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sim_company` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Temperature & GPS',
  `device_owner` enum('ReeferON Own Device','Outsourced Device') COLLATE utf8mb4_unicode_ci DEFAULT 'ReeferON Own Device',
  `oem_model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'RF-T100',
  `battery_percentage` int DEFAULT '98',
  `power_status` enum('ON','OFF') COLLATE utf8mb4_unicode_ci DEFAULT 'ON',
  `last_ping_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `freezer_id` (`freezer_id`),
  UNIQUE KEY `device_serial` (`device_serial`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `iot_telemetry`
--

DROP TABLE IF EXISTS `iot_telemetry`;
CREATE TABLE IF NOT EXISTS `iot_telemetry` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `freezer_id` int NOT NULL,
  `temperature` decimal(5,2) NOT NULL,
  `target_min_temp` decimal(5,2) DEFAULT '-24.00',
  `target_max_temp` decimal(5,2) DEFAULT '-18.00',
  `is_alert` tinyint(1) DEFAULT '0',
  `compressor_running` tinyint(1) DEFAULT '1',
  `door_open` tinyint(1) DEFAULT '0',
  `power_status` enum('ON','OFF') COLLATE utf8mb4_unicode_ci DEFAULT 'ON',
  `battery_level` int DEFAULT '98',
  `recorded_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_telemetry_freezer_time` (`freezer_id`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kyc_verifications`
--

DROP TABLE IF EXISTS `kyc_verifications`;
CREATE TABLE IF NOT EXISTS `kyc_verifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `freezer_id` int NOT NULL,
  `store_id` int NOT NULL,
  `cycle_year` smallint NOT NULL,
  `cycle_half` tinyint NOT NULL COMMENT '1=Jan-Jun, 2=Jul-Dec',
  `verified_at` datetime NOT NULL,
  `result` enum('PASS','FAIL','PENDING') COLLATE utf8mb4_unicode_ci DEFAULT 'PASS',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `image_store_front` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_freezer` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `verified_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kyc_cycle` (`freezer_id`,`cycle_year`,`cycle_half`),
  KEY `idx_kyc_store` (`store_id`),
  KEY `idx_kyc_verified` (`verified_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `kyc_verifications`
--

INSERT INTO `kyc_verifications` (`id`, `freezer_id`, `store_id`, `cycle_year`, `cycle_half`, `verified_at`, `result`, `notes`, `image_store_front`, `image_freezer`, `latitude`, `longitude`, `verified_by`, `created_at`) VALUES
(1, 2, 2, 2025, 2, '2025-09-15 19:05:55', 'PASS', 'DUMMY seed KYC #1', NULL, NULL, 23.02250000, 72.57140000, 7, '2026-11-13 00:35:55'),
(2, 3, 3, 2025, 2, '2025-10-01 19:05:55', 'PASS', 'DUMMY seed KYC #2', NULL, NULL, 18.53620000, 73.89390000, 7, '2026-11-13 00:35:55'),
(3, 5, 5, 2025, 2, '2025-10-20 19:05:55', 'FAIL', 'DUMMY seed KYC #4', NULL, NULL, 22.57690000, 88.43390000, 7, '2026-11-13 00:35:55'),
(4, 6, 6, 2025, 2, '2025-11-01 19:05:55', 'PASS', 'DUMMY seed KYC #5', NULL, NULL, 23.04010000, 72.50850000, 7, '2026-11-13 00:35:55');

-- --------------------------------------------------------

--
-- Table structure for table `location_cities`
--

DROP TABLE IF EXISTS `location_cities`;
CREATE TABLE IF NOT EXISTS `location_cities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `zone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prefix` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_location_code` (`location_code`),
  KEY `idx_location_cities_zone` (`zone`),
  KEY `idx_location_cities_state` (`state`),
  KEY `idx_location_cities_city` (`city`),
  KEY `idx_location_cities_prefix` (`prefix`)
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `location_cities`
--

INSERT INTO `location_cities` (`id`, `zone`, `state`, `city`, `location_code`, `prefix`, `pincode`, `latitude`, `longitude`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'CENTRAL', 'Madhya Pradesh', 'Bhopal', 'BPL1', 'BPL', '462001', 23.25990000, 77.41260000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(2, 'CENTRAL', 'Madhya Pradesh', 'Indore', 'IND1', 'IND', '452001', 22.71960000, 75.85770000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(3, 'CENTRAL', 'Madhya Pradesh', 'Gwalior', 'GWL1', 'GWL', '474001', 26.21830000, 78.18280000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(4, 'CENTRAL', 'Madhya Pradesh', 'Jabalpur', 'JAB1', 'JAB', '482001', 23.18150000, 79.98640000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(5, 'CENTRAL', 'Madhya Pradesh', 'Ujjain', 'UJJ1', 'UJJ', '456001', 23.17650000, 75.78850000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(6, 'CENTRAL', 'Madhya Pradesh', 'Sagar', 'SAG1', 'SAG', '470001', 23.83880000, 78.73780000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(7, 'CENTRAL', 'Madhya Pradesh', 'Ratlam', 'RAT1', 'RAT', '457001', 23.33150000, 75.03670000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(8, 'CENTRAL', 'Madhya Pradesh', 'Rewa', 'REW1', 'REW', '486001', 24.53620000, 81.30370000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(9, 'CENTRAL', 'Chhattisgarh', 'Raipur', 'RAI1', 'RAI', '492001', 21.25140000, 81.62960000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(10, 'CENTRAL', 'Chhattisgarh', 'Bilaspur', 'BIL1', 'BIL', '495001', 22.07970000, 82.14090000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(11, 'CENTRAL', 'Chhattisgarh', 'Durg-Bhilai', 'DUR1', 'DUR', '490006', 21.21670000, 81.43330000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(12, 'CENTRAL', 'Chhattisgarh', 'Korba', 'KRB1', 'KRB', '495677', 22.35950000, 82.75010000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(13, 'WEST', 'Gujarat', 'Ahmedabad', 'AMD1', 'AMD', '380058', 23.03380000, 72.55700000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(14, 'WEST', 'Gujarat', 'Surat', 'SUR1', 'SUR', '395001', 21.17020000, 72.83110000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(15, 'WEST', 'Gujarat', 'Vadodara', 'BRD1', 'BRD', '390001', 22.30720000, 73.18120000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(16, 'WEST', 'Gujarat', 'Rajkot', 'RAJ1', 'RAJ', '360001', 22.30390000, 70.80220000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(17, 'WEST', 'Gujarat', 'Gandhinagar', 'GND1', 'GND', '382010', 23.21560000, 72.63690000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(18, 'WEST', 'Gujarat', 'Bhavnagar', 'BHV1', 'BHV', '364001', 21.76450000, 72.15190000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(19, 'WEST', 'Gujarat', 'Jamnagar', 'JAM1', 'JAM', '361001', 22.47070000, 70.05770000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(20, 'WEST', 'Maharashtra', 'Mumbai', 'MUM1', 'MUM', '400018', 18.99860000, 72.82580000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(21, 'WEST', 'Maharashtra', 'Pune', 'PUN1', 'PUN', '411001', 18.52040000, 73.85670000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(22, 'WEST', 'Maharashtra', 'Nagpur', 'NAG1', 'NAG', '440001', 21.14580000, 79.08820000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(23, 'WEST', 'Maharashtra', 'Thane', 'THA1', 'THA', '400601', 19.21830000, 72.97810000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(24, 'WEST', 'Maharashtra', 'Nashik', 'NAS1', 'NAS', '422001', 19.99750000, 73.78980000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(25, 'WEST', 'Maharashtra', 'Aurangabad', 'AUR1', 'AUR', '431001', 19.87620000, 75.34330000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(26, 'WEST', 'Maharashtra', 'Navi Mumbai', 'NVM1', 'NVM', '400703', 19.03300000, 73.02970000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(27, 'WEST', 'Maharashtra', 'Kolhapur', 'KLP1', 'KLP', '416001', 16.70500000, 74.24330000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(28, 'WEST', 'Goa', 'Panaji', 'GOA1', 'GOA', '403001', 15.49090000, 73.82780000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(29, 'WEST', 'Goa', 'Margao', 'GOA2', 'GOA', '403601', 15.28320000, 73.98620000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(30, 'NORTH', 'Delhi NCR', 'New Delhi', 'DEL1', 'DEL', '110001', 28.61390000, 77.20900000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(31, 'NORTH', 'Delhi NCR', 'South Delhi', 'DEL2', 'DEL', '110017', 28.53550000, 77.21000000, 1, '2026-11-04 15:48:24', '2026-11-04 15:48:24'),
(32, 'NORTH', 'Delhi NCR', 'Noida', 'NOI1', 'NOI', '201301', 28.53550000, 77.39100000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(33, 'NORTH', 'Delhi NCR', 'Gurugram', 'GGN1', 'GGN', '122001', 28.45950000, 77.02660000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(34, 'NORTH', 'Rajasthan', 'Jaipur', 'JAI1', 'JAI', '302001', 26.91240000, 75.78730000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(35, 'NORTH', 'Rajasthan', 'Jodhpur', 'JOD1', 'JOD', '342001', 26.23890000, 73.02430000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(36, 'NORTH', 'Rajasthan', 'Udaipur', 'UDA1', 'UDA', '313001', 24.58540000, 73.71250000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(37, 'NORTH', 'Rajasthan', 'Kota', 'KOT1', 'KOT', '324001', 25.21380000, 75.86480000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(38, 'NORTH', 'Uttar Pradesh', 'Lucknow', 'LKO1', 'LKO', '226001', 26.84670000, 80.94620000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(39, 'NORTH', 'Uttar Pradesh', 'Kanpur', 'KNP1', 'KNP', '208001', 26.44990000, 80.33190000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(40, 'NORTH', 'Uttar Pradesh', 'Varanasi', 'VAR1', 'VAR', '221001', 25.31760000, 82.97390000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(41, 'NORTH', 'Uttar Pradesh', 'Agra', 'AGR1', 'AGR', '282001', 27.17670000, 78.00810000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(42, 'NORTH', 'Uttar Pradesh', 'Prayagraj', 'ALL1', 'ALL', '211001', 25.43580000, 81.84630000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(43, 'NORTH', 'Punjab', 'Chandigarh', 'CHD1', 'CHD', '160001', 30.73330000, 76.77940000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(44, 'NORTH', 'Punjab', 'Ludhiana', 'LDH1', 'LDH', '141001', 30.90100000, 75.85730000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(45, 'NORTH', 'Punjab', 'Amritsar', 'ASR1', 'ASR', '143001', 31.63400000, 74.87230000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(46, 'NORTH', 'Haryana', 'Faridabad', 'FBD1', 'FBD', '121001', 28.40890000, 77.31780000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(47, 'NORTH', 'Haryana', 'Panipat', 'PAN1', 'PAN', '132103', 29.39090000, 76.96350000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(48, 'SOUTH', 'Karnataka', 'Bengaluru', 'BLR1', 'BLR', '560001', 12.97160000, 77.59460000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(49, 'SOUTH', 'Karnataka', 'Mysuru', 'MYS1', 'MYS', '570001', 12.29580000, 76.63940000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(50, 'SOUTH', 'Karnataka', 'Hubballi', 'HUB1', 'HUB', '580020', 15.36470000, 75.12400000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(51, 'SOUTH', 'Karnataka', 'Mangaluru', 'MNG1', 'MNG', '575001', 12.91410000, 74.85600000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(52, 'SOUTH', 'Telangana', 'Hyderabad', 'HYD1', 'HYD', '500001', 17.38500000, 78.48670000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(53, 'SOUTH', 'Telangana', 'Warangal', 'WAR1', 'WAR', '506002', 17.96890000, 79.59410000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(54, 'SOUTH', 'Tamil Nadu', 'Chennai', 'CHN1', 'CHN', '600001', 13.08270000, 80.27070000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(55, 'SOUTH', 'Tamil Nadu', 'Coimbatore', 'CBE1', 'CBE', '641001', 11.01680000, 76.95580000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(56, 'SOUTH', 'Tamil Nadu', 'Madurai', 'MDU1', 'MDU', '625001', 9.92520000, 78.11980000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(57, 'SOUTH', 'Kerala', 'Kochi', 'COK1', 'COK', '682001', 9.93120000, 76.26730000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(58, 'SOUTH', 'Kerala', 'Thiruvananthapuram', 'TRV1', 'TRV', '695001', 8.52410000, 76.93660000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(59, 'SOUTH', 'Andhra Pradesh', 'Visakhapatnam', 'VIZ1', 'VIZ', '530001', 17.68680000, 83.21850000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(60, 'SOUTH', 'Andhra Pradesh', 'Vijayawada', 'VIJ1', 'VIJ', '520001', 16.50620000, 80.64800000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(61, 'EAST', 'West Bengal', 'Kolkata', 'KOL1', 'KOL', '700001', 22.57260000, 88.36390000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(62, 'EAST', 'West Bengal', 'Howrah', 'HOW1', 'HOW', '711101', 22.59580000, 88.26360000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(63, 'EAST', 'West Bengal', 'Siliguri', 'SLG1', 'SLG', '734001', 26.72710000, 88.39530000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(64, 'EAST', 'Bihar', 'Patna', 'PAT1', 'PAT', '800001', 25.59410000, 85.13760000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(65, 'EAST', 'Bihar', 'Gaya', 'GAY1', 'GAY', '823001', 24.79140000, 85.00020000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(66, 'EAST', 'Odisha', 'Bhubaneswar', 'BBI1', 'BBI', '751001', 20.29610000, 85.82450000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(67, 'EAST', 'Odisha', 'Cuttack', 'CTC1', 'CTC', '753001', 20.46250000, 85.88300000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(68, 'EAST', 'Jharkhand', 'Ranchi', 'RNC1', 'RNC', '834001', 23.34410000, 85.30960000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(69, 'EAST', 'Jharkhand', 'Jamshedpur', 'IXW1', 'IXW', '831001', 22.80460000, 86.20290000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25'),
(70, 'EAST', 'Assam', 'Guwahati', 'GAU1', 'GAU', '781001', 26.14450000, 91.73620000, 1, '2026-11-04 15:48:25', '2026-11-04 15:48:25');

-- --------------------------------------------------------

--
-- Table structure for table `stores`
--

DROP TABLE IF EXISTS `stores`;
CREATE TABLE IF NOT EXISTS `stores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `outlet_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `distributor_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aadhar_number` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fssai_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gst_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `zone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'WEST',
  `location_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Commercial Store',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `gps_accuracy` decimal(8,2) DEFAULT NULL,
  `gps_captured_at` datetime DEFAULT NULL,
  `location_link` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Google Maps share link for current location',
  `image_store_front` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_aadhar_card` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_aadhar_back` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `outlet_id` (`outlet_id`),
  KEY `idx_stores_city` (`city`),
  KEY `idx_stores_state` (`state`),
  KEY `idx_stores_pincode` (`pincode`),
  KEY `idx_stores_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `stores`
--

INSERT INTO `stores` (`id`, `outlet_id`, `store_name`, `customer_name`, `distributor_name`, `contact_person`, `phone_number`, `email`, `aadhar_number`, `fssai_number`, `gst_number`, `address`, `city`, `state`, `pincode`, `zone`, `location_type`, `latitude`, `longitude`, `gps_accuracy`, `gps_captured_at`, `location_link`, `image_store_front`, `image_aadhar_card`, `image_aadhar_back`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'THA1-462010-0001', 'Sheraton Traares Oshiwara teat testing', 'Baskin Robbins India', 'Ashish jyothi', 'Aman Singh', '+918765432123', 'a@aa.com', '272382738273', NULL, NULL, 'Coach Factory Road', 'Thane', 'Maharashtra', '462010', 'WEST', 'Commercial Store', 19.20320200, 72.95401100, 55.00, '2026-11-12 10:32:57', 'https://maps.google.com/?q=19.203201874999994%2C72.95401062499998', 'uploads/stores/RO-WE-L48WE4-20261112-Sheraton-Traares-Oshiwara-teat-testing-Store-Front.jpg', 'uploads/stores/RO-WE-L48WE4-20261112-Sheraton-Traares-Oshiwara-teat-testing-Aadhaar-Front.jpg', 'uploads/stores/RO-WE-L48WE4-20261112-Sheraton-Traares-Oshiwara-teat-testing-Aadhaar-Back.jpg', 'Active', 10, '2026-11-12 16:02:57', '2026-11-12 16:02:57'),
(2, 'AMD1-380009-0001', 'Dummy Store — CG Road Hub', 'Baskin Robbins', 'West Dist Ahmedabad', 'Ravi Patel', '+919876500001', 'dummy1.store@example.com', '123456789001', '10012021000001', '24AAAAA0000A1Z5', 'Shop 12, CG Road, Navrangpura', 'Ahmedabad', 'Gujarat', '380009', 'WEST', 'Retail Store', 23.02250000, 72.57140000, 12.00, '2026-11-12 19:05:54', 'https://maps.google.com/?q=23.022500%2C72.571400', NULL, NULL, NULL, 'Active', 7, '2026-11-13 00:35:54', '2026-11-13 00:35:54'),
(3, 'PUN1-411001-0001', 'Dummy Store — Koregaon Park', 'Baskin Robbins', 'South Dist Pune', 'Sneha Deshmukh', '+919876500002', 'dummy2.store@example.com', '123456789002', '10012021000002', '27BBBBB0000B1Z5', 'Lane 5, Koregaon Park', 'Pune', 'Maharashtra', '411001', 'SOUTH', 'Retail Store', 18.53620000, 73.89390000, 12.00, '2026-11-12 19:05:55', 'https://maps.google.com/?q=18.536200%2C73.893900', NULL, NULL, NULL, 'Active', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55'),
(4, 'DEL1-110001-0001', 'Dummy Store — Connaught Place', 'Baskin Robbins', 'North Dist Delhi', 'Amit Sharma', '+919876500003', 'dummy3.store@example.com', '123456789003', '10012021000003', '07CCCCC0000C1Z5', 'Block B, Connaught Place', 'New Delhi', 'Delhi', '110001', 'NORTH', 'Commercial Store', 28.63150000, 77.21670000, 12.00, '2026-11-12 19:05:55', 'https://maps.google.com/?q=28.631500%2C77.216700', NULL, NULL, NULL, 'Active', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55'),
(5, 'KOL1-700091-0001', 'Dummy Store — Salt Lake Sector V', 'Baskin Robbins', 'East Dist Kolkata', 'Priya Banerjee', '+919876500004', 'dummy4.store@example.com', '123456789004', '10012021000004', '19DDDDD0000D1Z5', 'Plot 42, Sector V, Salt Lake', 'Kolkata', 'West Bengal', '700091', 'EAST', 'Retail Store', 22.57690000, 88.43390000, 12.00, '2026-11-12 19:05:55', 'https://maps.google.com/?q=22.576900%2C88.433900', NULL, NULL, NULL, 'Active', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55'),
(6, 'AMD1-380054-0001', 'Dummy Store — SG Highway Mall', 'Baskin Robbins', 'West Dist Ahmedabad', 'Kiran Mehta', '+919876500005', 'dummy5.store@example.com', '123456789005', '10012021000005', '24EEEEE0000E1Z5', 'Food Court, SG Highway Mall', 'Ahmedabad', 'Gujarat', '380054', 'WEST', 'Mall Kiosk', 23.04010000, 72.50850000, 12.00, '2026-11-12 19:05:55', 'https://maps.google.com/?q=23.040100%2C72.508500', NULL, NULL, NULL, 'Active', 7, '2026-11-13 00:35:55', '2026-11-13 00:35:55');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('super_admin','technician','viewer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'super_admin',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `phone`, `zone`, `city`, `status`, `last_login`, `created_at`, `updated_at`) VALUES
(7, 'Harshit SuperAdmin', 'admin@reeferon.com', '$2y$12$6i5IJVSjRvN7KDkliiqt6OJesXxcLjoQLlKypgDJ6iQyoQZ246osu', 'super_admin', '9876543210', NULL, NULL, 'active', '2026-11-13 00:19:30', '2026-11-12 13:26:31', '2026-11-13 00:19:30'),
(10, 'Aman Singh', 'a@a.com', '$2y$12$tt5mB9lQLWkgve/3b8KU5OvA7rRzHPMIdsFpqSZ7/wqt.HeRPRni2', 'technician', '+918765432109', NULL, NULL, 'active', '2026-11-13 00:41:14', '2026-11-12 15:20:49', '2026-11-13 00:41:14');

--
-- Constraints for dumped tables
--

--
-- Constraints for table `freezers`
--
ALTER TABLE `freezers`
  ADD CONSTRAINT `fk_freezers_brand` FOREIGN KEY (`company_code`) REFERENCES `brands` (`code`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_freezers_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_freezers_type` FOREIGN KEY (`freezer_type`) REFERENCES `freezer_types` (`code`) ON UPDATE CASCADE;

--
-- Constraints for table `freezer_placements`
--
ALTER TABLE `freezer_placements`
  ADD CONSTRAINT `fk_placement_freezer` FOREIGN KEY (`freezer_id`) REFERENCES `freezers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_placement_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `iot_devices`
--
ALTER TABLE `iot_devices`
  ADD CONSTRAINT `fk_iot_freezer` FOREIGN KEY (`freezer_id`) REFERENCES `freezers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `iot_telemetry`
--
ALTER TABLE `iot_telemetry`
  ADD CONSTRAINT `fk_telemetry_freezer` FOREIGN KEY (`freezer_id`) REFERENCES `freezers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kyc_verifications`
--
ALTER TABLE `kyc_verifications`
  ADD CONSTRAINT `fk_kyc_freezer` FOREIGN KEY (`freezer_id`) REFERENCES `freezers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_kyc_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
