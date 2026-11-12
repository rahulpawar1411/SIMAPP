<?php
/**
 * Remove demo / default transactional data from freezer_management.
 * Keeps: freezer_types, brands, and one Super Admin (admin@reeferon.com) if present.
 *
 * Run: php scripts/clear_demo_data.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Services\PasswordService;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Clearing default/demo data...\n";

$tables = [
    'users', 'stores', 'freezers', 'asset_tags', 'kyc_verifications',
    'app_notifications', 'audit_logs', 'freezer_placements',
    'iot_devices', 'iot_telemetry', 'iot_readings',
];

echo "\nBefore:\n";
foreach ($tables as $t) {
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo "  {$t}: {$n}\n";
    } catch (Throwable $e) {
        echo "  {$t}: (no table)\n";
    }
}

// Keep master admin email so login still works
$keepEmail = 'admin@reeferon.com';
$admin = null;
try {
    $st = $pdo->prepare("SELECT * FROM `users` WHERE `email` = :e LIMIT 1");
    $st->execute(['e' => $keepEmail]);
    $admin = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $admin = null;
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$truncate = [
    'iot_telemetry',
    'iot_readings',
    'iot_devices',
    'kyc_verifications',
    'freezer_placements',
    'app_notifications',
    'audit_logs',
    'asset_tags',
    'freezers',
    'stores',
];

foreach ($truncate as $t) {
    try {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
        echo "Truncated {$t}\n";
    } catch (Throwable $e) {
        // table may not exist
        echo "Skip {$t}: " . $e->getMessage() . "\n";
    }
}

// Soft-delete leftovers if truncate skipped (older schemas)
try {
    $pdo->exec("DELETE FROM `freezers`");
    $pdo->exec("DELETE FROM `stores`");
} catch (Throwable $e) {
    // ignore
}

// Users: remove all demo/tech accounts; re-seed single Super Admin
try {
    $pdo->exec("DELETE FROM `users`");
    $defaultPass = \App\Config\AppConfig::DEFAULT_ADMIN_PASSWORD;
    $hash = PasswordService::hash($defaultPass);
    $name = $admin['name'] ?? 'Super Admin';
    $phone = $admin['phone'] ?? null;
    $ins = $pdo->prepare(
        "INSERT INTO `users` (`name`, `email`, `password`, `role`, `phone`, `zone`, `city`, `status`)
         VALUES (:name, :email, :password, 'super_admin', :phone, NULL, NULL, 'active')"
    );
    $ins->execute([
        'name' => $name,
        'email' => $keepEmail,
        'password' => $hash,
        'phone' => $phone,
    ]);
    echo "Users cleared — kept Super Admin {$keepEmail} / {$defaultPass}\n";
} catch (Throwable $e) {
    echo "Users clear failed: " . $e->getMessage() . "\n";
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "\nAfter:\n";
foreach ($tables as $t) {
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo "  {$t}: {$n}\n";
    } catch (Throwable $e) {
        echo "  {$t}: (no table)\n";
    }
}

echo "\nDone. Master lists (freezer_types / brands) kept.\n";
echo "Login: {$keepEmail} / " . \App\Config\AppConfig::DEFAULT_ADMIN_PASSWORD . "\n";
