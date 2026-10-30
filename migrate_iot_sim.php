<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();

    $sim = $pdo->query("SHOW COLUMNS FROM `iot_devices` LIKE 'sim_number'")->fetchAll();
    if ($sim) {
        echo "sim_number already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `iot_devices` ADD COLUMN `sim_number` VARCHAR(30) NULL AFTER `imei_or_mac`");
        echo "sim_number column added.\n";
    }

    $co = $pdo->query("SHOW COLUMNS FROM `iot_devices` LIKE 'sim_company'")->fetchAll();
    if ($co) {
        echo "sim_company already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `iot_devices` ADD COLUMN `sim_company` VARCHAR(50) NULL AFTER `sim_number`");
        echo "sim_company column added.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
