<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();

    $cols = [
        'sim_number' => "ALTER TABLE `freezers` ADD COLUMN `sim_number` VARCHAR(40) NULL AFTER `notes`",
        'sim_company' => "ALTER TABLE `freezers` ADD COLUMN `sim_company` VARCHAR(50) NULL AFTER `sim_number`",
        'image_sim' => "ALTER TABLE `freezers` ADD COLUMN `image_sim` VARCHAR(500) NULL AFTER `image_iot_device`",
    ];

    foreach ($cols as $name => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM `freezers` LIKE " . $pdo->quote($name))->fetchAll();
        if ($exists) {
            echo "{$name} already exists.\n";
        } else {
            $pdo->exec($sql);
            echo "{$name} column added.\n";
        }
    }
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
