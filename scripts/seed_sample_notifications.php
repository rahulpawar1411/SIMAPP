<?php
/**
 * One-shot: seed 2 pending KYC notifications for Notifications menu testing.
 * Run: php scripts/seed_sample_notifications.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Models\AppNotification;

$pdo = Database::getConnection();
$row = $pdo->query("
    SELECT f.id, f.unique_freezer_id, f.store_id,
           s.store_name, s.outlet_id, s.address, s.city, s.state, s.pincode,
           s.zone, s.contact_person, s.phone_number, s.customer_name
    FROM freezers f
    INNER JOIN stores s ON s.id = f.store_id
    WHERE f.deleted_at IS NULL
    ORDER BY f.id ASC
    LIMIT 1
")->fetch(\PDO::FETCH_ASSOC);

if (!$row) {
    fwrite(STDERR, "No freezer found. Add a freezer first.\n");
    exit(1);
}

$freezerId = (int)$row['id'];
$ufid = (string)$row['unique_freezer_id'];
$adminBy = null;
try {
    $u = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $adminBy = $u ? (int)$u['id'] : null;
} catch (Throwable $e) {
    $adminBy = null;
}

// Clear previous sample pending for this freezer (optional cleanup of our sample titles)
$del = $pdo->prepare("DELETE FROM app_notifications
    WHERE freezer_id = :fid AND status = 'pending'
      AND title LIKE 'SAMPLE —%'");
try {
    AppNotification::ensureTable();
    $del->execute(['fid' => $freezerId]);
} catch (Throwable $e) {
    AppNotification::ensureTable();
}

$idSame = AppNotification::create([
    'type' => AppNotification::TYPE_KYC_SAME_STORE,
    'freezer_id' => $freezerId,
    'unique_freezer_id' => $ufid,
    'title' => 'SAMPLE — same store KYC',
    'message' => 'Sample same-place KYC (PASS) for ' . ($row['store_name'] ?? 'store')
        . ' [' . ($row['outlet_id'] ?? '') . ']. Profile not updated until admin approves.',
    'created_by' => $adminBy,
    'payload' => [
        'kyc' => [
            'verified_at' => date('Y-m-d'),
            'cycle_year' => (int)date('Y'),
            'cycle_half' => ((int)date('n') <= 6) ? 1 : 2,
            'result' => 'PASS',
            'notes' => 'Sample same-store KYC for notification testing.',
            'image_store_front' => null,
            'image_freezer' => null,
            'latitude' => null,
            'longitude' => null,
            'verified_by' => $adminBy,
        ],
        'from_store' => [
            'store_id' => (int)$row['store_id'],
            'outlet_id' => $row['outlet_id'] ?? null,
            'store_name' => $row['store_name'] ?? null,
        ],
    ],
]);

$idMove = AppNotification::create([
    'type' => AppNotification::TYPE_KYC_STORE_MOVE,
    'freezer_id' => $freezerId,
    'unique_freezer_id' => $ufid,
    'title' => 'SAMPLE — new store move',
    'message' => 'Sample move from ' . ($row['store_name'] ?? 'current store')
        . ' → Sample New Outlet (Test). Profile not updated until admin approves.',
    'created_by' => $adminBy,
    'payload' => [
        'store' => [
            'store_name' => 'Sample New Outlet (Test)',
            'address' => '12 Sample Market Road',
            'city' => $row['city'] ?: 'Mumbai',
            'state' => $row['state'] ?: 'Maharashtra',
            'pincode' => preg_match('/^\d{6}$/', (string)($row['pincode'] ?? '')) ? $row['pincode'] : '400001',
            'zone' => $row['zone'] ?: 'WEST',
            'contact_person' => $row['contact_person'] ?: 'Sample Manager',
            'phone_number' => $row['phone_number'] ?: '9876543210',
            'aadhar_number' => '',
            'customer_name' => $row['customer_name'] ?? 'Baskin Robbins',
            'latitude' => '19.076090',
            'longitude' => '72.877426',
            'gps_accuracy' => 12,
            'gps_captured_at' => date('Y-m-d H:i:s'),
            'location_link' => 'https://maps.google.com/?q=19.076090,72.877426',
            'image_store_front' => null,
        ],
        'kyc' => [
            'verified_at' => date('Y-m-d'),
            'cycle_year' => (int)date('Y'),
            'cycle_half' => ((int)date('n') <= 6) ? 1 : 2,
            'result' => 'PASS',
            'notes' => 'Sample new-store KYC for notification testing.',
            'image_store_front' => null,
            'image_freezer' => null,
            'latitude' => '19.076090',
            'longitude' => '72.877426',
            'verified_by' => $adminBy,
        ],
        'from_store' => [
            'store_id' => (int)$row['store_id'],
            'outlet_id' => $row['outlet_id'] ?? null,
            'store_name' => $row['store_name'] ?? null,
        ],
    ],
]);

$pending = AppNotification::countPending();
echo "OK\n";
echo "Freezer: {$ufid} (#{$freezerId})\n";
echo "Same-store notification id: {$idSame}\n";
echo "New-store notification id: {$idMove}\n";
echo "Pending count: {$pending}\n";
