<?php
/**
 * Creates asset_tags table for blank QR-first workflow.
 * Run once: php migrate_asset_tags.php  (or open in browser while logged into MySQL).
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

$pdo = Database::getConnection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS `asset_tags` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tag_code` VARCHAR(32) NOT NULL UNIQUE,
    `status` ENUM('unused','assigned') NOT NULL DEFAULT 'unused',
    `freezer_id` INT NULL,
    `qr_code_path` VARCHAR(255) NULL,
    `qr_code_data` TEXT NULL,
    `batch_label` VARCHAR(100) NULL,
    `created_by` INT NULL,
    `assigned_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_asset_tags_status` (`status`),
    INDEX `idx_asset_tags_freezer` (`freezer_id`),
    CONSTRAINT `fk_asset_tags_freezer` FOREIGN KEY (`freezer_id`) REFERENCES `freezers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "OK: asset_tags table ready.\n";
