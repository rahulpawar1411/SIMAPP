<?php
/**
 * One rich demo profile with Store History + AMC verify history (photos + outlet change).
 *
 * Run: php scripts/seed_demo_amc_history_profile.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\AppConfig;
use App\Config\Database;
use App\Models\AssetTag;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Models\Store;
use App\Services\FreezerIdGenerator;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
AppConfig::initUploadDirs();
AssetTag::ensureTable();
Freezer::ensureSimColumns();

$marker = 'DEMO AMC HISTORY PROFILE — do not delete';

$adminId = null;
$adminName = 'Demo Admin';
try {
    $u = $pdo->query("SELECT id, name FROM users WHERE role = 'super_admin' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $adminId = (int)$u['id'];
        $adminName = trim((string)($u['name'] ?? 'Demo Admin')) ?: 'Demo Admin';
    }
} catch (Throwable $e) {
}

$copyImg = static function (string $srcRel, string $destRel): string {
    $root = dirname(__DIR__);
    $src = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $srcRel);
    $dest = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $destRel);
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (!is_file($src)) {
        throw new RuntimeException('Missing source image: ' . $srcRel);
    }
    if (!@copy($src, $dest)) {
        throw new RuntimeException('Could not copy image to ' . $destRel);
    }
    return str_replace('\\', '/', $destRel);
};

// Re-run: remove previous demo freezer (+ related KYC / placements stay via cascade where set)
$existing = $pdo->prepare("SELECT id, unique_freezer_id, store_id FROM freezers WHERE notes = :n AND deleted_at IS NULL LIMIT 1");
$existing->execute(['n' => $marker]);
$old = $existing->fetch(PDO::FETCH_ASSOC);
if ($old) {
    $fid = (int)$old['id'];
    echo "Removing previous demo freezer #{$fid} ({$old['unique_freezer_id']})…\n";
    $pdo->prepare('DELETE FROM kyc_verifications WHERE freezer_id = :fid')->execute(['fid' => $fid]);
    $pdo->prepare('DELETE FROM freezer_placements WHERE freezer_id = :fid')->execute(['fid' => $fid]);
    try {
        $pdo->prepare("UPDATE asset_tags SET freezer_id = NULL, status = 'unused', assigned_at = NULL WHERE freezer_id = :fid")->execute(['fid' => $fid]);
    } catch (Throwable $e) {
    }
    $pdo->prepare('DELETE FROM freezers WHERE id = :fid')->execute(['fid' => $fid]);
}

$storeFrontA = $copyImg(
    'uploads/stores/chandrakant_sakinaka_storefront.jpg',
    'uploads/stores/demo_amc_store_a_front.jpg'
);
$storeFrontB = $copyImg(
    'uploads/stores/sheraton_traders_khar_storefront.jpg',
    'uploads/stores/demo_amc_store_b_front.jpg'
);
$frzA1 = $copyImg(
    'uploads/freezers/chandrakant_sakinaka_freezer_full.jpg',
    'uploads/freezers/demo_amc_frz_a1.jpg'
);
$frzA2 = $copyImg(
    'uploads/freezers/frz_left_1793361071_d046ec87ac9df6282afc.jpg',
    'uploads/freezers/demo_amc_frz_a2.jpg'
);
$frzB1 = $copyImg(
    'uploads/freezers/RO-Q7H6UE-20261106-Zafran---Lower-Parel-Outlet-KYC-Freezer.jpg',
    'uploads/freezers/demo_amc_frz_b1.jpg'
);
$frzB2 = $copyImg(
    'uploads/freezers/frz_left_1793360059_8c42f2433253c37e7ae1.jpg',
    'uploads/freezers/demo_amc_frz_b2.jpg'
);

// Store A — Andheri (initial)
$outletA = Store::generateOutletId([
    'city' => 'Mumbai',
    'zone' => 'WEST',
    'pincode' => '400053',
    'location_type' => 'Retail Store',
]);
$storeA = Store::create([
    'outlet_id' => $outletA,
    'store_name' => 'Demo AMC — Andheri West Mart',
    'customer_name' => 'Baskin Robbins',
    'distributor_name' => 'West Dist Mumbai',
    'contact_person' => 'Priya Shah',
    'phone_number' => '9876511001',
    'email' => 'demo.amc.andheri@example.com',
    'aadhar_number' => '999988887777',
    'fssai_number' => '10012021999901',
    'gst_number' => '27DEMOP0000A1Z5',
    'address' => 'Shop 4, SV Road, Andheri West',
    'city' => 'Mumbai',
    'state' => 'Maharashtra',
    'pincode' => '400053',
    'zone' => 'WEST',
    'location_type' => 'Retail Store',
    'latitude' => 19.1364,
    'longitude' => 72.8277,
    'gps_accuracy' => 10,
    'image_store_front' => $storeFrontA,
    'status' => 'Active',
    'created_by' => $adminId,
]);

$gen = FreezerIdGenerator::generateUniqueId('DF', 'RW', 'SFR550DDU', 'MUM1', '400053', '0124', null);
$uniqueId = $gen['freezer_id'];

$batch = 'DEMO-AMC-' . date('Ymd-His');
$tags = AssetTag::createBatch(1, $adminId, $batch, 'WEST');
$tagCode = $tags[0]['tag_code'] ?? '';

$freezerId = Freezer::create([
    'unique_freezer_id' => $uniqueId,
    'store_id' => $storeA,
    'freezer_type' => 'DF',
    'company_code' => 'RW',
    'model_number' => 'SFR550DDU',
    'capacity_litres' => 550,
    'freezer_condition' => 'WORKING CONDITION',
    'location_code' => 'MUM1',
    'pincode' => '400053',
    'install_month_year' => '0124',
    'unit_sequence' => $gen['unit_sequence'],
    'installation_date' => '2024-01-15',
    'status' => 'Active',
    'notes' => $marker,
    'sim_number' => '89919225034008649999',
    'sim_company' => 'Airtel',
    'image_store_front' => $storeFrontA,
    'image_left_side' => $frzA1,
    'asset_tag_code' => $tagCode,
    'created_by' => $adminId,
    'created_by_name' => $adminName,
]);

echo "Created freezer #{$freezerId} · {$uniqueId} · tag {$tagCode}\n";
echo "Store A #{$storeA} · {$outletA}\n";

// Direct AMC inserts (bypass gap rules for historical seed)
$insKyc = $pdo->prepare(
    "INSERT INTO kyc_verifications
    (freezer_id, store_id, cycle_year, cycle_half, verified_at, result, notes,
     image_store_front, image_freezer, latitude, longitude, verified_by, created_at)
    VALUES
    (:fid, :sid, :y, :h, :vdate, :result, :notes,
     :img_s, :img_f, :lat, :lng, :by, NOW())"
);

// AMC 1 — same outlet (Andheri)
$insKyc->execute([
    'fid' => $freezerId,
    'sid' => $storeA,
    'y' => 2024,
    'h' => 1,
    'vdate' => '2024-02-20 11:30:00',
    'result' => 'PASS',
    'notes' => 'First AMC — same outlet verify at Andheri. Freezer OK, compressor normal.',
    'img_s' => $storeFrontA,
    'img_f' => $frzA1,
    'lat' => 19.1364,
    'lng' => 72.8277,
    'by' => $adminId,
]);
echo "AMC #1 PASS @ Andheri (same outlet) 2024-02-20\n";

// Store B — Bandra (outlet change)
$outletB = Store::generateOutletId([
    'city' => 'Mumbai',
    'zone' => 'WEST',
    'pincode' => '400050',
    'location_type' => 'Retail Store',
]);
$storeB = Store::create([
    'outlet_id' => $outletB,
    'store_name' => 'Demo AMC — Bandra Linking Road',
    'customer_name' => 'Baskin Robbins',
    'distributor_name' => 'West Dist Mumbai',
    'contact_person' => 'Rahul Mehta',
    'phone_number' => '9876511002',
    'email' => 'demo.amc.bandra@example.com',
    'aadhar_number' => '999988887778',
    'fssai_number' => '10012021999902',
    'gst_number' => '27DEMOP0000B1Z5',
    'address' => '15, Linking Road, Bandra West',
    'city' => 'Mumbai',
    'state' => 'Maharashtra',
    'pincode' => '400050',
    'zone' => 'WEST',
    'location_type' => 'Retail Store',
    'latitude' => 19.0596,
    'longitude' => 72.8295,
    'gps_accuracy' => 8,
    'image_store_front' => $storeFrontB,
    'status' => 'Active',
    'created_by' => $adminId,
]);

FreezerLifecycle::transfer(
    $freezerId,
    $storeB,
    'AMC outlet change — moved to Bandra',
    'Demo transfer Andheri → Bandra',
    $adminId,
    '2025-03-10'
);
echo "Transferred → Store B #{$storeB} · {$outletB}\n";

// AMC 2 — after outlet change
$insKyc->execute([
    'fid' => $freezerId,
    'sid' => $storeB,
    'y' => 2025,
    'h' => 1,
    'vdate' => '2025-03-12 15:45:00',
    'result' => 'PASS',
    'notes' => 'AMC after outlet change → ' . $outletB . ' · New store verify at Bandra Linking Road.',
    'img_s' => $storeFrontB,
    'img_f' => $frzB1,
    'lat' => 19.0596,
    'lng' => 72.8295,
    'by' => $adminId,
]);
echo "AMC #2 PASS @ Bandra (outlet changed) 2025-03-12\n";

// AMC 3 — same Bandra outlet later (FAIL for variety)
$insKyc->execute([
    'fid' => $freezerId,
    'sid' => $storeB,
    'y' => 2026,
    'h' => 1,
    'vdate' => '2026-04-05 10:15:00',
    'result' => 'FAIL',
    'notes' => 'Same outlet verify — door gasket leak noted. Follow-up required.',
    'img_s' => $storeFrontB,
    'img_f' => $frzB2,
    'lat' => 19.0598,
    'lng' => 72.8297,
    'by' => $adminId,
]);
echo "AMC #3 FAIL @ Bandra (same outlet) 2026-04-05\n";

// Extra same-store photo set for AMC1 variety already done; add one more early placement note via second AMC image path unused

$profileUrl = 'profile_view.php?id=' . rawurlencode($uniqueId);
$amcUrl = $profileUrl . '&tab=amc';
$histUrl = $profileUrl . '&tab=history';

echo "\nDone.\n";
echo "Unique Freezer ID: {$uniqueId}\n";
echo "Tag: {$tagCode}\n";
echo "Store profile: {$profileUrl}\n";
echo "AMC history:   {$amcUrl}\n";
echo "History:       {$histUrl}\n";
echo "Search in Assets for: Demo AMC\n";
