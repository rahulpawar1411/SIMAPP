<?php
/**
 * Insert 5 dummy store + freezer profiles for demo / UI testing.
 * Safe to re-run: skips rows whose Unique Freezer ID already exists.
 *
 * Run: php scripts/seed_5_dummy_profiles.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Models\Store;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Models\AssetTag;
use App\Services\FreezerIdGenerator;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$adminId = null;
$adminName = 'Demo Admin';
try {
    $u = $pdo->query("SELECT id, name FROM users WHERE role = 'super_admin' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $adminId = (int)$u['id'];
        $adminName = trim((string)($u['name'] ?? 'Demo Admin')) ?: 'Demo Admin';
    }
} catch (Throwable $e) {
    // continue without created_by
}

AssetTag::ensureTable();

$samples = [
    [
        'store_name' => 'Dummy Store — CG Road Hub',
        'outlet_hint' => 'DUMMY1',
        'customer_name' => 'Baskin Robbins',
        'distributor_name' => 'West Dist Ahmedabad',
        'contact_person' => 'Ravi Patel',
        'phone_number' => '9876500001',
        'email' => 'dummy1.store@example.com',
        'aadhar_number' => '123456789001',
        'fssai_number' => '10012021000001',
        'gst_number' => '24AAAAA0000A1Z5',
        'address' => 'Shop 12, CG Road, Navrangpura',
        'city' => 'Ahmedabad',
        'state' => 'Gujarat',
        'pincode' => '380009',
        'zone' => 'WEST',
        'location_type' => 'Retail Store',
        'latitude' => 23.0225,
        'longitude' => 72.5714,
        'location_code' => 'AMD1',
        'freezer_type' => 'DF',
        'company_code' => 'RW',
        'model_number' => 'SFR550DDU',
        'capacity_litres' => 550,
        'install_month_year' => '0325',
        'installation_date' => '2025-03-12',
        'notes' => 'DUMMY sample #1 — West / Ahmedabad',
        'kyc_result' => 'PASS',
        'kyc_at' => '2025-09-15',
    ],
    [
        'store_name' => 'Dummy Store — Koregaon Park',
        'outlet_hint' => 'DUMMY2',
        'customer_name' => 'Baskin Robbins',
        'distributor_name' => 'South Dist Pune',
        'contact_person' => 'Sneha Deshmukh',
        'phone_number' => '9876500002',
        'email' => 'dummy2.store@example.com',
        'aadhar_number' => '123456789002',
        'fssai_number' => '10012021000002',
        'gst_number' => '27BBBBB0000B1Z5',
        'address' => 'Lane 5, Koregaon Park',
        'city' => 'Pune',
        'state' => 'Maharashtra',
        'pincode' => '411001',
        'zone' => 'SOUTH',
        'location_type' => 'Retail Store',
        'latitude' => 18.5362,
        'longitude' => 73.8939,
        'location_code' => 'PUN1',
        'freezer_type' => 'DF',
        'company_code' => 'RW',
        'model_number' => 'SFR400',
        'capacity_litres' => 400,
        'install_month_year' => '0525',
        'installation_date' => '2025-05-08',
        'notes' => 'DUMMY sample #2 — South / Pune',
        'kyc_result' => 'PASS',
        'kyc_at' => '2025-10-01',
    ],
    [
        'store_name' => 'Dummy Store — Connaught Place',
        'outlet_hint' => 'DUMMY3',
        'customer_name' => 'Baskin Robbins',
        'distributor_name' => 'North Dist Delhi',
        'contact_person' => 'Amit Sharma',
        'phone_number' => '9876500003',
        'email' => 'dummy3.store@example.com',
        'aadhar_number' => '123456789003',
        'fssai_number' => '10012021000003',
        'gst_number' => '07CCCCC0000C1Z5',
        'address' => 'Block B, Connaught Place',
        'city' => 'New Delhi',
        'state' => 'Delhi',
        'pincode' => '110001',
        'zone' => 'NORTH',
        'location_type' => 'Commercial Store',
        'latitude' => 28.6315,
        'longitude' => 77.2167,
        'location_code' => 'DEL1',
        'freezer_type' => 'DF',
        'company_code' => 'RW',
        'model_number' => 'SFR550DDU',
        'capacity_litres' => 550,
        'install_month_year' => '0125',
        'installation_date' => '2025-01-20',
        'notes' => 'DUMMY sample #3 — North / Delhi · AMC due soon',
        'kyc_result' => null,
        'kyc_at' => null,
    ],
    [
        'store_name' => 'Dummy Store — Salt Lake Sector V',
        'outlet_hint' => 'DUMMY4',
        'customer_name' => 'Baskin Robbins',
        'distributor_name' => 'East Dist Kolkata',
        'contact_person' => 'Priya Banerjee',
        'phone_number' => '9876500004',
        'email' => 'dummy4.store@example.com',
        'aadhar_number' => '123456789004',
        'fssai_number' => '10012021000004',
        'gst_number' => '19DDDDD0000D1Z5',
        'address' => 'Plot 42, Sector V, Salt Lake',
        'city' => 'Kolkata',
        'state' => 'West Bengal',
        'pincode' => '700091',
        'zone' => 'EAST',
        'location_type' => 'Retail Store',
        'latitude' => 22.5769,
        'longitude' => 88.4339,
        'location_code' => 'KOL1',
        'freezer_type' => 'DF',
        'company_code' => 'RW',
        'model_number' => 'SFR300',
        'capacity_litres' => 300,
        'install_month_year' => '0725',
        'installation_date' => '2025-07-04',
        'notes' => 'DUMMY sample #4 — East / Kolkata · FAIL sample',
        'kyc_result' => 'FAIL',
        'kyc_at' => '2025-10-20',
    ],
    [
        'store_name' => 'Dummy Store — SG Highway Mall',
        'outlet_hint' => 'DUMMY5',
        'customer_name' => 'Baskin Robbins',
        'distributor_name' => 'West Dist Ahmedabad',
        'contact_person' => 'Kiran Mehta',
        'phone_number' => '9876500005',
        'email' => 'dummy5.store@example.com',
        'aadhar_number' => '123456789005',
        'fssai_number' => '10012021000005',
        'gst_number' => '24EEEEE0000E1Z5',
        'address' => 'Food Court, SG Highway Mall',
        'city' => 'Ahmedabad',
        'state' => 'Gujarat',
        'pincode' => '380054',
        'zone' => 'WEST',
        'location_type' => 'Mall Kiosk',
        'latitude' => 23.0401,
        'longitude' => 72.5085,
        'location_code' => 'AMD2',
        'freezer_type' => 'DF',
        'company_code' => 'RW',
        'model_number' => 'SFR550DDU',
        'capacity_litres' => 550,
        'install_month_year' => '0925',
        'installation_date' => '2025-09-18',
        'notes' => 'DUMMY sample #5 — West / Ahmedabad mall',
        'kyc_result' => 'PASS',
        'kyc_at' => '2025-11-01',
    ],
];

$created = 0;
$skipped = 0;

foreach ($samples as $i => $row) {
    $n = $i + 1;
    echo "\n--- Dummy #{$n}: {$row['store_name']} ---\n";

    try {
        $gen = FreezerIdGenerator::generateUniqueId(
            $row['freezer_type'],
            $row['company_code'],
            $row['model_number'],
            $row['location_code'],
            $row['pincode'],
            $row['install_month_year'],
            null
        );
        $uniqueId = $gen['freezer_id'];

        // Idempotent: if a freezer with same notes marker already exists, skip
        $chk = $pdo->prepare("SELECT id, unique_freezer_id FROM freezers WHERE notes = :n AND deleted_at IS NULL LIMIT 1");
        $chk->execute(['n' => $row['notes']]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            echo "Skip (already exists): {$existing['unique_freezer_id']}\n";
            $skipped++;
            continue;
        }

        $outletId = Store::generateOutletId([
            'city' => $row['city'],
            'zone' => $row['zone'],
            'pincode' => $row['pincode'],
            'location_type' => $row['location_type'],
        ]);

        $storeId = Store::create([
            'outlet_id' => $outletId,
            'store_name' => $row['store_name'],
            'customer_name' => $row['customer_name'],
            'distributor_name' => $row['distributor_name'],
            'contact_person' => $row['contact_person'],
            'phone_number' => $row['phone_number'],
            'email' => $row['email'],
            'aadhar_number' => $row['aadhar_number'],
            'fssai_number' => $row['fssai_number'],
            'gst_number' => $row['gst_number'],
            'address' => $row['address'],
            'city' => $row['city'],
            'state' => $row['state'],
            'pincode' => $row['pincode'],
            'zone' => $row['zone'],
            'location_type' => $row['location_type'],
            'latitude' => $row['latitude'],
            'longitude' => $row['longitude'],
            'gps_accuracy' => 12,
            'status' => 'Active',
            'created_by' => $adminId,
        ]);

        $zoneTag = match ($row['zone']) {
            'SOUTH' => 'SOUTH',
            'NORTH' => 'NORTH',
            'EAST' => 'EAST',
            default => 'WEST',
        };
        $batch = 'DUMMY-' . $zoneTag . '-' . date('Ymd-His') . '-' . $n;
        $tags = AssetTag::createBatch(1, $adminId, $batch, $zoneTag);
        $tagCode = $tags[0]['tag_code'] ?? '';

        $freezerId = Freezer::create([
            'unique_freezer_id' => $uniqueId,
            'store_id' => $storeId,
            'freezer_type' => $row['freezer_type'],
            'company_code' => $row['company_code'],
            'model_number' => $row['model_number'],
            'capacity_litres' => $row['capacity_litres'],
            'freezer_condition' => 'WORKING CONDITION',
            'location_code' => $row['location_code'],
            'pincode' => $row['pincode'],
            'install_month_year' => $row['install_month_year'],
            'unit_sequence' => $gen['unit_sequence'],
            'installation_date' => $row['installation_date'],
            'status' => 'Active',
            'notes' => $row['notes'],
            'sim_number' => '99999' . str_pad((string)$n, 5, '0', STR_PAD_LEFT),
            'sim_company' => 'Airtel',
            'asset_tag_code' => $tagCode,
            'created_by' => $adminId,
            'created_by_name' => $adminName,
        ]);

        $freezer = Freezer::findById($freezerId);

        if (!empty($row['kyc_result']) && !empty($row['kyc_at']) && $freezer) {
            try {
                FreezerLifecycle::recordKyc([
                    'freezer_id' => $freezerId,
                    'verified_at' => $row['kyc_at'],
                    'result' => $row['kyc_result'],
                    'notes' => 'DUMMY seed AMC #' . $n,
                    'image_store_front' => null,
                    'image_freezer' => null,
                    'latitude' => $row['latitude'],
                    'longitude' => $row['longitude'],
                    'verified_by' => $adminId,
                ]);
            } catch (Throwable $e) {
                // Direct insert if gap rule blocks
                $ins = $pdo->prepare(
                    "INSERT INTO kyc_verifications
                    (freezer_id, store_id, cycle_year, cycle_half, verified_at, result, notes, verified_by, created_at)
                    VALUES (:fid, :sid, :y, 1, :d, :r, :notes, :by, NOW())"
                );
                $ins->execute([
                    'fid' => $freezerId,
                    'sid' => $storeId,
                    'y' => (int)substr((string)$row['kyc_at'], 0, 4),
                    'd' => $row['kyc_at'],
                    'r' => $row['kyc_result'],
                    'notes' => 'DUMMY seed AMC #' . $n,
                    'by' => $adminId,
                ]);
            }
            echo "AMC {$row['kyc_result']} @ {$row['kyc_at']}\n";
        }

        echo "OK store #{$storeId} ({$outletId}) · freezer #{$freezerId} · {$uniqueId} · tag {$tagCode}\n";
        $created++;
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED #{$n}: " . $e->getMessage() . "\n");
    }
}

echo "\nDone. Created: {$created} · Skipped: {$skipped}\n";
echo "Open Profiles in the app to see the 5 Dummy Store rows.\n";
exit($created > 0 || $skipped > 0 ? 0 : 1);
