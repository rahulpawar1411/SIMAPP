<?php
namespace App\Models;

use App\Config\Database;
use Exception;
use PDO;

/**
 * Freezer can move between stores (same owner / other outlet).
 * Unique Freezer ID stays fixed. KYC required every 6 months per store.
 */
class FreezerLifecycle {

    /** Months between KYC visits for the same store. */
    public const KYC_GAP_MONTHS = 6;

    /** Upgrade verified_at from DATE to DATETIME so submit time is stored. */
    public static function ensureKycVerifiedAtDateTime(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $pdo = Database::getConnection();
            $col = $pdo->query("SHOW COLUMNS FROM `kyc_verifications` LIKE 'verified_at'")->fetch(PDO::FETCH_ASSOC);
            $type = strtolower((string)($col['Type'] ?? ''));
            if ($type !== '' && strpos($type, 'datetime') === false && strpos($type, 'timestamp') === false) {
                $pdo->exec("ALTER TABLE `kyc_verifications` MODIFY `verified_at` DATETIME NOT NULL");
            }
        } catch (\Throwable $e) {
            // Non-fatal — insert may still work as DATE (time truncated)
        }
    }

    public static function currentCycle(?string $date = null): array {
        $ts = $date ? strtotime($date) : time();
        $year = (int)date('Y', $ts);
        $month = (int)date('n', $ts);
        $half = $month <= 6 ? 1 : 2;
        return [
            'year' => $year,
            'half' => $half,
            'label' => $year . '-H' . $half,
            'window' => 'Every ' . self::KYC_GAP_MONTHS . ' months',
            'gap_months' => self::KYC_GAP_MONTHS,
        ];
    }

    /**
     * Anchor for the 6-month clock:
     * last KYC at store if any, otherwise installation date.
     */
    public static function kycAnchorDate(?string $lastVerifiedAt, ?string $installationDate = null): ?string {
        if ($lastVerifiedAt !== null && trim($lastVerifiedAt) !== '') {
            return trim($lastVerifiedAt);
        }
        if ($installationDate !== null && trim($installationDate) !== '') {
            return trim($installationDate);
        }
        return null;
    }

    /** Next due date = anchor + 6 months (Y-m-d). */
    public static function nextKycDueDate(?string $anchorDate): ?string {
        if ($anchorDate === null || trim($anchorDate) === '') {
            return null;
        }
        $ts = strtotime(trim($anchorDate) . ' +' . self::KYC_GAP_MONTHS . ' months');
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * Due when last KYC (or installation if never KYC'd) + 6 months has passed.
     * First KYC window starts from installation date.
     */
    public static function isKycDue(?string $lastVerifiedAt, ?string $asOf = null, ?string $installationDate = null): bool {
        $anchor = self::kycAnchorDate($lastVerifiedAt, $installationDate);
        if ($anchor === null) {
            return true;
        }
        $nextDue = self::nextKycDueDate($anchor);
        if ($nextDue === null) {
            return true;
        }
        $asOfDay = $asOf ? date('Y-m-d', strtotime($asOf)) : date('Y-m-d');
        return $asOfDay >= $nextDue;
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
            'gps_accuracy' => ($data['gps_accuracy'] ?? '') !== '' ? (float)$data['gps_accuracy'] : null,
            'gps_captured_at' => $data['gps_captured_at'] ?? null,
            'location_link' => $data['location_link'] ?? null,
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
                s.store_name, s.outlet_id, s.city, s.state, s.pincode, s.address, s.zone,
                s.contact_person, s.phone_number, s.email AS store_email, s.customer_name
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

        self::ensureKycVerifiedAtDateTime();
        $verifiedAt = trim((string)($data['verified_at'] ?? ''));
        if ($verifiedAt === '' || !strtotime($verifiedAt)) {
            $verifiedAt = date('Y-m-d H:i:s');
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $verifiedAt)) {
            // Legacy date-only → stamp noon so time is present
            $verifiedAt .= ' ' . date('H:i:s');
        } else {
            $verifiedAt = date('Y-m-d H:i:s', strtotime($verifiedAt));
        }
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
        $storeId = (int)$freezer['store_id'];

        // One KYC per store every 6 months (same freezer at this outlet)
        $lastAtStore = $pdo->prepare("SELECT `id`, `verified_at` FROM `kyc_verifications`
            WHERE `freezer_id` = :fid AND `store_id` = :sid
            ORDER BY `verified_at` DESC, `id` DESC LIMIT 1");
        $lastAtStore->execute(['fid' => $freezerId, 'sid' => $storeId]);
        $prev = $lastAtStore->fetch() ?: null;
        if ($prev && !self::isKycDue((string)$prev['verified_at'], $verifiedAt)) {
            $nextDue = self::nextKycDueDate((string)$prev['verified_at']);
            throw new Exception(
                'KYC already done for this store on ' . $prev['verified_at']
                . '. Next due after ' . self::KYC_GAP_MONTHS . ' months'
                . ($nextDue ? " (from {$nextDue})" : '') . '.'
            );
        }

        // Same calendar half still unique (keeps history tidy)
        $existing = $pdo->prepare("SELECT `id` FROM `kyc_verifications`
            WHERE `freezer_id` = :fid AND `cycle_year` = :y AND `cycle_half` = :h LIMIT 1");
        $existing->execute(['fid' => $freezerId, 'y' => $year, 'h' => $half]);
        if ($existing->fetch()) {
            throw new Exception("KYC for {$year}-H{$half} already recorded. Next visit after " . self::KYC_GAP_MONTHS . " months.");
        }

        $stmt = $pdo->prepare("INSERT INTO `kyc_verifications`
            (`freezer_id`, `store_id`, `cycle_year`, `cycle_half`, `verified_at`, `result`, `notes`,
             `image_store_front`, `image_freezer`, `latitude`, `longitude`, `verified_by`)
            VALUES
            (:fid, :sid, :y, :h, :vdate, :result, :notes,
             :img_s, :img_f, :lat, :lng, :by)");
        $stmt->execute([
            'fid' => $freezerId,
            'sid' => $storeId,
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
                s.store_name, s.outlet_id, s.city, s.state, s.pincode, s.address, s.zone,
                s.contact_person, s.phone_number, s.email AS store_email,
                u.name AS verified_by_name
            FROM `kyc_verifications` k
            INNER JOIN `stores` s ON s.id = k.store_id
            LEFT JOIN `users` u ON u.id = k.verified_by
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

    /**
     * Single activity log for History tab / export:
     * Installation, Store move, KYC — Same store, KYC — New store.
     * Newest first.
     *
     * @return list<array{
     *   date:string, activity:string, activity_key:string, store_id:?string, store_name:?string,
     *   address:?string, city:?string, state:?string, pincode:?string, zone:?string,
     *   result:?string, cycle:?string, notes:?string, reason:?string,
     *   verified_by:?string, latitude:?string, longitude:?string,
     *   photo_store:?string, photo_freezer:?string, sort_key:string
     * }>
     */
    public static function buildActivityLog(array $freezer): array {
        $fid = (int)$freezer['id'];
        $placements = self::placementHistory($fid);
        // placementHistory is usually newest first — normalize oldest→newest for classification
        $byStart = $placements;
        usort($byStart, static function ($a, $b) {
            return strcmp((string)($a['started_at'] ?? ''), (string)($b['started_at'] ?? ''));
        });

        $moveDates = []; // date => true when a new-outlet / transfer placement started
        $moveStoreIds = [];
        foreach ($byStart as $idx => $p) {
            $isNewOutlet = stripos((string)($p['transfer_reason'] ?? ''), 'new outlet') !== false
                || stripos((string)($p['notes'] ?? ''), 'new outlet_id=') !== false
                || stripos((string)($p['transfer_reason'] ?? ''), 'auto ID') !== false
                || stripos((string)($p['transfer_reason'] ?? ''), 'moved') !== false
                || stripos((string)($p['transfer_reason'] ?? ''), 'KYC') !== false;
            if ($idx > 0 || $isNewOutlet) {
                $d = substr((string)($p['started_at'] ?? ''), 0, 10);
                if ($d !== '') {
                    $moveDates[$d] = true;
                    $moveStoreIds[(int)($p['store_id'] ?? 0)] = true;
                }
            }
        }

        $rows = [];

        // 1) Installation
        $installDate = trim((string)($freezer['installation_date'] ?? ''));
        $first = $byStart[0] ?? null;
        if ($installDate === '' && $first) {
            $installDate = (string)($first['started_at'] ?? '');
        }
        if ($installDate !== '' || $first) {
            $installerName = trim((string)($freezer['created_by_name'] ?? ''));
            if ($installerName === '' && !empty($freezer['created_by'])) {
                try {
                    $u = User::findById((int)$freezer['created_by']);
                    $installerName = trim((string)($u['name'] ?? ''));
                } catch (\Throwable $e) {
                    $installerName = '';
                }
            }
            $rows[] = [
                'date' => $installDate !== '' ? $installDate : (string)($first['started_at'] ?? ''),
                'activity' => 'Installation',
                'activity_key' => 'installation',
                'store_id' => $first['outlet_id'] ?? ($freezer['outlet_id'] ?? null),
                'store_name' => $first['store_name'] ?? ($freezer['store_name'] ?? null),
                'address' => self::cleanAddressText($first['address'] ?? ($freezer['store_address'] ?? ''), $freezer['unique_freezer_id'] ?? null),
                'city' => $first['city'] ?? ($freezer['store_city'] ?? null),
                'state' => $first['state'] ?? ($freezer['store_state'] ?? null),
                'pincode' => $first['pincode'] ?? ($freezer['store_pincode'] ?? null),
                'zone' => $first['zone'] ?? ($freezer['zone'] ?? null),
                'result' => null,
                'cycle' => null,
                'notes' => null,
                'reason' => 'Initial implementation / SIM install',
                'verified_by' => $installerName !== '' ? $installerName : null,
                'latitude' => null,
                'longitude' => null,
                'photo_store' => null,
                'photo_freezer' => null,
                'sort_key' => ($installDate !== '' ? $installDate : (string)($first['started_at'] ?? '0000-00-00')) . '-A00000000',
            ];
        }

        // 2) Store moves (placements after first)
        foreach ($byStart as $idx => $p) {
            if ($idx === 0) {
                continue;
            }
            $isNewOutlet = stripos((string)($p['transfer_reason'] ?? ''), 'new outlet') !== false
                || stripos((string)($p['notes'] ?? ''), 'new outlet_id=') !== false
                || stripos((string)($p['transfer_reason'] ?? ''), 'auto ID') !== false;
            $date = (string)($p['started_at'] ?? '');
            $rows[] = [
                'date' => $date,
                'activity' => $isNewOutlet ? 'Store move — new outlet' : 'Store move',
                'activity_key' => $isNewOutlet ? 'store_move_new' : 'store_move',
                'store_id' => $p['outlet_id'] ?? null,
                'store_name' => $p['store_name'] ?? null,
                'address' => self::cleanAddressText($p['address'] ?? '', $freezer['unique_freezer_id'] ?? null),
                'city' => $p['city'] ?? null,
                'state' => $p['state'] ?? null,
                'pincode' => $p['pincode'] ?? null,
                'zone' => $p['zone'] ?? null,
                'result' => null,
                'cycle' => null,
                'notes' => $p['notes'] ?? null,
                'reason' => $p['transfer_reason'] ?? null,
                'verified_by' => null,
                'latitude' => null,
                'longitude' => null,
                'photo_store' => null,
                'photo_freezer' => null,
                'sort_key' => $date . '-M' . str_pad((string)($p['id'] ?? 0), 8, '0', STR_PAD_LEFT),
            ];
        }

        // 3) KYC — same store / new store
        foreach (self::kycHistory($fid, 200) as $k) {
            $vDate = substr((string)($k['verified_at'] ?? ''), 0, 10);
            $sid = (int)($k['store_id'] ?? 0);
            $isNew = !empty($moveDates[$vDate]) || (!empty($sid) && !empty($moveStoreIds[$sid]) && isset($moveDates[$vDate]));
            // Also: KYC store differs from earliest placement store and placement started same day
            if (!$isNew && $vDate !== '') {
                foreach ($byStart as $idx => $p) {
                    if ($idx === 0) {
                        continue;
                    }
                    if (substr((string)($p['started_at'] ?? ''), 0, 10) === $vDate
                        && (int)($p['store_id'] ?? 0) === $sid) {
                        $isNew = true;
                        break;
                    }
                }
            }

            $rows[] = [
                'date' => (string)($k['verified_at'] ?? ''),
                'activity' => $isNew ? 'KYC — New store' : 'KYC — Same store',
                'activity_key' => $isNew ? 'kyc_new_store' : 'kyc_same_store',
                'store_id' => $k['outlet_id'] ?? null,
                'store_name' => $k['store_name'] ?? null,
                'address' => self::cleanAddressText($k['address'] ?? '', $freezer['unique_freezer_id'] ?? null),
                'city' => $k['city'] ?? null,
                'state' => $k['state'] ?? null,
                'pincode' => $k['pincode'] ?? null,
                'zone' => $k['zone'] ?? null,
                'result' => $k['result'] ?? null,
                'cycle' => ((int)($k['cycle_year'] ?? 0)) . '-H' . ((int)($k['cycle_half'] ?? 0)),
                'notes' => $k['notes'] ?? null,
                'reason' => null,
                'verified_by' => $k['verified_by_name'] ?? null,
                'latitude' => isset($k['latitude']) ? (string)$k['latitude'] : null,
                'longitude' => isset($k['longitude']) ? (string)$k['longitude'] : null,
                'photo_store' => !empty($k['image_store_front']) ? 'Yes' : 'No',
                'photo_freezer' => !empty($k['image_freezer']) ? 'Yes' : 'No',
                'sort_key' => (string)($k['verified_at'] ?? '') . '-K' . str_pad((string)($k['id'] ?? 0), 8, '0', STR_PAD_LEFT),
            ];
        }

        // 4) Rejected KYC requests (no profile KYC record — from notifications)
        try {
            AppNotification::ensureTable();
            $pdo = Database::getConnection();
            $rej = $pdo->prepare("SELECT * FROM `app_notifications`
                WHERE `freezer_id` = :fid
                  AND `status` = 'rejected'
                  AND `type` IN ('kyc_same_store', 'kyc_store_move')
                ORDER BY `id` DESC
                LIMIT 50");
            $rej->execute(['fid' => $fid]);
            foreach ($rej->fetchAll(PDO::FETCH_ASSOC) ?: [] as $n) {
                $payload = AppNotification::decodePayload($n);
                $kyc = $payload['kyc'] ?? [];
                $store = $payload['store'] ?? [];
                $from = $payload['from_store'] ?? [];
                $isMove = ($n['type'] ?? '') === AppNotification::TYPE_KYC_STORE_MOVE;
                $date = (string)($n['reviewed_at'] ?? $kyc['verified_at'] ?? $n['created_at'] ?? '');
                if ($date !== '' && str_contains($date, ' ')) {
                    $date = substr($date, 0, 10);
                }
                $rejectNote = trim((string)($n['review_notes'] ?? ''));
                $rows[] = [
                    'date' => $date,
                    'activity' => $isMove ? 'KYC — Rejected (new store)' : 'KYC — Rejected (same store)',
                    'activity_key' => 'kyc_rejected',
                    'store_id' => $isMove ? '—' : ($from['outlet_id'] ?? null),
                    'store_name' => $isMove
                        ? ($store['store_name'] ?? null)
                        : ($from['store_name'] ?? null),
                    'address' => $isMove
                        ? trim(implode(', ', array_filter([
                            $store['address'] ?? '',
                            $store['city'] ?? '',
                            $store['pincode'] ?? '',
                        ])))
                        : null,
                    'city' => $isMove ? ($store['city'] ?? null) : null,
                    'state' => $isMove ? ($store['state'] ?? null) : null,
                    'pincode' => $isMove ? ($store['pincode'] ?? null) : null,
                    'zone' => $isMove ? ($store['zone'] ?? null) : null,
                    'result' => 'REJECTED',
                    'cycle' => !empty($kyc['cycle_year'])
                        ? ((int)$kyc['cycle_year'] . '-H' . (int)($kyc['cycle_half'] ?? 0))
                        : null,
                    'notes' => $kyc['notes'] ?? null,
                    'reason' => $rejectNote !== '' ? ('Rejected: ' . $rejectNote) : 'Rejected by admin — no profile change',
                    'verified_by' => $n['created_by_name']
                        ?? ($payload['technician_name'] ?? ($kyc['technician_name'] ?? null)),
                    'latitude' => isset($kyc['latitude']) ? (string)$kyc['latitude'] : null,
                    'longitude' => isset($kyc['longitude']) ? (string)$kyc['longitude'] : null,
                    'photo_store' => !empty($store['image_store_front'] ?? $kyc['image_store_front'] ?? null) ? 'Yes' : 'No',
                    'photo_freezer' => !empty($kyc['image_freezer']) ? 'Yes' : 'No',
                    'sort_key' => ($date !== '' ? $date : '0000-00-00') . '-R' . str_pad((string)($n['id'] ?? 0), 8, '0', STR_PAD_LEFT),
                ];
            }
        } catch (\Throwable $e) {
            // notifications table may not exist yet
        }

        usort($rows, static fn($a, $b) => strcmp((string)$b['sort_key'], (string)$a['sort_key']));
        return $rows;
    }

    /** @return list<string> */
    public static function activityExportColumns(): array {
        return [
            'Date',
            'Activity',
            'Unique Freezer ID',
            'Tag code',
            'Store ID',
            'Store name',
            'Address',
            'City',
            'State',
            'PIN',
            'Zone',
            'KYC result',
            'Cycle',
            'Reason',
            'Notes',
            'Verified by',
            'GPS lat',
            'GPS lng',
            'Store photo',
            'Freezer photo',
        ];
    }

    /** @return list<array<string,string>> */
    public static function activityExportRows(array $freezer, string $tagCode = ''): array {
        $out = [];
        foreach (self::buildActivityLog($freezer) as $r) {
            $out[] = [
                'Date' => (string)($r['date'] ?? ''),
                'Activity' => (string)($r['activity'] ?? ''),
                'Unique Freezer ID' => (string)($freezer['unique_freezer_id'] ?? ''),
                'Tag code' => $tagCode,
                'Store ID' => (string)($r['store_id'] ?? ''),
                'Store name' => (string)($r['store_name'] ?? ''),
                'Address' => (string)($r['address'] ?? ''),
                'City' => (string)($r['city'] ?? ''),
                'State' => (string)($r['state'] ?? ''),
                'PIN' => (string)($r['pincode'] ?? ''),
                'Zone' => (string)($r['zone'] ?? ''),
                'KYC result' => (string)($r['result'] ?? ''),
                'Cycle' => (string)($r['cycle'] ?? ''),
                'Reason' => (string)($r['reason'] ?? ''),
                'Notes' => (string)($r['notes'] ?? ''),
                'Verified by' => (string)($r['verified_by'] ?? ''),
                'GPS lat' => (string)($r['latitude'] ?? ''),
                'GPS lng' => (string)($r['longitude'] ?? ''),
                'Store photo' => (string)($r['photo_store'] ?? ''),
                'Freezer photo' => (string)($r['photo_freezer'] ?? ''),
            ];
        }
        return $out;
    }

    /** Strip Unique Freezer ID glued into address text (for display / export). */
    public static function cleanAddressText(?string $text, ?string $uniqueFreezerId = null): string {
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }
        $fid = trim((string)$uniqueFreezerId);
        if ($fid !== '') {
            $escaped = preg_quote($fid, '/');
            $text = preg_replace('/' . $escaped . '/i', '', $text) ?? $text;
        }
        $text = preg_replace('/(?:\s*[,;.\-]?\s*)?(?:DF|DCF|CF)-[A-Z0-9]{2,}(?:-[A-Z0-9]+)+/i', '', $text) ?? $text;
        return trim(preg_replace('/\s{2,}/', ' ', $text) ?? $text, " \t,;.-");
    }

    /** @return list<string> */
    public static function kycExportColumns(): array {
        return [
            'Unique Freezer ID',
            'Tag code',
            'Cycle',
            'Cycle year',
            'Cycle half',
            'Verified date',
            'Result',
            'Store ID',
            'Store name',
            'Address',
            'City',
            'State',
            'PIN',
            'Zone',
            'Contact',
            'Phone',
            'GPS lat',
            'GPS lng',
            'Store photo',
            'Freezer photo',
            'Notes',
            'Verified by',
        ];
    }

    /** @return list<array<string, scalar|null>> */
    public static function kycExportRows(array $freezer, string $tagCode = ''): array {
        $fid = (int)$freezer['id'];
        $uid = (string)($freezer['unique_freezer_id'] ?? '');
        $rows = [];
        foreach (self::kycHistory($fid, 200) as $k) {
            $rows[] = [
                'Unique Freezer ID' => $uid,
                'Tag code' => $tagCode,
                'Cycle' => $k['cycle_year'] . '-H' . $k['cycle_half'],
                'Cycle year' => $k['cycle_year'],
                'Cycle half' => ((int)$k['cycle_half'] === 1) ? 'H1' : 'H2',
                'Verified date' => $k['verified_at'] ?? '',
                'Result' => $k['result'] ?? '',
                'Store ID' => $k['outlet_id'] ?? '',
                'Store name' => $k['store_name'] ?? '',
                'Address' => self::cleanAddressText($k['address'] ?? '', $uid),
                'City' => $k['city'] ?? '',
                'State' => $k['state'] ?? '',
                'PIN' => $k['pincode'] ?? '',
                'Zone' => $k['zone'] ?? '',
                'Contact' => $k['contact_person'] ?? '',
                'Phone' => $k['phone_number'] ?? '',
                'GPS lat' => $k['latitude'] ?? '',
                'GPS lng' => $k['longitude'] ?? '',
                'Store photo' => !empty($k['image_store_front']) ? 'YES' : 'NO',
                'Freezer photo' => !empty($k['image_freezer']) ? 'YES' : 'NO',
                'Notes' => $k['notes'] ?? '',
                'Verified by' => $k['verified_by_name'] ?? '',
            ];
        }
        return $rows;
    }

    /**
     * History export: unified activity / implementation / KYC sheets.
     * @param string $sheet all|activity|implementation|kyc
     * @param string $format csv|xlsx  (xlsx supported for sheet=kyc|activity)
     */
    public static function streamHistoryExport(array $freezer, string $sheet = 'all', string $format = 'csv'): void {
        $fid = (int)$freezer['id'];
        $sheet = in_array($sheet, ['all', 'activity', 'implementation', 'kyc'], true) ? $sheet : 'activity';
        $format = in_array($format, ['csv', 'xlsx'], true) ? $format : 'csv';
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $freezer['unique_freezer_id']);

        $tagCode = '';
        try {
            $tag = AssetTag::findByFreezerId($fid);
            $tagCode = $tag['tag_code'] ?? '';
        } catch (\Throwable $e) {
            $tagCode = '';
        }

        // Unified activity Excel
        if ($format === 'xlsx' && ($sheet === 'activity' || $sheet === 'all')) {
            $cols = self::activityExportColumns();
            $rows = self::activityExportRows($freezer, $tagCode);
            $writer = new \App\Services\ExcelXlsxWriter(static fn(string $c): string => 'store');
            $writer->setSheetName('Store activity')
                ->setColumns($cols)
                ->setRows($rows)
                ->stream('store_activity_' . $safeName . '_' . date('Ymd_His') . '.xlsx');
            return;
        }

        // Unified activity CSV
        if ($format === 'csv' && $sheet === 'activity') {
            $filename = 'store_activity_' . $safeName . '_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $filename);
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            $cols = self::activityExportColumns();
            fputcsv($out, $cols);
            foreach (self::activityExportRows($freezer, $tagCode) as $row) {
                $line = [];
                foreach ($cols as $col) {
                    $line[] = $row[$col] ?? '';
                }
                fputcsv($out, $line);
            }
            fclose($out);
            return;
        }

        // Excel export for KYC verifications table
        if ($format === 'xlsx' && $sheet === 'kyc') {
            $cols = self::kycExportColumns();
            $rows = self::kycExportRows($freezer, $tagCode);
            $writer = new \App\Services\ExcelXlsxWriter(static fn(string $c): string => 'store');
            $writer->setSheetName('KYC verifications')
                ->setColumns($cols)
                ->setRows($rows)
                ->stream('kyc_verifications_' . $safeName . '_' . date('Ymd_His') . '.xlsx');
            return;
        }

        $filename = 'store_history_' . $sheet . '_' . $safeName . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if ($sheet === 'all' || $sheet === 'implementation') {
            fputcsv($out, ['=== IMPLEMENTATION / STORE PLACEMENTS ===']);
            fputcsv($out, [
                'unique_freezer_id',
                'tag_code',
                'installation_date',
                'status',
                'outlet_id',
                'store_name',
                'customer_name',
                'address',
                'city',
                'state',
                'pincode',
                'zone',
                'contact_person',
                'phone',
                'email',
                'implemented_from',
                'implemented_to',
                'days_at_store',
                'reason',
                'notes',
            ]);
            foreach (self::placementHistory($fid) as $p) {
                $isCurrent = empty($p['ended_at']);
                $fromTs = !empty($p['started_at']) ? strtotime($p['started_at']) : false;
                $toTs = $isCurrent ? time() : (!empty($p['ended_at']) ? strtotime($p['ended_at']) : false);
                $days = ($fromTs && $toTs) ? max(0, (int)floor(($toTs - $fromTs) / 86400)) : '';
                fputcsv($out, [
                    $freezer['unique_freezer_id'],
                    $tagCode,
                    $freezer['installation_date'] ?? '',
                    $isCurrent ? 'CURRENT' : 'LEFT',
                    $p['outlet_id'] ?? '',
                    $p['store_name'] ?? '',
                    $p['customer_name'] ?? '',
                    self::cleanAddressText($p['address'] ?? '', $freezer['unique_freezer_id'] ?? null),
                    $p['city'] ?? '',
                    $p['state'] ?? '',
                    $p['pincode'] ?? '',
                    $p['zone'] ?? '',
                    $p['contact_person'] ?? '',
                    $p['phone_number'] ?? '',
                    $p['store_email'] ?? '',
                    $p['started_at'] ?? '',
                    $isCurrent ? 'CURRENT' : ($p['ended_at'] ?? ''),
                    $days,
                    $p['transfer_reason'] ?? '',
                    $p['notes'] ?? '',
                ]);
            }
        }

        if ($sheet === 'all') {
            fputcsv($out, []);
        }

        if ($sheet === 'all' || $sheet === 'kyc') {
            fputcsv($out, ['=== KYC VERIFICATIONS ===']);
            $kycCols = self::kycExportColumns();
            fputcsv($out, $kycCols);
            foreach (self::kycExportRows($freezer, $tagCode) as $row) {
                $line = [];
                foreach ($kycCols as $col) {
                    $line[] = $row[$col] ?? '';
                }
                fputcsv($out, $line);
            }

            // Year grid appendix
            fputcsv($out, []);
            fputcsv($out, ['=== KYC BY YEAR (H1 / H2) ===']);
            fputcsv($out, [
                'year',
                'h1_result', 'h1_date', 'h1_outlet', 'h1_store',
                'h2_result', 'h2_date', 'h2_outlet', 'h2_store',
            ]);
            $byYear = [];
            foreach (self::kycHistory($fid, 200) as $k) {
                $y = (int)$k['cycle_year'];
                $h = (int)$k['cycle_half'];
                if (!isset($byYear[$y])) {
                    $byYear[$y] = ['h1' => null, 'h2' => null];
                }
                if ($h === 1) {
                    $byYear[$y]['h1'] = $k;
                } elseif ($h === 2) {
                    $byYear[$y]['h2'] = $k;
                }
            }
            krsort($byYear);
            foreach ($byYear as $year => $halves) {
                $h1 = $halves['h1'];
                $h2 = $halves['h2'];
                fputcsv($out, [
                    $year,
                    $h1['result'] ?? '',
                    $h1['verified_at'] ?? '',
                    $h1['outlet_id'] ?? '',
                    $h1['store_name'] ?? '',
                    $h2['result'] ?? '',
                    $h2['verified_at'] ?? '',
                    $h2['outlet_id'] ?? '',
                    $h2['store_name'] ?? '',
                ]);
            }
        }

        fclose($out);
    }

    public static function kycStatusForFreezer(int $freezerId): array {
        $cycle = self::currentCycle();
        $pdo = Database::getConnection();
        $freezer = Freezer::findById($freezerId);
        $storeId = $freezer ? (int)$freezer['store_id'] : 0;

        // Last KYC for this freezer at the current store (6-month gap is per store)
        $atStore = null;
        if ($storeId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM `kyc_verifications`
                WHERE `freezer_id` = :fid AND `store_id` = :sid
                ORDER BY `verified_at` DESC, `id` DESC LIMIT 1");
            $stmt->execute(['fid' => $freezerId, 'sid' => $storeId]);
            $atStore = $stmt->fetch() ?: null;
        }

        $last = $pdo->prepare("SELECT * FROM `kyc_verifications`
            WHERE `freezer_id` = :fid ORDER BY `verified_at` DESC, `id` DESC LIMIT 1");
        $last->execute(['fid' => $freezerId]);
        $lastRow = $last->fetch() ?: null;

        $lastAtStoreDate = $atStore['verified_at'] ?? null;
        $installDate = $freezer['installation_date'] ?? null;
        $anchor = self::kycAnchorDate(
            $lastAtStoreDate !== null ? (string)$lastAtStoreDate : null,
            $installDate !== null ? (string)$installDate : null
        );
        $due = self::isKycDue(
            $lastAtStoreDate !== null ? (string)$lastAtStoreDate : null,
            null,
            $installDate !== null ? (string)$installDate : null
        );
        $nextDue = self::nextKycDueDate($anchor) ?? date('Y-m-d');
        $withinGap = !$due;

        return [
            'cycle' => $cycle,
            'current' => ($withinGap && $atStore) ? $atStore : null,
            'due' => $due,
            'last' => $lastRow,
            'last_at_store' => $atStore,
            'anchor_date' => $anchor,
            'anchor_source' => $lastAtStoreDate ? 'kyc' : ($installDate ? 'installation' : null),
            'next_due_at' => $nextDue,
            'gap_months' => self::KYC_GAP_MONTHS,
        ];
    }

    /** SQL expression: DATE_ADD(COALESCE(last KYC at store, installation_date), INTERVAL N MONTH) */
    public static function kycDueSqlExpr(string $freezerAlias = 'f', int $gapMonths = 0): string {
        $gap = $gapMonths > 0 ? $gapMonths : self::KYC_GAP_MONTHS;
        $anchor = "COALESCE(
            (
                SELECT MAX(k.verified_at) FROM `kyc_verifications` k
                WHERE k.freezer_id = {$freezerAlias}.id
                  AND k.store_id = {$freezerAlias}.store_id
            ),
            {$freezerAlias}.installation_date,
            '1970-01-01'
        )";
        return "DATE_ADD({$anchor}, INTERVAL {$gap} MONTH)";
    }

    /**
     * Technician work queue: KYC due on freezers this user last verified (re-visits),
     * plus their KYC completed today.
     *
     * @return array{due:list<array>,today:list<array>,due_count:int,today_count:int}
     */
    /**
     * @param array{zone?:string,city?:string} $filters
     */
    public static function technicianWorkQueue(int $userId, int $dueLimit = 10, array $filters = []): array {
        $pdo = Database::getConnection();
        Freezer::ensureSimColumns();
        $userId = max(0, $userId);
        $dueLimit = max(1, min(30, $dueLimit));
        $gap = self::KYC_GAP_MONTHS;
        $dueExpr = self::kycDueSqlExpr('f', $gap);
        $today = date('Y-m-d');

        $due = [];
        $todayRows = [];
        if ($userId < 1) {
            return ['due' => [], 'today' => [], 'due_count' => 0, 'today_count' => 0];
        }

        $zone = strtoupper(trim((string)($filters['zone'] ?? '')));
        $city = trim((string)($filters['city'] ?? ''));
        if ($zone === 'ALL') {
            $zone = '';
        }
        if (strcasecmp($city, 'All') === 0) {
            $city = '';
        }
        $areaSql = '';
        $areaParams = [];
        if ($zone !== '') {
            $areaSql .= ' AND UPPER(TRIM(s.zone)) = :zone';
            $areaParams['zone'] = $zone;
        }
        if ($city !== '') {
            $areaSql .= ' AND s.city = :city';
            $areaParams['city'] = $city;
        }

        // Due re-visits: latest KYC at current store was by this technician, and due date passed
        $lastByExpr = "(SELECT k2.verified_by FROM `kyc_verifications` k2
             WHERE k2.freezer_id = f.id AND k2.store_id = f.store_id
             ORDER BY k2.verified_at DESC, k2.id DESC LIMIT 1)";
        $lastAtExpr = "(SELECT k2.verified_at FROM `kyc_verifications` k2
             WHERE k2.freezer_id = f.id AND k2.store_id = f.store_id
             ORDER BY k2.verified_at DESC, k2.id DESC LIMIT 1)";
        $lastResExpr = "(SELECT k2.result FROM `kyc_verifications` k2
             WHERE k2.freezer_id = f.id AND k2.store_id = f.store_id
             ORDER BY k2.verified_at DESC, k2.id DESC LIMIT 1)";

        $dueParams = array_merge(['uid' => $userId, 'today' => $today], $areaParams);
        $dueStmt = $pdo->prepare(
            "SELECT f.id, f.unique_freezer_id, f.installation_date, s.store_name, s.outlet_id, s.city,
                    {$lastAtExpr} AS last_kyc_at, {$lastResExpr} AS last_kyc_result,
                    {$dueExpr} AS next_kyc_due
             FROM `freezers` f
             INNER JOIN `stores` s ON s.id = f.store_id
             WHERE f.deleted_at IS NULL
               AND {$lastByExpr} = :uid
               AND {$dueExpr} <= :today
               {$areaSql}
             ORDER BY next_kyc_due ASC, f.id DESC
             LIMIT {$dueLimit}"
        );
        $dueStmt->execute($dueParams);
        $due = $dueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cntStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM `freezers` f
             INNER JOIN `stores` s ON s.id = f.store_id
             WHERE f.deleted_at IS NULL
               AND {$lastByExpr} = :uid
               AND {$dueExpr} <= :today
               {$areaSql}"
        );
        $cntStmt->execute($dueParams);
        $dueCount = (int)$cntStmt->fetchColumn();

        $todayParams = array_merge(['uid' => $userId, 'today' => $today], $areaParams);
        $todayCntStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM `kyc_verifications` k
             INNER JOIN `freezers` f ON f.id = k.freezer_id AND f.deleted_at IS NULL
             LEFT JOIN `stores` s ON s.id = k.store_id
             WHERE k.verified_by = :uid
               AND DATE(k.verified_at) = :today
               {$areaSql}"
        );
        $todayCntStmt->execute($todayParams);
        $todayCount = (int)$todayCntStmt->fetchColumn();

        $todayLimit = max(1, min(100, $dueLimit * 4)); // show more of today’s KYCs on Home
        $todayStmt = $pdo->prepare(
            "SELECT k.result, k.verified_at, f.id AS freezer_id, f.unique_freezer_id, s.store_name, s.city
             FROM `kyc_verifications` k
             INNER JOIN `freezers` f ON f.id = k.freezer_id AND f.deleted_at IS NULL
             LEFT JOIN `stores` s ON s.id = k.store_id
             WHERE k.verified_by = :uid
               AND DATE(k.verified_at) = :today
               {$areaSql}
             ORDER BY k.verified_at DESC, k.id DESC
             LIMIT {$todayLimit}"
        );
        $todayStmt->execute($todayParams);
        $todayRows = $todayStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'due' => $due,
            'today' => $todayRows,
            'due_count' => $dueCount,
            'today_count' => $todayCount,
        ];
    }

    /**
     * KYC-due freezers with store GPS for the map (nearby / list).
     * Caps at $limit for load safety.
     *
     * @return list<array{
     *   id:int,unique_freezer_id:string,store_name:string,outlet_id:string,city:string,zone:string,
     *   latitude:float,longitude:float,next_kyc_due:string,last_kyc_at:?string,has_gps:bool
     * }>
     */
    public static function kycDueForMap(int $limit = 200, array $filters = []): array {
        $pdo = Database::getConnection();
        Freezer::ensureSimColumns();
        $limit = max(1, min(500, $limit));
        $gap = self::KYC_GAP_MONTHS;
        $dueExpr = self::kycDueSqlExpr('f', $gap);
        $today = date('Y-m-d');

        $zone = strtoupper(trim((string)($filters['zone'] ?? '')));
        $city = trim((string)($filters['city'] ?? ''));
        if ($zone === 'ALL') {
            $zone = '';
        }
        if (strcasecmp($city, 'All') === 0) {
            $city = '';
        }

        $extra = [];
        $params = ['today' => $today];
        if ($zone !== '') {
            $extra[] = 'UPPER(TRIM(s.zone)) = :zone';
            $params['zone'] = $zone;
        }
        if ($city !== '') {
            $extra[] = 's.city = :city';
            $params['city'] = $city;
        }
        $extraSql = $extra ? (' AND ' . implode(' AND ', $extra)) : '';

        $lastAtExpr = "(SELECT MAX(k.verified_at) FROM `kyc_verifications` k
            WHERE k.freezer_id = f.id AND k.store_id = f.store_id)";

        $stmt = $pdo->prepare(
            "SELECT f.id, f.unique_freezer_id, s.store_name, s.outlet_id, s.city, s.zone,
                    s.latitude, s.longitude, {$dueExpr} AS next_kyc_due,
                    {$lastAtExpr} AS last_kyc_at
             FROM `freezers` f
             INNER JOIN `stores` s ON s.id = f.store_id
             WHERE f.deleted_at IS NULL
               AND {$dueExpr} <= :today
               {$extraSql}
             ORDER BY next_kyc_due ASC, f.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $lat = isset($r['latitude']) && $r['latitude'] !== '' && $r['latitude'] !== null
                ? (float)$r['latitude'] : null;
            $lng = isset($r['longitude']) && $r['longitude'] !== '' && $r['longitude'] !== null
                ? (float)$r['longitude'] : null;
            $hasGps = $lat !== null && $lng !== null
                && abs($lat) <= 90 && abs($lng) <= 180
                && !($lat == 0.0 && $lng == 0.0);
            $out[] = [
                'id' => (int)$r['id'],
                'unique_freezer_id' => (string)($r['unique_freezer_id'] ?? ''),
                'store_name' => (string)($r['store_name'] ?? ''),
                'outlet_id' => (string)($r['outlet_id'] ?? ''),
                'city' => (string)($r['city'] ?? ''),
                'zone' => (string)($r['zone'] ?? ''),
                'latitude' => $hasGps ? $lat : null,
                'longitude' => $hasGps ? $lng : null,
                'next_kyc_due' => (string)($r['next_kyc_due'] ?? ''),
                'last_kyc_at' => $r['last_kyc_at'] !== null ? (string)$r['last_kyc_at'] : null,
                'has_gps' => $hasGps,
            ];
        }
        return $out;
    }

    /**
     * Dashboard KYC counts — pending when install/last KYC + 6 months has passed.
     * @param array{zone?:string,city?:string} $filters  empty zone/city = All
     * @return array{done:int,pending:int,pending_list:array,cutoff:string,gap_months:int}
     */
    public static function dashboardKycStats(int $pendingLimit = 5, array $filters = []): array {
        $pdo = Database::getConnection();
        $gap = self::KYC_GAP_MONTHS;
        $dueExpr = self::kycDueSqlExpr('f', $gap);
        $today = $pdo->quote(date('Y-m-d'));

        Freezer::ensureSimColumns();
        $alive = '(f.deleted_at IS NULL)';

        $zone = strtoupper(trim((string)($filters['zone'] ?? '')));
        $city = trim((string)($filters['city'] ?? ''));
        if ($zone === 'ALL') {
            $zone = '';
        }
        if (strcasecmp($city, 'All') === 0) {
            $city = '';
        }

        $extra = [];
        $params = [];
        if ($zone !== '') {
            $extra[] = 'UPPER(TRIM(s.zone)) = :zone';
            $params['zone'] = $zone;
        }
        if ($city !== '') {
            $extra[] = 's.city = :city';
            $params['city'] = $city;
        }
        $extraSql = $extra ? (' AND ' . implode(' AND ', $extra)) : '';
        $join = ($zone !== '' || $city !== '') ? 'INNER' : 'LEFT';

        $doneStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM `freezers` f
             {$join} JOIN `stores` s ON s.id = f.store_id
             WHERE {$alive} AND {$dueExpr} > {$today}{$extraSql}"
        );
        $doneStmt->execute($params);
        $done = (int)$doneStmt->fetchColumn();

        $pendingStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM `freezers` f
             {$join} JOIN `stores` s ON s.id = f.store_id
             WHERE {$alive} AND {$dueExpr} <= {$today}{$extraSql}"
        );
        $pendingStmt->execute($params);
        $pending = (int)$pendingStmt->fetchColumn();

        $list = [];
        if ($pending > 0 && $pendingLimit > 0) {
            $lim = (int)$pendingLimit;
            $listStmt = $pdo->prepare(
                "SELECT f.id, f.unique_freezer_id, f.installation_date, s.store_name, s.outlet_id, s.city,
                        (
                            SELECT MAX(k.verified_at) FROM `kyc_verifications` k
                            WHERE k.freezer_id = f.id AND k.store_id = f.store_id
                        ) AS last_kyc_at,
                        {$dueExpr} AS next_kyc_due
                 FROM `freezers` f
                 {$join} JOIN `stores` s ON s.id = f.store_id
                 WHERE {$alive} AND {$dueExpr} <= {$today}{$extraSql}
                 ORDER BY next_kyc_due ASC, f.id DESC
                 LIMIT {$lim}"
            );
            $listStmt->execute($params);
            $list = $listStmt->fetchAll() ?: [];
        }

        return [
            'done' => $done,
            'pending' => $pending,
            'pending_list' => $list,
            'cutoff' => date('Y-m-d', strtotime("-{$gap} months")),
            'gap_months' => $gap,
        ];
    }

    /**
     * Date-wise KYC activity for the Activity section.
     * Pass $from/$to (Y-m-d) for calendar range, or $days for a rolling window.
     * @return list<array{
     *   date:string,label:string,total:int,same_store:int,new_store:int,pass:int,fail:int,
     *   items:list<array>
     * }>
     */
    /**
     * @param string $location  all|same|new
     */
    public static function notificationHistoryByDate(
        int $days = 90,
        ?string $from = null,
        ?string $to = null,
        string $location = 'all',
        string $resultFilter = 'all'
    ): array {
        $pdo = Database::getConnection();

        $fromDate = trim((string)$from);
        $toDate = trim((string)$to);
        $useRange = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate);
        if ($useRange && $fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $location = strtolower(trim($location));
        if (!in_array($location, ['all', 'same', 'new'], true)) {
            $location = 'all';
        }
        $resultFilter = strtoupper(trim($resultFilter));
        if (!in_array($resultFilter, ['ALL', 'PASS', 'FAIL'], true)) {
            $resultFilter = 'ALL';
        }

        if ($useRange) {
            $whereSql = 'DATE(k.verified_at) >= :from_d AND DATE(k.verified_at) <= :to_d';
            $params = ['from_d' => $fromDate, 'to_d' => $toDate];
        } else {
            $days = max(7, min(180, $days));
            $whereSql = "DATE(k.verified_at) >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)";
            $params = [];
        }

        // Push result filter into SQL so we don't over-fetch.
        if ($resultFilter === 'FAIL') {
            $whereSql .= " AND UPPER(TRIM(COALESCE(k.result, ''))) = 'FAIL'";
        } elseif ($resultFilter === 'PASS') {
            $whereSql .= " AND UPPER(TRIM(COALESCE(k.result, ''))) <> 'FAIL'";
        }

        $isNewExpr = "CASE WHEN EXISTS (
                        SELECT 1 FROM `freezer_placements` p
                        WHERE p.freezer_id = k.freezer_id
                          AND p.store_id = k.store_id
                          AND DATE(p.started_at) = DATE(k.verified_at)
                          AND EXISTS (
                              SELECT 1 FROM `freezer_placements` p0
                              WHERE p0.freezer_id = k.freezer_id AND p0.id < p.id
                          )
                    ) THEN 1 ELSE 0 END";

        // Hard page cap — Activity never loads unbounded KYC history.
        $maxRows = 500;
        $stmt = $pdo->prepare(
            "SELECT k.id, k.freezer_id, k.store_id, k.verified_at, k.result, k.notes, k.verified_by,
                    f.unique_freezer_id, f.deleted_at AS freezer_deleted,
                    s.store_name, s.outlet_id, s.city, s.zone,
                    u.name AS technician_name,
                    {$isNewExpr} AS is_new_store
             FROM `kyc_verifications` k
             INNER JOIN `freezers` f ON f.id = k.freezer_id
             LEFT JOIN `stores` s ON s.id = k.store_id
             LEFT JOIN `users` u ON u.id = k.verified_by
             WHERE {$whereSql}
             ORDER BY k.verified_at DESC, k.id DESC
             LIMIT {$maxRows}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $truncated = count($rows) >= $maxRows;

        $groups = [];
        foreach ($rows as $r) {
            $isNew = !empty($r['is_new_store']);
            if ($location === 'same' && $isNew) {
                continue;
            }
            if ($location === 'new' && !$isNew) {
                continue;
            }
            $rowResult = strtoupper(trim((string)($r['result'] ?? 'PASS')));

            $date = substr((string)($r['verified_at'] ?? ''), 0, 10);
            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            if (!isset($groups[$date])) {
                $ts = strtotime($date);
                $label = $ts ? date('D, d M Y', $ts) : $date;
                if ($date === date('Y-m-d')) {
                    $label = 'Today · ' . $label;
                } elseif ($date === date('Y-m-d', strtotime('-1 day'))) {
                    $label = 'Yesterday · ' . $label;
                }
                $groups[$date] = [
                    'date' => $date,
                    'label' => $label,
                    'total' => 0,
                    'same_store' => 0,
                    'new_store' => 0,
                    'pass' => 0,
                    'fail' => 0,
                    'items' => [],
                ];
            }

            $groups[$date]['total']++;
            if ($isNew) {
                $groups[$date]['new_store']++;
            } else {
                $groups[$date]['same_store']++;
            }
            if ($rowResult === 'FAIL') {
                $groups[$date]['fail']++;
            } else {
                $groups[$date]['pass']++;
            }

            // Cap items per day so one busy day cannot explode the page.
            if (count($groups[$date]['items']) < 100) {
                $groups[$date]['items'][] = [
                    'id' => (int)$r['id'],
                    'freezer_id' => (int)$r['freezer_id'],
                    'unique_freezer_id' => (string)($r['unique_freezer_id'] ?? ''),
                    'store_name' => (string)($r['store_name'] ?? ''),
                    'outlet_id' => (string)($r['outlet_id'] ?? ''),
                    'city' => (string)($r['city'] ?? ''),
                    'zone' => (string)($r['zone'] ?? ''),
                    'result' => $rowResult,
                    'notes' => (string)($r['notes'] ?? ''),
                    'technician_name' => (string)($r['technician_name'] ?? ''),
                    'is_new_store' => $isNew,
                    'verified_at' => (string)($r['verified_at'] ?? ''),
                ];
            }
        }

        $out = array_values($groups);
        // Attach truncation flag on first group for UI notice (non-breaking for callers).
        if ($truncated && $out !== []) {
            $out[0]['_truncated'] = true;
            $out[0]['_max_rows'] = $maxRows;
        }
        return $out;
    }
}
