<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();

    $fssai = $pdo->query("SHOW COLUMNS FROM `stores` LIKE 'fssai_number'")->fetchAll();
    if ($fssai) {
        echo "fssai_number already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `stores` ADD COLUMN `fssai_number` VARCHAR(20) NULL AFTER `aadhar_number`");
        echo "fssai_number column added.\n";
    }

    $gst = $pdo->query("SHOW COLUMNS FROM `stores` LIKE 'gst_number'")->fetchAll();
    if ($gst) {
        echo "gst_number already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `stores` ADD COLUMN `gst_number` VARCHAR(20) NULL AFTER `fssai_number`");
        echo "gst_number column added.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
