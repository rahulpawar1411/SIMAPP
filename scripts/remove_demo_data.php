<?php
/**
 * Remove DEMO AMC history profile(s) and related store / KYC / tag links.
 * Keeps real profiles (Chandrakant, Sheraton, …).
 *
 * Run: php scripts/remove_demo_data.php
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$demoFreezers = $pdo->query("
    SELECT f.id, f.unique_freezer_id, f.store_id, f.notes, s.store_name
    FROM freezers f
    LEFT JOIN stores s ON s.id = f.store_id
    WHERE f.deleted_at IS NULL
      AND (
        f.notes LIKE '%DEMO AMC%'
        OR s.store_name LIKE 'Demo AMC%'
        OR s.store_name LIKE 'Dummy Store%'
        OR f.unique_freezer_id LIKE '%DUMMY%'
        OR s.email LIKE 'demo.amc.%'
      )
")->fetchAll(PDO::FETCH_ASSOC);

$demoStores = $pdo->query("
    SELECT id, outlet_id, store_name
    FROM stores
    WHERE store_name LIKE 'Demo AMC%'
       OR store_name LIKE 'Dummy Store%'
       OR email LIKE 'demo.amc.%'
       OR outlet_id LIKE 'DUMMY%'
")->fetchAll(PDO::FETCH_ASSOC);

if (!$demoFreezers && !$demoStores) {
    echo "No demo data found.\n";
    exit(0);
}

echo "Demo freezers: " . count($demoFreezers) . "\n";
echo "Demo stores: " . count($demoStores) . "\n";

$storeIds = [];
foreach ($demoStores as $s) {
    $storeIds[(int)$s['id']] = true;
}
foreach ($demoFreezers as $f) {
    if (!empty($f['store_id'])) {
        $storeIds[(int)$f['store_id']] = true;
    }
}

$pdo->beginTransaction();
try {
    foreach ($demoFreezers as $f) {
        $fid = (int)$f['id'];
        echo "Removing freezer #{$fid} {$f['unique_freezer_id']} ({$f['store_name']})\n";

        $pdo->prepare("DELETE FROM kyc_verifications WHERE freezer_id = :fid")->execute(['fid' => $fid]);
        try {
            $pdo->prepare("DELETE FROM freezer_placements WHERE freezer_id = :fid")->execute(['fid' => $fid]);
        } catch (Throwable $e) { /* optional table */ }
        try {
            $pdo->prepare("DELETE FROM iot_telemetry WHERE device_id IN (SELECT id FROM iot_devices WHERE freezer_id = :fid)")->execute(['fid' => $fid]);
            $pdo->prepare("DELETE FROM iot_devices WHERE freezer_id = :fid")->execute(['fid' => $fid]);
        } catch (Throwable $e) { /* optional */ }
        try {
            $pdo->prepare("DELETE FROM audit_logs WHERE entity_id = :fid AND (event LIKE 'freezer.%' OR action LIKE '%PROFILE%' OR action LIKE '%FREEZER%')")->execute(['fid' => $fid]);
        } catch (Throwable $e) { /* optional */ }

        $pdo->prepare("UPDATE asset_tags SET freezer_id = NULL, status = 'unused', assigned_at = NULL WHERE freezer_id = :fid")
            ->execute(['fid' => $fid]);
        $pdo->prepare("DELETE FROM freezers WHERE id = :fid")->execute(['fid' => $fid]);
    }

    // Also remove any freezers still pointing at demo stores
    foreach (array_keys($storeIds) as $sid) {
        $left = $pdo->prepare("SELECT id, unique_freezer_id FROM freezers WHERE store_id = :sid");
        $left->execute(['sid' => $sid]);
        foreach ($left->fetchAll(PDO::FETCH_ASSOC) as $lf) {
            $fid = (int)$lf['id'];
            echo "Removing leftover freezer #{$fid} {$lf['unique_freezer_id']} on demo store #{$sid}\n";
            $pdo->prepare("DELETE FROM kyc_verifications WHERE freezer_id = :fid")->execute(['fid' => $fid]);
            try {
                $pdo->prepare("DELETE FROM freezer_placements WHERE freezer_id = :fid")->execute(['fid' => $fid]);
            } catch (Throwable $e) { /* */ }
            $pdo->prepare("UPDATE asset_tags SET freezer_id = NULL, status = 'unused', assigned_at = NULL WHERE freezer_id = :fid")
                ->execute(['fid' => $fid]);
            $pdo->prepare("DELETE FROM freezers WHERE id = :fid")->execute(['fid' => $fid]);
        }
        echo "Removing store #{$sid}\n";
        $pdo->prepare("DELETE FROM stores WHERE id = :sid")->execute(['sid' => $sid]);
    }

    $pdo->commit();
    echo "Done. Demo data removed.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

$remain = $pdo->query("
    SELECT f.id, f.unique_freezer_id, s.store_name
    FROM freezers f LEFT JOIN stores s ON s.id = f.store_id
    WHERE f.deleted_at IS NULL ORDER BY f.id
")->fetchAll(PDO::FETCH_ASSOC);
echo "Remaining freezers:\n" . json_encode($remain, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
