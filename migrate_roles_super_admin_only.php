<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();

    // Keep only Super Admin users; deactivate others first then delete non-super_admin
    $pdo->exec("UPDATE `users` SET `status` = 'inactive' WHERE `role` <> 'super_admin'");
    $deleted = $pdo->exec("DELETE FROM `users` WHERE `role` <> 'super_admin'");
    echo "Removed non-super_admin users: " . (int)$deleted . "\n";

    // Shrink ENUM to super_admin only
    $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('super_admin') NOT NULL DEFAULT 'super_admin'");
    echo "users.role ENUM limited to super_admin.\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
