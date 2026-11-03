<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Models\Store;

try {
    $pdo = Database::getConnection();

    $col = $pdo->query("SHOW COLUMNS FROM `stores` LIKE 'location_link'")->fetchAll();
    if ($col) {
        echo "location_link already exists.\n";
    } else {
        $pdo->exec(
            "ALTER TABLE `stores` ADD COLUMN `location_link` VARCHAR(500) NULL
             COMMENT 'Google Maps share link for current location'
             AFTER `gps_captured_at`"
        );
        echo "location_link column added.\n";
    }

    $rows = $pdo->query(
        "SELECT `id`, `latitude`, `longitude` FROM `stores`
         WHERE `latitude` IS NOT NULL AND `longitude` IS NOT NULL
           AND (`location_link` IS NULL OR `location_link` = '')"
    )->fetchAll();

    $stmt = $pdo->prepare("UPDATE `stores` SET `location_link` = :link WHERE `id` = :id");
    $n = 0;
    foreach ($rows as $r) {
        $link = Store::buildMapsLink((float)$r['latitude'], (float)$r['longitude']);
        $stmt->execute(['link' => $link, 'id' => (int)$r['id']]);
        $n++;
    }
    echo "Backfilled location_link on {$n} store(s).\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
