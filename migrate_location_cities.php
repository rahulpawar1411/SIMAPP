<?php
/**
 * Create + seed location_cities (GPS + city location/tag codes).
 * Run once: php migrate_location_cities.php
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Models\MasterData;
use App\Config\Database;

try {
    MasterData::ensureLocationCitiesTable();
    $pdo = Database::getConnection();
    $count = (int)$pdo->query('SELECT COUNT(*) FROM `location_cities`')->fetchColumn();
    echo "location_cities ready — {$count} row(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
