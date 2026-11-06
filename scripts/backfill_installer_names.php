<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Models\Freezer;

Freezer::ensureSimColumns();
$pdo = Database::getConnection();
$sql = "UPDATE freezers f
        LEFT JOIN users u ON u.id = f.created_by
        SET f.created_by_name = u.name
        WHERE (f.created_by_name IS NULL OR f.created_by_name = '')
          AND f.created_by IS NOT NULL
          AND u.name IS NOT NULL
          AND u.name <> ''";
$n = $pdo->exec($sql);
echo "Backfilled rows: " . (int)$n . "\n";
