<?php
namespace App\Models;

use App\Config\Database;
use PDO;
use Exception;

class AppNotification {

    public const TYPE_KYC_STORE_MOVE = 'kyc_store_move';
    public const TYPE_KYC_SAME_STORE = 'kyc_same_store';
    public const TYPE_PROFILE_EDIT = 'profile_edit';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    private static bool $tableReady = false;

    public static function ensureTable(): void {
        if (self::$tableReady) {
            return;
        }
        $pdo = Database::getConnection();
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS `app_notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `type` VARCHAR(64) NOT NULL,
            `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `freezer_id` INT NOT NULL,
            `unique_freezer_id` VARCHAR(120) NULL,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NULL,
            `payload` LONGTEXT NOT NULL,
            `created_by` INT NULL,
            `created_by_name` VARCHAR(120) NULL,
            `reviewed_by` INT NULL,
            `reviewed_by_name` VARCHAR(120) NULL,
            `reviewed_at` DATETIME NULL,
            `review_notes` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_app_notif_status` (`status`),
            INDEX `idx_app_notif_type` (`type`),
            INDEX `idx_app_notif_freezer` (`freezer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Upgrade older tables
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `app_notifications`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('created_by_name', $cols, true)) {
                $pdo->exec("ALTER TABLE `app_notifications` ADD COLUMN `created_by_name` VARCHAR(120) NULL AFTER `created_by`");
            }
            if (!in_array('reviewed_by_name', $cols, true)) {
                $pdo->exec("ALTER TABLE `app_notifications` ADD COLUMN `reviewed_by_name` VARCHAR(120) NULL AFTER `reviewed_by`");
            }
        } catch (\Throwable $e) {
            // ignore
        }
        self::$tableReady = true;
    }

    public static function countPending(?string $type = null): int {
        self::ensureTable();
        $pdo = Database::getConnection();
        if ($type) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `app_notifications` WHERE `status` = 'pending' AND `type` = :t");
            $stmt->execute(['t' => $type]);
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `app_notifications` WHERE `status` = 'pending'");
        }
        return (int)$stmt->fetchColumn();
    }

    public static function findPendingMoveForFreezer(int $freezerId): ?array {
        return self::findPendingForFreezer($freezerId, self::TYPE_KYC_STORE_MOVE);
    }

    public static function findPendingSameStoreKyc(int $freezerId): ?array {
        return self::findPendingForFreezer($freezerId, self::TYPE_KYC_SAME_STORE);
    }

    /** Any pending KYC notification (same store or move) for this freezer. */
    public static function findPendingKycForFreezer(int $freezerId): ?array {
        self::ensureTable();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `app_notifications`
            WHERE `freezer_id` = :fid AND `status` = 'pending'
              AND `type` IN (:t1, :t2)
            ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([
            'fid' => $freezerId,
            't1' => self::TYPE_KYC_STORE_MOVE,
            't2' => self::TYPE_KYC_SAME_STORE,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findPendingProfileEdit(int $freezerId): ?array {
        return self::findPendingForFreezer($freezerId, self::TYPE_PROFILE_EDIT);
    }

    /**
     * Approved edit-access unlock for this technician on this freezer (not yet used / not expired).
     * Unlock lasts 24 hours after approval, or until the technician saves an edit.
     */
    public static function findActiveEditUnlock(int $freezerId, int $userId): ?array {
        if ($freezerId <= 0 || $userId <= 0) {
            return null;
        }
        self::ensureTable();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `app_notifications`
            WHERE `freezer_id` = :fid
              AND `type` = :t
              AND `status` = :st
              AND `created_by` = :uid
            ORDER BY `id` DESC LIMIT 10");
        $stmt->execute([
            'fid' => $freezerId,
            't' => self::TYPE_PROFILE_EDIT,
            'st' => self::STATUS_APPROVED,
            'uid' => $userId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $payload = self::decodePayload($row);
            if (empty($payload['unlocked'])) {
                continue;
            }
            if (!empty($payload['consumed'])) {
                continue;
            }
            $unlockedAt = strtotime((string)($payload['unlocked_at'] ?? $row['reviewed_at'] ?? ''));
            if ($unlockedAt && (time() - $unlockedAt) > 86400) {
                continue; // expired
            }
            return $row;
        }
        return null;
    }

    public static function updatePayload(int $id, array $payload): void {
        self::ensureTable();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Exception('Could not encode notification payload.');
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE `app_notifications` SET `payload` = :p WHERE `id` = :id");
        $stmt->execute(['p' => $json, 'id' => $id]);
    }

    public static function consumeEditUnlock(int $id): void {
        $row = self::findById($id);
        if (!$row) {
            return;
        }
        $payload = self::decodePayload($row);
        $payload['consumed'] = true;
        $payload['consumed_at'] = date('Y-m-d H:i:s');
        self::updatePayload($id, $payload);
    }

    public static function findPendingForFreezer(int $freezerId, string $type): ?array {
        self::ensureTable();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `app_notifications`
            WHERE `freezer_id` = :fid AND `type` = :t AND `status` = 'pending'
            ORDER BY `id` DESC LIMIT 1");
        $stmt->execute(['fid' => $freezerId, 't' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(array $data): int {
        self::ensureTable();
        $pdo = Database::getConnection();
        $payload = $data['payload'] ?? [];
        if (!is_string($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($payload === false) {
            throw new Exception('Could not encode notification payload.');
        }
        $createdByName = trim((string)($data['created_by_name'] ?? ''));
        if ($createdByName === '' && !empty($data['created_by'])) {
            try {
                $u = User::findById((int)$data['created_by']);
                $createdByName = trim((string)($u['name'] ?? ''));
            } catch (\Throwable $e) {
                $createdByName = '';
            }
        }
        $stmt = $pdo->prepare("INSERT INTO `app_notifications`
            (`type`, `status`, `freezer_id`, `unique_freezer_id`, `title`, `message`, `payload`, `created_by`, `created_by_name`)
            VALUES (:type, :status, :fid, :ufid, :title, :msg, :payload, :by, :by_name)");
        $stmt->execute([
            'type' => $data['type'] ?? self::TYPE_KYC_STORE_MOVE,
            'status' => $data['status'] ?? self::STATUS_PENDING,
            'fid' => (int)$data['freezer_id'],
            'ufid' => $data['unique_freezer_id'] ?? null,
            'title' => $data['title'] ?? 'Notification',
            'msg' => $data['message'] ?? null,
            'payload' => $payload,
            'by' => $data['created_by'] ?? null,
            'by_name' => $createdByName !== '' ? $createdByName : null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function findById(int $id): ?array {
        self::ensureTable();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `app_notifications` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array> */
    public static function listAll(int $limit = 100, ?string $status = null): array {
        self::ensureTable();
        $pdo = Database::getConnection();
        $limit = max(1, min(500, $limit));
        if ($status) {
            $stmt = $pdo->prepare("SELECT * FROM `app_notifications` WHERE `status` = :s ORDER BY `id` DESC LIMIT {$limit}");
            $stmt->execute(['s' => $status]);
        } else {
            $stmt = $pdo->query("SELECT * FROM `app_notifications` ORDER BY
                CASE `status` WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
                `id` DESC LIMIT {$limit}");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Pending approvals stay active until admin approves/rejects (task incomplete).
     * @param array{zone?:string,city?:string} $filters
     * @return list<array>
     */
    public static function listPendingApprovals(int $limit = 8, array $filters = []): array {
        self::ensureTable();
        $pdo = Database::getConnection();
        $limit = max(1, min(50, $limit));

        $zone = strtoupper(trim((string)($filters['zone'] ?? '')));
        $city = trim((string)($filters['city'] ?? ''));
        if ($zone === 'ALL') {
            $zone = '';
        }
        if (strcasecmp($city, 'All') === 0) {
            $city = '';
        }

        // Technician profile edits (and any leftover KYC move requests)
        $sql = "SELECT n.*
                FROM `app_notifications` n
                LEFT JOIN `freezers` f ON f.id = n.freezer_id
                LEFT JOIN `stores` s ON s.id = f.store_id
                WHERE n.status = 'pending' AND n.type IN (:t1, :t2)";
        $params = [
            't1' => self::TYPE_PROFILE_EDIT,
            't2' => self::TYPE_KYC_STORE_MOVE,
        ];
        if ($zone !== '') {
            $sql .= " AND UPPER(TRIM(s.zone)) = :zone";
            $params['zone'] = $zone;
        }
        if ($city !== '') {
            $sql .= " AND s.city = :city";
            $params['city'] = $city;
        }
        $sql .= " ORDER BY n.id ASC LIMIT {$limit}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{zone?:string,city?:string} $filters
     */
    public static function countPendingApprovals(array $filters = []): int {
        self::ensureTable();
        $pdo = Database::getConnection();

        $zone = strtoupper(trim((string)($filters['zone'] ?? '')));
        $city = trim((string)($filters['city'] ?? ''));
        if ($zone === 'ALL') {
            $zone = '';
        }
        if (strcasecmp($city, 'All') === 0) {
            $city = '';
        }

        if ($zone === '' && $city === '') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `app_notifications`
                WHERE `status` = 'pending' AND `type` IN (:t1, :t2)");
            $stmt->execute([
                't1' => self::TYPE_PROFILE_EDIT,
                't2' => self::TYPE_KYC_STORE_MOVE,
            ]);
            return (int)$stmt->fetchColumn();
        }

        $sql = "SELECT COUNT(*)
                FROM `app_notifications` n
                LEFT JOIN `freezers` f ON f.id = n.freezer_id
                LEFT JOIN `stores` s ON s.id = f.store_id
                WHERE n.status = 'pending' AND n.type IN (:t1, :t2)";
        $params = [
            't1' => self::TYPE_PROFILE_EDIT,
            't2' => self::TYPE_KYC_STORE_MOVE,
        ];
        if ($zone !== '') {
            $sql .= " AND UPPER(TRIM(s.zone)) = :zone";
            $params['zone'] = $zone;
        }
        if ($city !== '') {
            $sql .= " AND s.city = :city";
            $params['city'] = $city;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function decodePayload(?array $row): array {
        if (!$row) {
            return [];
        }
        $raw = $row['payload'] ?? '';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function setStatus(int $id, string $status, ?int $reviewedBy, ?string $notes = null, ?string $reviewedByName = null): void {
        self::ensureTable();
        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_PENDING], true)) {
            throw new Exception('Invalid notification status.');
        }
        $name = trim((string)$reviewedByName);
        if ($name === '' && $reviewedBy) {
            try {
                $u = User::findById($reviewedBy);
                $name = trim((string)($u['name'] ?? ''));
            } catch (\Throwable $e) {
                $name = '';
            }
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE `app_notifications`
            SET `status` = :st,
                `reviewed_by` = :by,
                `reviewed_by_name` = :by_name,
                `reviewed_at` = NOW(),
                `review_notes` = :notes
            WHERE `id` = :id");
        $stmt->execute([
            'st' => $status,
            'by' => $reviewedBy,
            'by_name' => $name !== '' ? $name : null,
            'notes' => $notes,
            'id' => $id,
        ]);
    }

    /**
     * Approve any pending notification (profile edit / KYC).
     * @return array{type:string,outlet_id?:string,store_id?:int,freezer_id?:int}
     */
    public static function approve(int $id, int $adminId, ?string $notes = null): array {
        $row = self::findById($id);
        if (!$row) {
            throw new Exception('Notification not found.');
        }
        $type = (string)($row['type'] ?? '');
        if ($type === self::TYPE_PROFILE_EDIT) {
            self::approveProfileEdit($id, $adminId, $notes);
            return ['type' => $type, 'freezer_id' => (int)$row['freezer_id']];
        }
        if ($type === self::TYPE_KYC_STORE_MOVE) {
            $created = self::approveKycStoreMove($id, $adminId, $notes);
            return array_merge(['type' => $type], $created);
        }
        if ($type === self::TYPE_KYC_SAME_STORE) {
            self::approveKycSameStore($id, $adminId, $notes);
            return ['type' => $type];
        }
        throw new Exception('Unsupported notification type.');
    }

    /**
     * Approve technician edit-access request (remark first).
     * Grants a one-time unlock so the technician can open the edit form and save.
     * Legacy payloads that already include store/freezer changes are still applied.
     */
    public static function approveProfileEdit(int $id, int $adminId, ?string $notes = null): void {
        $row = self::findById($id);
        if (!$row) {
            throw new Exception('Notification not found.');
        }
        if (($row['type'] ?? '') !== self::TYPE_PROFILE_EDIT) {
            throw new Exception('Not a profile edit notification.');
        }
        if (($row['status'] ?? '') !== self::STATUS_PENDING) {
            throw new Exception('This request is already ' . ($row['status'] ?? 'closed') . '.');
        }

        $payload = self::decodePayload($row);
        $storeData = is_array($payload['store'] ?? null) ? $payload['store'] : [];
        $freezerData = is_array($payload['freezer'] ?? null) ? $payload['freezer'] : [];
        $freezerId = (int)$row['freezer_id'];

        // Legacy queued field changes (if any)
        if ($storeData !== [] || $freezerData !== []) {
            $freezer = Freezer::findById($freezerId);
            if (!$freezer) {
                throw new Exception('Freezer no longer exists.');
            }
            $storeId = (int)($freezer['store_id'] ?? 0);
            if ($storeId > 0 && $storeData !== []) {
                Store::update($storeId, $storeData);
            }
            if ($freezerData !== []) {
                $freezerData['store_id'] = $storeId;
                Freezer::update($freezerId, $freezerData);
                $payload['consumed'] = true;
                $payload['consumed_at'] = date('Y-m-d H:i:s');
            }
        }

        $payload['unlocked'] = true;
        $payload['unlocked_at'] = date('Y-m-d H:i:s');
        if (!array_key_exists('consumed', $payload)) {
            $payload['consumed'] = false;
        }
        $payload['request_kind'] = $payload['request_kind'] ?? 'edit_access';

        self::updatePayload($id, $payload);
        self::setStatus($id, self::STATUS_APPROVED, $adminId, $notes);
    }

    /** Approve same-store KYC — record verification on current profile only. */
    public static function approveKycSameStore(int $id, int $adminId, ?string $notes = null): void {
        $row = self::findById($id);
        if (!$row) {
            throw new Exception('Notification not found.');
        }
        if (($row['type'] ?? '') !== self::TYPE_KYC_SAME_STORE) {
            throw new Exception('Not a same-store KYC notification.');
        }
        if (($row['status'] ?? '') !== self::STATUS_PENDING) {
            throw new Exception('This request is already ' . ($row['status'] ?? 'closed') . '.');
        }

        $payload = self::decodePayload($row);
        $kycData = $payload['kyc'] ?? [];
        $freezerId = (int)$row['freezer_id'];

        $freezer = Freezer::findById($freezerId);
        if (!$freezer) {
            throw new Exception('Freezer no longer exists.');
        }

        FreezerLifecycle::recordKyc([
            'freezer_id' => $freezerId,
            'verified_at' => $kycData['verified_at'] ?? date('Y-m-d'),
            'cycle_year' => (int)($kycData['cycle_year'] ?? date('Y')),
            'cycle_half' => (int)($kycData['cycle_half'] ?? 1),
            'result' => $kycData['result'] ?? 'PASS',
            'notes' => $kycData['notes'] ?? null,
            'image_store_front' => $kycData['image_store_front'] ?? null,
            'image_freezer' => $kycData['image_freezer'] ?? null,
            'latitude' => $kycData['latitude'] ?? null,
            'longitude' => $kycData['longitude'] ?? null,
            'verified_by' => (int)($kycData['verified_by'] ?? $adminId),
        ]);

        self::setStatus($id, self::STATUS_APPROVED, $adminId, $notes);
    }

    /**
     * Approve a pending KYC store-move: create outlet, transfer freezer, record KYC.
     * @return array{outlet_id:string,store_id:int}
     */
    public static function approveKycStoreMove(int $id, int $adminId, ?string $notes = null): array {
        $row = self::findById($id);
        if (!$row) {
            throw new Exception('Notification not found.');
        }
        if (($row['type'] ?? '') !== self::TYPE_KYC_STORE_MOVE) {
            throw new Exception('Not a store-move notification.');
        }
        if (($row['status'] ?? '') !== self::STATUS_PENDING) {
            throw new Exception('This request is already ' . ($row['status'] ?? 'closed') . '.');
        }

        $payload = self::decodePayload($row);
        $storeData = $payload['store'] ?? [];
        $kycData = $payload['kyc'] ?? [];
        $freezerId = (int)$row['freezer_id'];

        $freezer = Freezer::findById($freezerId);
        if (!$freezer) {
            throw new Exception('Freezer no longer exists.');
        }

        $created = FreezerLifecycle::createNewOutlet([
            'store_name' => $storeData['store_name'] ?? '',
            'address' => $storeData['address'] ?? '',
            'city' => $storeData['city'] ?? '',
            'state' => $storeData['state'] ?? '',
            'pincode' => $storeData['pincode'] ?? '',
            'zone' => $storeData['zone'] ?? 'WEST',
            'contact_person' => $storeData['contact_person'] ?? ($freezer['contact_person'] ?? ''),
            'phone_number' => $storeData['phone_number'] ?? '',
            'aadhar_number' => $storeData['aadhar_number'] ?? '',
            'customer_name' => $storeData['customer_name'] ?? ($freezer['customer_name'] ?? 'Baskin Robbins'),
            'latitude' => $storeData['latitude'] ?? null,
            'longitude' => $storeData['longitude'] ?? null,
            'gps_accuracy' => $storeData['gps_accuracy'] ?? null,
            'gps_captured_at' => $storeData['gps_captured_at'] ?? null,
            'location_link' => $storeData['location_link'] ?? null,
            'image_store_front' => $storeData['image_store_front'] ?? null,
        ], $adminId);

        $verifiedAt = $kycData['verified_at'] ?? date('Y-m-d');
        FreezerLifecycle::transfer(
            $freezerId,
            $created['store_id'],
            'KYC — moved to new outlet (admin approved, auto ID ' . $created['outlet_id'] . ')',
            $kycData['notes'] ?? null,
            $adminId,
            $verifiedAt
        );

        FreezerLifecycle::recordKyc([
            'freezer_id' => $freezerId,
            'verified_at' => $verifiedAt,
            'cycle_year' => (int)($kycData['cycle_year'] ?? date('Y')),
            'cycle_half' => (int)($kycData['cycle_half'] ?? 1),
            'result' => $kycData['result'] ?? 'PASS',
            'notes' => $kycData['notes'] ?? null,
            'image_store_front' => $kycData['image_store_front'] ?? ($storeData['image_store_front'] ?? null),
            'image_freezer' => $kycData['image_freezer'] ?? null,
            'latitude' => $kycData['latitude'] ?? ($storeData['latitude'] ?? null),
            'longitude' => $kycData['longitude'] ?? ($storeData['longitude'] ?? null),
            'verified_by' => (int)($kycData['verified_by'] ?? $adminId),
        ]);

        self::setStatus($id, self::STATUS_APPROVED, $adminId, $notes);

        return $created;
    }

    public static function reject(int $id, int $adminId, ?string $notes = null): void {
        $row = self::findById($id);
        if (!$row) {
            throw new Exception('Notification not found.');
        }
        if (($row['status'] ?? '') !== self::STATUS_PENDING) {
            throw new Exception('This request is already ' . ($row['status'] ?? 'closed') . '.');
        }
        self::setStatus($id, self::STATUS_REJECTED, $adminId, $notes);
    }
}
