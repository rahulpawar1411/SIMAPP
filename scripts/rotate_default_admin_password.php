<?php
/**
 * Set accounts still on ReeferON@2026 back to AppConfig::DEFAULT_ADMIN_PASSWORD (Admin@123).
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Config\Database;
use App\Services\PasswordService;

$old = 'ReeferON@2026';
$new = AppConfig::DEFAULT_ADMIN_PASSWORD;

$pdo = Database::getConnection();
$rows = $pdo->query('SELECT id, email, password FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$updated = 0;
foreach ($rows as $row) {
    $hash = (string)($row['password'] ?? '');
    if ($hash === '' || !PasswordService::verify($old, $hash)) {
        continue;
    }
    $stmt = $pdo->prepare('UPDATE users SET password = :p WHERE id = :id');
    $stmt->execute([
        'p' => PasswordService::hash($new),
        'id' => (int)$row['id'],
    ]);
    $updated++;
    echo "Updated: {$row['email']} (id {$row['id']})\n";
}

echo $updated === 0
    ? "No accounts still used {$old}.\n"
    : "Done. {$updated} account(s) now use {$new}.\n";
