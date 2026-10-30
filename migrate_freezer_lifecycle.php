<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `freezer_placements` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "freezer_placements OK\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `kyc_verifications` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "kyc_verifications OK\n";

    // Backfill open placements for existing freezers missing history
    $missing = $pdo->query("SELECT f.id, f.store_id, f.installation_date, f.created_by
        FROM freezers f
        LEFT JOIN freezer_placements p ON p.freezer_id = f.id AND p.ended_at IS NULL
        WHERE p.id IS NULL")->fetchAll();
    $ins = $pdo->prepare("INSERT INTO freezer_placements
        (freezer_id, store_id, started_at, ended_at, transfer_reason, notes, created_by)
        VALUES (:fid, :sid, :start, NULL, 'Initial placement', 'Backfilled', :by)");
    $n = 0;
    foreach ($missing as $row) {
        $ins->execute([
            'fid' => (int)$row['id'],
            'sid' => (int)$row['store_id'],
            'start' => $row['installation_date'] ?: date('Y-m-d'),
            'by' => $row['created_by'],
        ]);
        $n++;
    }
    echo "Backfilled {$n} open placement(s).\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
