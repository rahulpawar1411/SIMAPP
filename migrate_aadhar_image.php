<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();

    $col = $pdo->query("SHOW COLUMNS FROM `stores` LIKE 'image_aadhar_card'")->fetchAll();
    if ($col) {
        echo "image_aadhar_card already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `stores` ADD COLUMN `image_aadhar_card` VARCHAR(255) NULL AFTER `image_store_front`");
        echo "image_aadhar_card column added.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
