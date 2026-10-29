<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();
    $cols = $pdo->query("SHOW COLUMNS FROM `stores` LIKE 'aadhar_number'")->fetchAll();
    if ($cols) {
        echo "aadhar_number already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `stores` ADD COLUMN `aadhar_number` VARCHAR(12) NULL AFTER `email`");
        echo "aadhar_number column added.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
