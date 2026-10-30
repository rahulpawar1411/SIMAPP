<?php
namespace App\Models;

use App\Config\Database;
use Exception;
use PDO;

/**
 * Freezer can move between stores (same owner / other outlet).
 * Unique Freezer ID stays fixed. KYC runs twice per year (H1 / H2).
 */
class FreezerLifecycle {

    public static function currentCycle(?string $date = null): array {
        $ts = $date ? strtotime($date) : time();
        $year = (int)date('Y', $ts);
        $month = (int)date('n', $ts);
        $half = $month <= 6 ? 1 : 2;
        return [
            'year' => $year,
            'half' => $half,
            'label' => $year . '-H' . $half,
            'window' => $half === 1 ? 'Jan–Jun' : 'Jul–Dec',
        ];
    }

    public static function openPlacement(int $freezerId, int $storeId, string $startedAt, ?int $createdBy, string $reason = 'Initial placement', ?string $notes = null): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO `freezer_placements`
            (`freezer_id`, `store_id`, `started_at`, `ended_at`, `transfer_reason`, `notes`, `created_by`)
            VALUES (:fid, :sid, :start, NULL, :reason, :notes, :by)");
        $stmt->execute([
            'fid' => $freezerId,
            'sid' => $storeId,
            'start' => $startedAt,
            'reason' => $reason,
            'notes' => $notes,
            'by' => $createdBy,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function ensureOpenPlacement(array $freezer): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT `id` FROM `freezer_placements`
            WHERE `freezer_id` = :fid AND `ended_at` IS NULL LIMIT 1");
        $stmt->execute(['fid' => (int)$freezer['id']]);
        if ($stmt->fetch()) {
            return;
        }
        self::openPlacement(
            (int)$freezer['id'],
            (int)$freezer['store_id'],
            $freezer['installation_date'] ?? date('Y-m-d'),
            $freezer['created_by'] ?? null,
            'Initial placement',
            'Auto-opened'
        );
    }

    /**
     * Create a brand-new outlet (auto outlet_id) then optionally use it for transfer/KYC.
     * Returns ['store_id' => int, 'outlet_id' => string].
     */
    public static function createNewOutlet(array $data, int $createdBy): array {
        $storeName = trim($data['store_name'] ?? '');
        $address = trim($data['address'] ?? '');
        $city = trim($data['city'] ?? '');
        $pincode = preg_replace('/\D/', '', (string)($data['pincode'] ?? ''));

        if ($storeName === '' || $address === '' || $city === '') {
            throw new Exception('New outlet needs store name, address and city.');
        }
        if (!preg_match('/^\d{6}$/', $pincode)) {
            throw new Exception('New outlet PIN must be 6 digits.');
        }

        $outletId = Store::generateOutletId([
            'city' => $city,
            'pincode' => $pincode,
            'location_code' => $data['location_code'] ?? '',
        ]);

        $storeId = Store::create([
            'outlet_id' => $outletId,
            'store_name' => $storeName,
            'customer_name' => $data['customer_name'] ?? 'Baskin Robbins',
            'distributor_name' => $data['distributor_name'] ?? '',
            'contact_person' => $data['contact_person'] ?? '',
            'phone_number' => $data['phone_number'] ?? '',
            'email' => $data['email'] ?? '',
            'aadhar_number' => $data['aadhar_number'] ?? '',
            'fssai_number' => $data['fssai_number'] ?? '',
            'gst_number' => $data['gst_number'] ?? '',
            'address' => $address,
            'city' => $city,
            'state' => trim($data['state'] ?? '') !== '' ? trim($data['state']) : 'Unknown',
            'pincode' => $pincode,
            'zone' => strtoupper(trim($data['zone'] ?? 'WEST')) ?: 'WEST',
            'location_type' => $data['location_type'] ?? 'Retail Store',
            'latitude' => ($data['latitude'] ?? '') !== '' ? (float)$data['latitude'] : null,
            'longitude' => ($data['longitude'] ?? '') !== '' ? (float)$data['longitude'] : null,
            'image_store_front' => $data['image_store_front'] ?? null,
            'image_aadhar_card' => $data['image_aadhar_card'] ?? null,
            'status' => 'Active',
            'created_by' => $createdBy,
        ]);

        return [
            'store_id' => $storeId,
            'outlet_id' => $outletId,
        ];
    }

    /**
     * Move freezer to another store. Unique Freezer ID does not change.
     */
    public static function transfer(int $freezerId, int $newStoreId, string $reason, ?string $notes, ?int $userId, ?string $transferDate = null): void {
        $pdo = Database::getConnection();
        $freezer = Freezer::findById($freezerId);
        if (!$freezer) {
            throw new Exception('Freezer not found.');
        }
        if ((int)$freezer['store_id'] === $newStoreId) {
            throw new Exception('Freezer is already at this store.');
        }
        $newStore = Store::findById($newStoreId);
        if (!$newStore) {
            throw new Exception('Target store not found.');
        }
        if (($newStore['status'] ?? 'Active') === 'Inactive') {
            throw new Exception('Target store is inactive.');
        }

        $date = $transferDate ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('Invalid transfer date.');
        }

        self::ensureOpenPlacement($freezer);

        $pdo->beginTransaction();
        try {
            $close = $pdo->prepare("UPDATE `freezer_placements`
                SET `ended_at` = :end
                WHERE `freezer_id` = :fid AND `ended_at` IS NULL");
            $close->execute(['end' => $date, 'fid' => $freezerId]);

            self::openPlacement(
                $freezerId,
                $newStoreId,
                $date,
                $userId,
                $reason !== '' ? $reason : 'Store transfer',
                $notes
            );

            $upd = $pdo->prepare("UPDATE `freezers` SET `store_id` = :sid WHERE `id` = :id");
            $upd->execute(['sid' => $newStoreId, 'id' => $freezerId]);

            Freezer::logAudit(
                'TRANSFER_STORE',
                $freezerId,
                $freezer['unique_freezer_id'],
                "Moved from store #{$freezer['store_id']} ({$freezer['outlet_id']}) to #{$newStoreId} ({$newStore['outlet_id']}): {$reason}"
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function placementHistory(int $freezerId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT p.*,
                s.store_name, s.outlet_id, s.city, s.pincode, s.contact_person, s.phone_number
            FROM `freezer_placements` p
            INNER JOIN `stores` s ON s.id = p.store_id
            WHERE p.freezer_id = :fid
            ORDER BY p.started_at DESC, p.id DESC");
        $stmt->execute(['fid' => $freezerId]);
        return $stmt->fetchAll();
    }

    public static function recordKyc(array $data): int {
        $freezerId = (int)($data['freezer_id'] ?? 0);
        $freezer = Freezer::findById($freezerId);
        if (!$freezer) {
            throw new Exception('Freezer not found.');
        }

        $verifiedAt = $data['verified_at'] ?? date('Y-m-d');
        $cycle = self::currentCycle($verifiedAt);
        $year = (int)($data['cycle_year'] ?? $cycle['year']);
        $half = (int)($data['cycle_half'] ?? $cycle['half']);
        if ($half !== 1 && $half !== 2) {
            throw new Exception('KYC cycle half must be 1 (Jan–Jun) or 2 (Jul–Dec).');
        }

        $result = strtoupper(trim($data['result'] ?? 'PASS'));
        if (!in_array($result, ['PASS', 'FAIL', 'PENDING'], true)) {
            $result = 'PASS';
        }

        $pdo = Database::getConnection();
        $existing = $pdo->prepare("SELECT `id` FROM `kyc_verifications`
            WHERE `freezer_id` = :fid AND `cycle_year` = :y AND `cycle_half` = :h LIMIT 1");
        $existing->execute(['fid' => $freezerId, 'y' => $year, 'h' => $half]);
        if ($existing->fetch()) {
            throw new Exception("KYC for {$year}-H{$half} already recorded. One verification per half-year.");
        }

        $stmt = $pdo->prepare("INSERT INTO `kyc_verifications`
            (`freezer_id`, `store_id`, `cycle_year`, `cycle_half`, `verified_at`, `result`, `notes`,
             `image_store_front`, `image_freezer`, `latitude`, `longitude`, `verified_by`)
            VALUES
            (:fid, :sid, :y, :h, :vdate, :result, :notes,
             :img_s, :img_f, :lat, :lng, :by)");
        $stmt->execute([
            'fid' => $freezerId,
            'sid' => (int)$freezer['store_id'],
            'y' => $year,
            'h' => $half,
            'vdate' => $verifiedAt,
            'result' => $result,
            'notes' => $data['notes'] ?? null,
            'img_s' => $data['image_store_front'] ?? null,
            'img_f' => $data['image_freezer'] ?? null,
            'lat' => isset($data['latitude']) && $data['latitude'] !== '' ? (float)$data['latitude'] : null,
            'lng' => isset($data['longitude']) && $data['longitude'] !== '' ? (float)$data['longitude'] : null,
            'by' => $data['verified_by'] ?? null,
        ]);

        $id = (int)$pdo->lastInsertId();
        Freezer::logAudit(
            'KYC_VERIFY',
            $freezerId,
            $freezer['unique_freezer_id'],
            "KYC {$year}-H{$half} = {$result} at store #{$freezer['store_id']}"
        );
        return $id;
    }

    public static function kycHistory(int $freezerId, int $limit = 50): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT k.*,
                s.store_name, s.outlet_id, s.city, s.state, s.pincode, s.address,
                s.contact_person, s.phone_number
            FROM `kyc_verifications` k
            INNER JOIN `stores` s ON s.id = k.store_id
            WHERE k.freezer_id = :fid
            ORDER BY k.verified_at DESC, k.cycle_year DESC, k.cycle_half DESC, k.id DESC
            LIMIT :lim");
        $stmt->bindValue(':fid', $freezerId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Combined timeline: placements + KYC (newest first) — where freezer was, when, new outlets.
     */
    public static function buildTimeline(int $freezerId): array {
        $events = [];

        foreach (self::placementHistory($freezerId) as $p) {
            $isNewOutlet = stripos((string)($p['transfer_reason'] ?? ''), 'new outlet') !== false
                || stripos((string)($p['notes'] ?? ''), 'new outlet_id=') !== false
                || stripos((string)($p['transfer_reason'] ?? ''), 'auto ID') !== false;

            $events[] = [
                'type' => empty($p['ended_at']) ? 'placement_current' : 'placement',
                'sort_date' => $p['started_at'],
                'sort_key' => $p['started_at'] . '-P' . str_pad((string)$p['id'], 8, '0', STR_PAD_LEFT),
                'date' => $p['started_at'],
                'end_date' => $p['ended_at'],
                'title' => empty($p['ended_at']) ? 'Current store' : 'Placed at store',
                'outlet_id' => $p['outlet_id'],
                'store_name' => $p['store_name'],
                'city' => $p['city'] ?? '',
                'pincode' => $p['pincode'] ?? '',
                'contact_person' => $p['contact_person'] ?? '',
                'reason' => $p['transfer_reason'] ?? '',
                'notes' => $p['notes'] ?? '',
                'is_new_outlet' => $isNewOutlet,
                'is_current' => empty($p['ended_at']),
                'result' => null,
                'cycle' => null,
            ];

            if (!empty($p['ended_at'])) {
                $events[] = [
                    'type' => 'left_store',
                    'sort_date' => $p['ended_at'],
                    'sort_key' => $p['ended_at'] . '-L' . str_pad((string)$p['id'], 8, '0', STR_PAD_LEFT),
                    'date' => $p['ended_at'],
                    'end_date' => null,
                    'title' => 'Left store',
                    'outlet_id' => $p['outlet_id'],
                    'store_name' => $p['store_name'],
                    'city' => $p['city'] ?? '',
                    'pincode' => $p['pincode'] ?? '',
                    'contact_person' => $p['contact_person'] ?? '',
                    'reason' => $p['transfer_reason'] ?? '',
                    'notes' => $p['notes'] ?? '',
                    'is_new_outlet' => false,
                    'is_current' => false,
                    'result' => null,
                    'cycle' => null,
                ];
            }
        }

        foreach (self::kycHistory($freezerId, 100) as $k) {
            $events[] = [
                'type' => 'kyc',
                'sort_date' => $k['verified_at'],
                'sort_key' => $k['verified_at'] . '-K' . str_pad((string)$k['id'], 8, '0', STR_PAD_LEFT),
                'date' => $k['verified_at'],
                'end_date' => null,
                'title' => 'KYC ' . $k['cycle_year'] . '-H' . $k['cycle_half'],
                'outlet_id' => $k['outlet_id'],
                'store_name' => $k['store_name'],
                'city' => $k['city'] ?? '',
                'pincode' => $k['pincode'] ?? '',
                'contact_person' => $k['contact_person'] ?? '',
                'reason' => '',
                'notes' => $k['notes'] ?? '',
                'is_new_outlet' => false,
                'is_current' => false,
                'result' => $k['result'],
                'cycle' => $k['cycle_year'] . '-H' . $k['cycle_half'],
                'image_store_front' => $k['image_store_front'] ?? null,
                'image_freezer' => $k['image_freezer'] ?? null,
                'latitude' => $k['latitude'] ?? null,
                'longitude' => $k['longitude'] ?? null,
            ];
        }

        usort($events, fn($a, $b) => strcmp($b['sort_key'], $a['sort_key']));
        return $events;
    }

    public static function streamHistoryExport(array $freezer): void {
        $fid = (int)$freezer['id'];
        $timeline = self::buildTimeline($fid);
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $freezer['unique_freezer_id']);
        $filename = 'freezer_history_' . $safeName . '_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, [
            'unique_freezer_id',
            'event_type',
            'event_date',
            'end_date',
            'cycle',
            'result',
            'outlet_id',
            'store_name',
            'city',
            'pincode',
            'contact_person',
            'is_new_outlet',
            'is_current_store',
            'reason',
            'notes',
            'latitude',
            'longitude',
        ]);

        foreach ($timeline as $e) {
            fputcsv($out, [
                $freezer['unique_freezer_id'],
                $e['type'],
                $e['date'],
                $e['end_date'] ?? '',
                $e['cycle'] ?? '',
                $e['result'] ?? '',
                $e['outlet_id'],
                $e['store_name'],
                $e['city'],
                $e['pincode'],
                $e['contact_person'],
                !empty($e['is_new_outlet']) ? 'YES' : 'NO',
                !empty($e['is_current']) ? 'YES' : 'NO',
                $e['reason'],
                $e['notes'],
                $e['latitude'] ?? '',
                $e['longitude'] ?? '',
            ]);
        }

        // Also append raw placement + KYC detail sheets as blank separator + sections
        fputcsv($out, []);
        fputcsv($out, ['--- PLACEMENT DETAIL ---']);
        fputcsv($out, ['outlet_id', 'store_name', 'city', 'pincode', 'started_at', 'ended_at', 'transfer_reason', 'notes', 'contact_person']);
        foreach (self::placementHistory($fid) as $p) {
            fputcsv($out, [
                $p['outlet_id'],
                $p['store_name'],
                $p['city'] ?? '',
                $p['pincode'] ?? '',
                $p['started_at'],
                $p['ended_at'] ?? 'CURRENT',
                $p['transfer_reason'] ?? '',
                $p['notes'] ?? '',
                $p['contact_person'] ?? '',
            ]);
        }

        fputcsv($out, []);
        fputcsv($out, ['--- KYC DETAIL ---']);
        fputcsv($out, ['cycle', 'verified_at', 'result', 'outlet_id', 'store_name', 'city', 'pincode', 'notes', 'latitude', 'longitude']);
        foreach (self::kycHistory($fid, 100) as $k) {
            fputcsv($out, [
                $k['cycle_year'] . '-H' . $k['cycle_half'],
                $k['verified_at'],
                $k['result'],
                $k['outlet_id'],
                $k['store_name'],
                $k['city'] ?? '',
                $k['pincode'] ?? '',
                $k['notes'] ?? '',
                $k['latitude'] ?? '',
                $k['longitude'] ?? '',
            ]);
        }

        fclose($out);
    }

    public static function kycStatusForFreezer(int $freezerId): array {
        $cycle = self::currentCycle();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `kyc_verifications`
            WHERE `freezer_id` = :fid AND `cycle_year` = :y AND `cycle_half` = :h
            LIMIT 1");
        $stmt->execute([
            'fid' => $freezerId,
            'y' => $cycle['year'],
            'h' => $cycle['half'],
        ]);
        $row = $stmt->fetch() ?: null;

        $last = $pdo->prepare("SELECT * FROM `kyc_verifications`
            WHERE `freezer_id` = :fid ORDER BY `verified_at` DESC, `id` DESC LIMIT 1");
        $last->execute(['fid' => $freezerId]);
        $lastRow = $last->fetch() ?: null;

        return [
            'cycle' => $cycle,
            'current' => $row,
            'due' => $row === null,
            'last' => $lastRow,
        ];
    }
}
