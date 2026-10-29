<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();
    $pdo->exec("ALTER TABLE `freezers` MODIFY COLUMN `pincode_last3` VARCHAR(6) NOT NULL");
    echo "pincode_last3 widened to VARCHAR(6) for full PIN in Freezer ID.\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
