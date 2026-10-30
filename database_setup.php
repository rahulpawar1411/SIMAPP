<?php
// Database Installer and Migrator for Freezer / Store Profile Management System

$host = '127.0.0.1';
$port = 3306;
$user = 'root';
$pass = '';

echo "Connecting to MySQL server at {$host}:{$port}...\n";

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "Connected successfully to MySQL.\n";

    // Read schema.sql
    $sqlFile = __DIR__ . '/app/Database/schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("schema.sql not found at {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);

    // Update password hash dynamically for safety
    $demoPassword = 'Admin@123';
    $hashedPassword = password_hash($demoPassword, PASSWORD_DEFAULT);

    // Replace dummy hash in SQL
    $sql = str_replace('$2y$10$fB8Qd9P93tJ32K97n/aO3e0Q5Fk2G8M5Yl8R1bE1aJ7eC9fG7u8O.', $hashedPassword, $sql);

    // Execute queries
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $pdo->exec($stmt);
        }
    }

    echo "Database `freezer_management` created and tables migrated successfully!\n";
    echo "Default User Created:\n";
    echo "  - Super Admin: admin@reeferon.com / {$demoPassword}\n";

} catch (Exception $e) {
    echo "ERROR during database setup: " . $e->getMessage() . "\n";
    exit(1);
}
