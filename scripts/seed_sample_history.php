<?php
/**
 * Seed sample history for DF-RW-SFR550DDU-AMD1-058-0324-AAA004:
 * Installation, KYC same store, KYC new store (move),
 * plus notification reject + approve examples.
 *
 * Run: php scripts/seed_sample_history.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Models\AppNotification;
use App\Models\Store;
use App\Models\AssetTag;

$pdo = Database::getConnection();
$ufid = 'DF-RW-SFR550DDU-AMD1-058-0324-AAA004';
$freezer = Freezer::findByUniqueId($ufid);
if (!$freezer) {
    fwrite(STDERR, "Freezer not found: {$ufid}\n");
    exit(1);
}

$fid = (int)$freezer['id'];
$adminId = null;
try {
    $u = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $adminId = $u ? (int)$u['id'] : null;
} catch (Throwable $e) {
    $adminId = null;
}

FreezerLifecycle::ensureOpenPlacement($freezer);
$tag = null;
try {
    $tag = AssetTag::findByFreezerId($fid);
} catch (Throwable $e) {
    $tag = null;
}

echo "Freezer #{$fid} {$ufid}\n";

// --- 1) Installation date (if empty) ---
if (empty($freezer['installation_date'])) {
    $pdo->prepare("UPDATE freezers SET installation_date = :d WHERE id = :id")
        ->execute(['d' => '2024-03-15', 'id' => $fid]);
    echo "Set installation_date = 2024-03-15\n";
} else {
    echo "Installation date: {$freezer['installation_date']}\n";
}

// Ensure first placement started near install
$placements = FreezerLifecycle::placementHistory($fid);
if ($placements) {
    // oldest
    usort($placements, fn($a, $b) => strcmp((string)$a['started_at'], (string)$b['started_at']));
    $first = $placements[0];
    if (empty($first['started_at']) || $first['started_at'] > '2024-03-15') {
        $pdo->prepare("UPDATE freezer_placements SET started_at = '2024-03-15' WHERE id = :id")
            ->execute(['id' => (int)$first['id']]);
        echo "First placement started_at -> 2024-03-15\n";
    }
}

$freezer = Freezer::findById($fid);
$fromStore = [
    'store_id' => (int)$freezer['store_id'],
    'outlet_id' => $freezer['outlet_id'] ?? null,
    'store_name' => $freezer['store_name'] ?? null,
];

// --- 2) KYC — Same store (recorded = approved) ---
$sameDate = '2024-09-20';
$chk = $pdo->prepare("SELECT id FROM kyc_verifications WHERE freezer_id = :fid AND verified_at = :d AND notes LIKE :n LIMIT 1");
$chk->execute(['fid' => $fid, 'd' => $sameDate, 'n' => '%SAMPLE same-store%']);
if (!$chk->fetch()) {
    // Bypass 6-month guard by inserting directly if needed
    try {
        FreezerLifecycle::recordKyc([
            'freezer_id' => $fid,
            'verified_at' => $sameDate,
            'cycle_year' => 2024,
            'cycle_half' => 2,
            'result' => 'PASS',
            'notes' => 'SAMPLE same-store KYC (approved / on profile)',
            'image_store_front' => null,
            'image_freezer' => null,
            'latitude' => null,
            'longitude' => null,
            'verified_by' => $adminId,
        ]);
        echo "Added KYC same store @ {$sameDate}\n";
    } catch (Throwable $e) {
        // Direct insert if gap rule blocks
        $ins = $pdo->prepare("INSERT INTO kyc_verifications
            (freezer_id, store_id, cycle_year, cycle_half, verified_at, result, notes, verified_by, created_at)
            VALUES (:fid, :sid, 2024, 2, :d, 'PASS', :notes, :by, NOW())");
        $ins->execute([
            'fid' => $fid,
            'sid' => (int)$freezer['store_id'],
            'd' => $sameDate,
            'notes' => 'SAMPLE same-store KYC (approved / on profile)',
            'by' => $adminId,
        ]);
        echo "Added KYC same store (direct) @ {$sameDate}: {$e->getMessage()}\n";
    }
} else {
    echo "Same-store KYC sample already exists\n";
}

// --- 3) KYC — New store (create outlet + transfer + KYC) ---
$moveDate = '2025-03-25';
$chkMove = $pdo->prepare("SELECT id FROM kyc_verifications WHERE freezer_id = :fid AND notes LIKE :n LIMIT 1");
$chkMove->execute(['fid' => $fid, 'n' => '%SAMPLE new-store KYC%']);
if (!$chkMove->fetch()) {
    $created = FreezerLifecycle::createNewOutlet([
        'store_name' => 'SAMPLE Relocated Outlet',
        'address' => '45 Sample Ring Road',
        'city' => $freezer['store_city'] ?: 'Ahmedabad',
        'state' => $freezer['store_state'] ?: 'Gujarat',
        'pincode' => preg_match('/^\d{6}$/', (string)($freezer['store_pincode'] ?? '')) ? $freezer['store_pincode'] : '380058',
        'zone' => $freezer['zone'] ?? 'WEST',
        'contact_person' => $freezer['contact_person'] ?? 'Sample Contact',
        'phone_number' => $freezer['phone_number'] ?? '9876500001',
        'customer_name' => $freezer['customer_name'] ?? 'Baskin Robbins',
        'latitude' => '23.0225',
        'longitude' => '72.5714',
        'location_link' => 'https://maps.google.com/?q=23.0225,72.5714',
    ], $adminId ?: 1);

    FreezerLifecycle::transfer(
        $fid,
        $created['store_id'],
        'KYC — moved to new outlet (SAMPLE auto ID ' . $created['outlet_id'] . ')',
        'SAMPLE new-store move for history',
        $adminId,
        $moveDate
    );

    $freezer = Freezer::findById($fid);
    try {
        FreezerLifecycle::recordKyc([
            'freezer_id' => $fid,
            'verified_at' => $moveDate,
            'cycle_year' => 2025,
            'cycle_half' => 1,
            'result' => 'PASS',
            'notes' => 'SAMPLE new-store KYC (approved after move)',
            'latitude' => '23.0225',
            'longitude' => '72.5714',
            'verified_by' => $adminId,
        ]);
    } catch (Throwable $e) {
        $pdo->prepare("INSERT INTO kyc_verifications
            (freezer_id, store_id, cycle_year, cycle_half, verified_at, result, notes, latitude, longitude, verified_by, created_at)
            VALUES (:fid, :sid, 2025, 1, :d, 'PASS', :notes, 23.0225, 72.5714, :by, NOW())")
            ->execute([
                'fid' => $fid,
                'sid' => (int)$freezer['store_id'],
                'd' => $moveDate,
                'notes' => 'SAMPLE new-store KYC (approved after move)',
                'by' => $adminId,
            ]);
        echo "New-store KYC direct insert: {$e->getMessage()}\n";
    }
    echo "Added store move + KYC new store @ {$moveDate} → {$created['outlet_id']}\n";
} else {
    echo "New-store KYC sample already exists\n";
}

$freezer = Freezer::findById($fid);

// --- 4) Notifications: rejected + approved examples (and refresh pending samples) ---
AppNotification::ensureTable();
$pdo->prepare("DELETE FROM app_notifications WHERE freezer_id = :fid AND title LIKE 'SAMPLE —%'")
    ->execute(['fid' => $fid]);

// Rejected same-store request (no KYC record — already the case)
$idReject = AppNotification::create([
    'type' => AppNotification::TYPE_KYC_SAME_STORE,
    'status' => AppNotification::STATUS_PENDING,
    'freezer_id' => $fid,
    'unique_freezer_id' => $ufid,
    'title' => 'SAMPLE — rejected KYC (demo)',
    'message' => 'Sample same-store KYC that admin rejected — no profile KYC record.',
    'created_by' => $adminId,
    'payload' => [
        'kyc' => [
            'verified_at' => date('Y-m-d', strtotime('-10 days')),
            'cycle_year' => (int)date('Y'),
            'cycle_half' => ((int)date('n') <= 6) ? 1 : 2,
            'result' => 'PASS',
            'notes' => 'SAMPLE rejected — should not appear in History KYC',
            'verified_by' => $adminId,
        ],
        'from_store' => $fromStore,
    ],
]);
AppNotification::reject($idReject, $adminId ?: 1, 'SAMPLE reject reason — photos unclear');
echo "Notification rejected id={$idReject}\n";

// Approved same-store notification (already applied to profile conceptually — status only)
$idApproved = AppNotification::create([
    'type' => AppNotification::TYPE_KYC_SAME_STORE,
    'status' => AppNotification::STATUS_PENDING,
    'freezer_id' => $fid,
    'unique_freezer_id' => $ufid,
    'title' => 'SAMPLE — approved KYC (demo)',
    'message' => 'Sample notification marked approved (demo). History already has same-store KYC row.',
    'created_by' => $adminId,
    'payload' => [
        'kyc' => [
            'verified_at' => $sameDate,
            'cycle_year' => 2024,
            'cycle_half' => 2,
            'result' => 'PASS',
            'notes' => 'SAMPLE approved notification mirror',
            'verified_by' => $adminId,
        ],
        'from_store' => $fromStore,
    ],
]);
AppNotification::setStatus($idApproved, AppNotification::STATUS_APPROVED, $adminId ?: 1, 'SAMPLE approved');
echo "Notification approved id={$idApproved}\n";

// One pending for live testing (new store request — not applied)
AppNotification::create([
    'type' => AppNotification::TYPE_KYC_STORE_MOVE,
    'freezer_id' => $fid,
    'unique_freezer_id' => $ufid,
    'title' => 'SAMPLE — pending new store',
    'message' => 'Pending move request for testing Approve/Reject on Notifications.',
    'created_by' => $adminId,
    'payload' => [
        'store' => [
            'store_name' => 'SAMPLE Pending Outlet',
            'address' => '99 Pending Test Lane',
            'city' => $freezer['store_city'] ?: 'Ahmedabad',
            'state' => $freezer['store_state'] ?: 'Gujarat',
            'pincode' => preg_match('/^\d{6}$/', (string)($freezer['store_pincode'] ?? '')) ? $freezer['store_pincode'] : '380058',
            'zone' => $freezer['zone'] ?? 'WEST',
            'contact_person' => 'Pending Manager',
            'phone_number' => '9876500099',
            'customer_name' => 'Baskin Robbins',
            'latitude' => '23.03',
            'longitude' => '72.58',
            'location_link' => 'https://maps.google.com/?q=23.03,72.58',
        ],
        'kyc' => [
            'verified_at' => date('Y-m-d'),
            'cycle_year' => (int)date('Y'),
            'cycle_half' => ((int)date('n') <= 6) ? 1 : 2,
            'result' => 'PASS',
            'notes' => 'SAMPLE pending new-store KYC',
            'verified_by' => $adminId,
        ],
        'from_store' => [
            'store_id' => (int)$freezer['store_id'],
            'outlet_id' => $freezer['outlet_id'] ?? null,
            'store_name' => $freezer['store_name'] ?? null,
        ],
    ],
]);
echo "Notification pending new-store created\n";

$log = FreezerLifecycle::buildActivityLog(Freezer::findById($fid));
echo "\nActivity rows (" . count($log) . "):\n";
foreach ($log as $r) {
    echo " - {$r['date']} | {$r['activity']} | {$r['store_name']} | {$r['result']}\n";
}
echo "\nPending notifications: " . AppNotification::countPending() . "\n";
echo "OK\n";
