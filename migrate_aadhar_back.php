<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();

    $col = $pdo->query("SHOW COLUMNS FROM `stores` LIKE 'image_aadhar_back'")->fetchAll();
    if ($col) {
        echo "image_aadhar_back already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `stores` ADD COLUMN `image_aadhar_back` VARCHAR(255) NULL AFTER `image_aadhar_card`");
        echo "image_aadhar_back column added.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
