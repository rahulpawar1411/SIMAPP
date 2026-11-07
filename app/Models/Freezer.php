<?php
namespace App\Models;

use App\Config\AppConfig;
use App\Config\Database;
use App\Services\FreezerIdGenerator;
use App\Services\QRCodeService;
use App\Services\FileUploadService;
use App\Models\AssetTag;
use PDO;
use Exception;

class Freezer {

    /** Ensure SIM installation columns exist on freezers. */
    public static function ensureSimColumns(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo = Database::getConnection();
        $cols = [
            'sim_number' => "ALTER TABLE `freezers` ADD COLUMN `sim_number` VARCHAR(40) NULL",
            'sim_company' => "ALTER TABLE `freezers` ADD COLUMN `sim_company` VARCHAR(50) NULL",
            'image_sim' => "ALTER TABLE `freezers` ADD COLUMN `image_sim` VARCHAR(500) NULL",
            'deleted_at' => "ALTER TABLE `freezers` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL",
            'created_by_name' => "ALTER TABLE `freezers` ADD COLUMN `created_by_name` VARCHAR(120) NULL",
        ];
        foreach ($cols as $name => $sql) {
            $exists = $pdo->query("SHOW COLUMNS FROM `freezers` LIKE " . $pdo->quote($name))->fetch();
            if (!$exists) {
                $pdo->exec($sql);
            }
        }
        $done = true;
    }

    /** Soft-deleted rows are hidden unless includeDeleted is true. */
    private static function notDeletedSql(string $alias = 'f'): string {
        self::ensureSimColumns();
        return "({$alias}.deleted_at IS NULL)";
    }

    public static function create(array $data): int {
        $pdo = Database::getConnection();
        self::ensureSimColumns();

        // 1. Verify or generate unique ID
        if (empty($data['unique_freezer_id'])) {
            $gen = FreezerIdGenerator::generateUniqueId(
                $data['freezer_type'],
                $data['company_code'],
                $data['model_number'],
                $data['location_code'],
                $data['pincode'],
                $data['install_month_year'] ?? date('my'),
                $data['unit_sequence'] ?? null
            );
            $uniqueId = $gen['freezer_id'];
            $unitSeq = $gen['unit_sequence'];
            $pinLast3 = $gen['pin_last3'];
            $installMmYy = $gen['install_month_year'];
        } else {
            $uniqueId = strtoupper(trim($data['unique_freezer_id']));
            if (FreezerIdGenerator::isDuplicate($uniqueId)) {
                throw new Exception("Freezer ID '{$uniqueId}' already exists in the system.");
            }
            $validation = FreezerIdGenerator::validateIdFormat($uniqueId);
            if (!$validation['valid']) {
                throw new Exception($validation['error']);
            }
            $unitSeq = $validation['parts']['unit_sequence'];
            $pinLast3 = $validation['parts']['pincode_last3'];
            $installMmYy = $validation['parts']['install_month_year'];
        }

        // 2. QR: reuse blank asset tag sticker when provided; else generate from Unique ID
        $assetTagCode = strtoupper(trim((string)($data['asset_tag_code'] ?? '')));
        $blankTag = null;
        if ($assetTagCode !== '') {
            $blankTag = AssetTag::findByCode($assetTagCode);
            if (!$blankTag) {
                throw new Exception("Blank QR tag '{$assetTagCode}' not found.");
            }
            if ($blankTag['status'] === 'assigned') {
                throw new Exception("Blank QR tag '{$assetTagCode}' is already assigned.");
            }
            $qrPath = $blankTag['qr_code_path'] ?? null;
            $qrData = $blankTag['qr_code_data'] ?? QRCodeService::getTagScanUrl($assetTagCode);
        } else {
            $qrResult = QRCodeService::generateAndSave($uniqueId);
            $qrPath = $qrResult['file_path'] ?? null;
            $qrData = $qrResult['payload'] ?? $uniqueId;
        }

        // 3. Insert Freezer Record
        $createdByName = trim((string)($data['created_by_name'] ?? ''));
        if ($createdByName === '' && !empty($data['created_by'])) {
            try {
                $u = User::findById((int)$data['created_by']);
                $createdByName = trim((string)($u['name'] ?? ''));
            } catch (\Throwable $e) {
                $createdByName = '';
            }
        }

        $sql = "INSERT INTO `freezers` (
            `unique_freezer_id`, `store_id`, `freezer_type`, `company_code`,
            `model_number`, `capacity_litres`, `freezer_condition`, `location_code`,
            `pincode_last3`, `install_month_year`, `unit_sequence`, `installation_date`,
            `image_left_side`, `image_right_side`, `image_iot_device`, `image_sim`,
            `qr_code_path`, `qr_code_data`, `status`, `notes`, `sim_number`, `sim_company`,
            `created_by`, `created_by_name`
        ) VALUES (
            :unique_freezer_id, :store_id, :freezer_type, :company_code,
            :model_number, :capacity_litres, :freezer_condition, :location_code,
            :pincode_last3, :install_month_year, :unit_sequence, :installation_date,
            :image_left_side, :image_right_side, :image_iot_device, :image_sim,
            :qr_code_path, :qr_code_data, :status, :notes, :sim_number, :sim_company,
            :created_by, :created_by_name
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'unique_freezer_id' => $uniqueId,
            'store_id' => (int)$data['store_id'],
            'freezer_type' => strtoupper(trim($data['freezer_type'])),
            'company_code' => strtoupper(trim($data['company_code'])),
            'model_number' => FreezerIdGenerator::sanitizeModel($data['model_number']),
            'capacity_litres' => !empty($data['capacity_litres']) ? (int)$data['capacity_litres'] : null,
            'freezer_condition' => $data['freezer_condition'] ?? 'WORKING CONDITION',
            'location_code' => strtoupper(trim($data['location_code'])),
            'pincode_last3' => $pinLast3,
            'install_month_year' => $installMmYy,
            'unit_sequence' => $unitSeq,
            'installation_date' => $data['installation_date'] ?? date('Y-m-d'),
            'image_left_side' => $data['image_left_side'] ?? null,
            'image_right_side' => $data['image_right_side'] ?? null,
            'image_iot_device' => $data['image_iot_device'] ?? null,
            'image_sim' => $data['image_sim'] ?? null,
            'qr_code_path' => $qrPath,
            'qr_code_data' => $qrData,
            'status' => $data['status'] ?? 'Active',
            'notes' => $data['notes'] ?? null,
            'sim_number' => trim((string)($data['sim_number'] ?? '')) ?: null,
            'sim_company' => trim((string)($data['sim_company'] ?? '')) ?: null,
            'created_by' => $data['created_by'] ?? null,
            'created_by_name' => $createdByName !== '' ? $createdByName : null,
        ]);

        $freezerId = (int)$pdo->lastInsertId();

        if ($blankTag) {
            AssetTag::assignToFreezer($assetTagCode, $freezerId);
        }

        // 4. Create/Link IoT Device record if provided (next phase)
        if (AppConfig::IOT_ENABLED && !empty($data['iot_serial'])) {
            IoTDevice::registerDevice($freezerId, [
                'device_serial' => trim($data['iot_serial']),
                'imei_or_mac' => $data['iot_imei'] ?? null,
                'sim_number' => $data['iot_sim_number'] ?? null,
                'sim_company' => $data['iot_sim_company'] ?? null,
                'device_type' => $data['iot_type'] ?? 'Temperature & GPS',
                'device_owner' => $data['iot_owner'] ?? 'ReeferON Own Device',
                'oem_model' => $data['iot_model'] ?? 'RF-T100',
            ]);
        }

        // 4b. Open placement history (freezer may later move to another store)
        FreezerLifecycle::openPlacement(
            $freezerId,
            (int)$data['store_id'],
            $data['installation_date'] ?? date('Y-m-d'),
            $data['created_by'] ?? null,
            'Initial placement',
            'Created with profile'
        );

        // 5. Add Audit Log
        self::logAudit('CREATE_PROFILE', $freezerId, $uniqueId, "Freezer {$uniqueId} created for store ID {$data['store_id']}");

        return $freezerId;
    }

    public static function update(int $id, array $data): bool {
        $pdo = Database::getConnection();
        self::ensureSimColumns();
        $existing = self::findById($id);
        if (!$existing) {
            throw new Exception("Freezer profile #{$id} not found.");
        }

        $uniqueId = strtoupper(trim($data['unique_freezer_id'] ?? $existing['unique_freezer_id']));
        if ($uniqueId !== $existing['unique_freezer_id']) {
            if (FreezerIdGenerator::isDuplicate($uniqueId, $id)) {
                throw new Exception("Freezer ID '{$uniqueId}' is already used by another record.");
            }
            $val = FreezerIdGenerator::validateIdFormat($uniqueId);
            if (!$val['valid']) {
                throw new Exception($val['error']);
            }
            // Regenerate QR code if ID changed
            $qrResult = QRCodeService::generateAndSave($uniqueId);
            $qrPath = $qrResult['file_path'];
            $qrData = $qrResult['payload'];
        } else {
            $qrPath = $existing['qr_code_path'];
            $qrData = $existing['qr_code_data'];
        }

        // Images: retain old if new one is not uploaded
        $imgLeft = !empty($data['image_left_side']) ? $data['image_left_side'] : $existing['image_left_side'];
        $imgRight = !empty($data['image_right_side']) ? $data['image_right_side'] : $existing['image_right_side'];
        $imgIot = !empty($data['image_iot_device']) ? $data['image_iot_device'] : $existing['image_iot_device'];
        $imgSim = array_key_exists('image_sim', $data) && !empty($data['image_sim'])
            ? $data['image_sim']
            : ($existing['image_sim'] ?? null);

        $sql = "UPDATE `freezers` SET
            `unique_freezer_id` = :unique_freezer_id,
            `store_id` = :store_id,
            `freezer_type` = :freezer_type,
            `company_code` = :company_code,
            `model_number` = :model_number,
            `capacity_litres` = :capacity_litres,
            `freezer_condition` = :freezer_condition,
            `location_code` = :location_code,
            `pincode_last3` = :pincode_last3,
            `install_month_year` = :install_month_year,
            `unit_sequence` = :unit_sequence,
            `installation_date` = :installation_date,
            `image_left_side` = :image_left_side,
            `image_right_side` = :image_right_side,
            `image_iot_device` = :image_iot_device,
            `image_sim` = :image_sim,
            `qr_code_path` = :qr_code_path,
            `qr_code_data` = :qr_code_data,
            `status` = :status,
            `notes` = :notes,
            `sim_number` = :sim_number,
            `sim_company` = :sim_company
        WHERE `id` = :id";

        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute([
            'id' => $id,
            'unique_freezer_id' => $uniqueId,
            'store_id' => (int)($data['store_id'] ?? $existing['store_id']),
            'freezer_type' => strtoupper(trim($data['freezer_type'] ?? $existing['freezer_type'])),
            'company_code' => strtoupper(trim($data['company_code'] ?? $existing['company_code'])),
            'model_number' => FreezerIdGenerator::sanitizeModel($data['model_number'] ?? $existing['model_number']),
            'capacity_litres' => !empty($data['capacity_litres']) ? (int)$data['capacity_litres'] : $existing['capacity_litres'],
            'freezer_condition' => $data['freezer_condition'] ?? $existing['freezer_condition'],
            'location_code' => strtoupper(trim($data['location_code'] ?? $existing['location_code'])),
            'pincode_last3' => $data['pincode_last3'] ?? $existing['pincode_last3'],
            'install_month_year' => $data['install_month_year'] ?? $existing['install_month_year'],
            'unit_sequence' => $data['unit_sequence'] ?? $existing['unit_sequence'],
            'installation_date' => $data['installation_date'] ?? $existing['installation_date'],
            'image_left_side' => $imgLeft,
            'image_right_side' => $imgRight,
            'image_iot_device' => $imgIot,
            'image_sim' => $imgSim,
            'qr_code_path' => $qrPath,
            'qr_code_data' => $qrData,
            'status' => $data['status'] ?? $existing['status'],
            'notes' => $data['notes'] ?? $existing['notes'],
            'sim_number' => array_key_exists('sim_number', $data)
                ? (trim((string)$data['sim_number']) ?: null)
                : ($existing['sim_number'] ?? null),
            'sim_company' => array_key_exists('sim_company', $data)
                ? (trim((string)$data['sim_company']) ?: null)
                : ($existing['sim_company'] ?? null),
        ]);

        // Update IoT device info if passed (next phase)
        if (AppConfig::IOT_ENABLED && !empty($data['iot_serial'])) {
            IoTDevice::registerOrUpdateDevice($id, [
                'device_serial' => trim($data['iot_serial']),
                'imei_or_mac' => $data['iot_imei'] ?? null,
                'sim_number' => $data['iot_sim_number'] ?? null,
                'sim_company' => $data['iot_sim_company'] ?? null,
                'device_type' => $data['iot_type'] ?? 'Temperature & GPS',
                'device_owner' => $data['iot_owner'] ?? 'ReeferON Own Device',
                'oem_model' => $data['iot_model'] ?? 'RF-T100',
            ]);
        }

        self::logAudit('UPDATE_PROFILE', $id, $uniqueId, "Freezer {$uniqueId} updated");

        return $res;
    }

    /** Soft-delete so Undo can restore within the toast window. */
    public static function delete(int $id): bool {
        $pdo = Database::getConnection();
        self::ensureSimColumns();
        $freezer = self::findById($id);
        if (!$freezer) {
            return false;
        }

        $stmt = $pdo->prepare("UPDATE `freezers` SET `deleted_at` = NOW() WHERE `id` = :id AND `deleted_at` IS NULL");
        $deleted = $stmt->execute(['id' => $id]);

        if ($deleted && $stmt->rowCount() > 0) {
            self::logAudit('DELETE_PROFILE', $id, $freezer['unique_freezer_id'], "Freezer {$freezer['unique_freezer_id']} soft-deleted");
            return true;
        }

        return false;
    }

    public static function restoreDeleted(int $id): bool {
        $pdo = Database::getConnection();
        self::ensureSimColumns();
        $freezer = self::findById($id, true);
        if (!$freezer || empty($freezer['deleted_at'])) {
            return false;
        }
        $stmt = $pdo->prepare("UPDATE `freezers` SET `deleted_at` = NULL WHERE `id` = :id");
        $ok = $stmt->execute(['id' => $id]);
        if ($ok) {
            self::logAudit('RESTORE_PROFILE', $id, $freezer['unique_freezer_id'], "Freezer {$freezer['unique_freezer_id']} restored");
        }
        return $ok;
    }

    /** Force-write freezer columns from an undo snapshot (including null images). */
    public static function restoreFreezerSnapshot(int $id, array $data): bool {
        $pdo = Database::getConnection();
        self::ensureSimColumns();
        $sql = "UPDATE `freezers` SET
            `unique_freezer_id` = :unique_freezer_id,
            `store_id` = :store_id,
            `freezer_type` = :freezer_type,
            `company_code` = :company_code,
            `model_number` = :model_number,
            `capacity_litres` = :capacity_litres,
            `freezer_condition` = :freezer_condition,
            `location_code` = :location_code,
            `pincode_last3` = :pincode_last3,
            `install_month_year` = :install_month_year,
            `unit_sequence` = :unit_sequence,
            `installation_date` = :installation_date,
            `image_left_side` = :image_left_side,
            `image_right_side` = :image_right_side,
            `image_iot_device` = :image_iot_device,
            `image_sim` = :image_sim,
            `qr_code_path` = :qr_code_path,
            `qr_code_data` = :qr_code_data,
            `status` = :status,
            `notes` = :notes,
            `sim_number` = :sim_number,
            `sim_company` = :sim_company,
            `deleted_at` = NULL
        WHERE `id` = :id";

        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([
            'id' => $id,
            'unique_freezer_id' => $data['unique_freezer_id'] ?? null,
            'store_id' => (int)($data['store_id'] ?? 0),
            'freezer_type' => $data['freezer_type'] ?? null,
            'company_code' => $data['company_code'] ?? null,
            'model_number' => $data['model_number'] ?? null,
            'capacity_litres' => $data['capacity_litres'] ?? null,
            'freezer_condition' => $data['freezer_condition'] ?? null,
            'location_code' => $data['location_code'] ?? null,
            'pincode_last3' => $data['pincode_last3'] ?? null,
            'install_month_year' => $data['install_month_year'] ?? null,
            'unit_sequence' => $data['unit_sequence'] ?? null,
            'installation_date' => $data['installation_date'] ?? null,
            'image_left_side' => $data['image_left_side'] ?? null,
            'image_right_side' => $data['image_right_side'] ?? null,
            'image_iot_device' => $data['image_iot_device'] ?? null,
            'image_sim' => $data['image_sim'] ?? null,
            'qr_code_path' => $data['qr_code_path'] ?? null,
            'qr_code_data' => $data['qr_code_data'] ?? null,
            'status' => $data['status'] ?? 'Active',
            'notes' => $data['notes'] ?? null,
            'sim_number' => $data['sim_number'] ?? null,
            'sim_company' => $data['sim_company'] ?? null,
        ]);
        if ($ok) {
            self::logAudit('UNDO_EDIT', $id, $data['unique_freezer_id'] ?? null, 'Freezer edit undone');
        }
        return $ok;
    }

    /** Force-write store columns from an undo snapshot. */
    public static function restoreStoreSnapshot(int $storeId, array $data): bool {
        $pdo = Database::getConnection();
        $sql = "UPDATE `stores` SET
            `outlet_id` = :outlet_id,
            `store_name` = :store_name,
            `customer_name` = :customer_name,
            `distributor_name` = :distributor_name,
            `contact_person` = :contact_person,
            `phone_number` = :phone_number,
            `email` = :email,
            `aadhar_number` = :aadhar_number,
            `fssai_number` = :fssai_number,
            `gst_number` = :gst_number,
            `address` = :address,
            `city` = :city,
            `state` = :state,
            `pincode` = :pincode,
            `zone` = :zone,
            `location_type` = :location_type,
            `latitude` = :latitude,
            `longitude` = :longitude,
            `gps_accuracy` = :gps_accuracy,
            `gps_captured_at` = :gps_captured_at,
            `location_link` = :location_link,
            `image_store_front` = :image_store_front,
            `image_aadhar_card` = :image_aadhar_card,
            `image_aadhar_back` = :image_aadhar_back,
            `status` = :status
        WHERE `id` = :id";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            'id' => $storeId,
            'outlet_id' => $data['outlet_id'] ?? null,
            'store_name' => $data['store_name'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'distributor_name' => $data['distributor_name'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'email' => $data['email'] ?? null,
            'aadhar_number' => $data['aadhar_number'] ?? null,
            'fssai_number' => $data['fssai_number'] ?? null,
            'gst_number' => $data['gst_number'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'zone' => $data['zone'] ?? null,
            'location_type' => $data['location_type'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'gps_accuracy' => $data['gps_accuracy'] ?? null,
            'gps_captured_at' => $data['gps_captured_at'] ?? null,
            'location_link' => $data['location_link'] ?? null,
            'image_store_front' => $data['image_store_front'] ?? null,
            'image_aadhar_card' => $data['image_aadhar_card'] ?? null,
            'image_aadhar_back' => $data['image_aadhar_back'] ?? null,
            'status' => $data['status'] ?? 'Active',
        ]);
    }

    public static function findById(int $id, bool $includeDeleted = false): ?array {
        $pdo = Database::getConnection();
        self::ensureSimColumns();
        $deletedClause = $includeDeleted ? '' : ' AND ' . self::notDeletedSql('f');
        $sql = "SELECT f.*, 
                       s.store_name, s.outlet_id, s.customer_name, s.distributor_name,
                       s.contact_person, s.phone_number, s.email as store_email, s.aadhar_number,
                       s.fssai_number, s.gst_number,
                       s.address as store_address, s.city as store_city, s.state as store_state,
                       s.pincode as store_pincode, s.zone as store_zone, s.location_type,
                       s.latitude as store_latitude, s.longitude as store_longitude,
                       s.gps_accuracy as store_gps_accuracy, s.gps_captured_at as store_gps_captured_at,
                       s.location_link as store_location_link,
                       s.image_store_front, s.image_aadhar_card, s.image_aadhar_back,
                       ft.name as type_name,
                       b.name as brand_name,
                       iot.device_serial, iot.imei_or_mac, iot.sim_number, iot.sim_company,
                       iot.device_type, iot.device_owner,
                       iot.oem_model as iot_model, iot.battery_percentage, iot.power_status as iot_power_status,
                       iot.last_ping_at as iot_last_ping
                FROM `freezers` f
                INNER JOIN `stores` s ON f.store_id = s.id
                LEFT JOIN `freezer_types` ft ON f.freezer_type = ft.code
                LEFT JOIN `brands` b ON f.company_code = b.code
                LEFT JOIN `iot_devices` iot ON f.id = iot.freezer_id
                WHERE f.id = :id{$deletedClause}
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByUniqueId(string $uniqueId): ?array {
        $pdo = Database::getConnection();
        self::ensureSimColumns();
        $stmt = $pdo->prepare(
            "SELECT id FROM `freezers` WHERE `unique_freezer_id` = :uid AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute(['uid' => trim($uniqueId)]);
        $id = $stmt->fetchColumn();
        return $id ? self::findById((int)$id) : null;
    }

    /**
     * Public wrapper for export queries (WHERE + params).
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    public static function exportFilterParts(array $filters): array {
        [$where, $params] = self::buildListFilters($filters);
        return [$where, $params];
    }

    /**
     * Build WHERE + params for list/count filters (incl. Missing gaps).
     *
     * @return array{0: list<string>, 1: array<string, mixed>, 2: bool} [where, params, needsIotJoin]
     */
    /** Normalize GET filter to list of non-empty strings (supports multi checkbox). */
    private static function normalizeFilterList(mixed $value): array {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        $out = [];
        foreach ($value as $item) {
            $s = trim((string)$item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    private static function buildListFilters(array $filters): array {
        self::ensureSimColumns();
        $where = [self::notDeletedSql('f')];
        $params = [];
        $needsIotJoin = false;

        if (!empty($filters['q'])) {
            // PDO native prepares: same named placeholder cannot be reused — use q0..q5
            $qVal = '%' . trim((string)$filters['q']) . '%';
            $where[] = "(f.unique_freezer_id LIKE :q0
                        OR s.store_name LIKE :q1
                        OR s.outlet_id LIKE :q2
                        OR s.city LIKE :q3
                        OR f.model_number LIKE :q4
                        OR EXISTS (
                            SELECT 1 FROM `asset_tags` atq
                            WHERE atq.freezer_id = f.id AND atq.tag_code LIKE :q5
                        ))";
            $params['q0'] = $qVal;
            $params['q1'] = $qVal;
            $params['q2'] = $qVal;
            $params['q3'] = $qVal;
            $params['q4'] = $qVal;
            $params['q5'] = $qVal;
        }

        $types = self::normalizeFilterList($filters['type'] ?? null);
        if ($types) {
            $ph = [];
            foreach ($types as $i => $code) {
                $key = 'type' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $code;
            }
            $where[] = 'f.freezer_type IN (' . implode(',', $ph) . ')';
        }

        $brands = self::normalizeFilterList($filters['brand'] ?? null);
        if ($brands) {
            $ph = [];
            foreach ($brands as $i => $code) {
                $key = 'brand' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $code;
            }
            $where[] = 'f.company_code IN (' . implode(',', $ph) . ')';
        }

        $statuses = self::normalizeFilterList($filters['status'] ?? null);
        if ($statuses) {
            $ph = [];
            foreach ($statuses as $i => $code) {
                $key = 'status' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $code;
            }
            $where[] = 'f.status IN (' . implode(',', $ph) . ')';
        }

        $zones = self::normalizeFilterList($filters['zone'] ?? null);
        if ($zones) {
            $ph = [];
            foreach ($zones as $i => $code) {
                $key = 'zone' . $i;
                $ph[] = ':' . $key;
                $params[$key] = strtoupper(trim((string)$code));
            }
            $where[] = 'UPPER(TRIM(s.zone)) IN (' . implode(',', $ph) . ')';
        }

        // Technician’s own installs (data they filled)
        if (isset($filters['created_by']) && (int)$filters['created_by'] > 0) {
            $where[] = 'f.created_by = :created_by';
            $params['created_by'] = (int)$filters['created_by'];
        }

        $cities = self::normalizeFilterList($filters['city'] ?? null);
        if ($cities) {
            $ph = [];
            foreach ($cities as $i => $code) {
                $key = 'city' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $code;
            }
            $where[] = 's.city IN (' . implode(',', $ph) . ')';
        }

        $missingList = self::normalizeFilterList($filters['missing'] ?? null);
        if ($missingList) {
            $gapParts = [];
            foreach ($missingList as $missing) {
                switch ($missing) {
                    case 'gps':
                        $gapParts[] = '(s.latitude IS NULL OR s.longitude IS NULL)';
                        break;
                    case 'gps_approx':
                        $gapParts[] = '(s.latitude IS NOT NULL AND s.longitude IS NOT NULL AND s.gps_accuracy IS NOT NULL AND s.gps_accuracy >= 1000)';
                        break;
                    case 'gps_exact':
                        $gapParts[] = '(s.latitude IS NOT NULL AND s.longitude IS NOT NULL AND (s.gps_accuracy IS NULL OR s.gps_accuracy < 1000))';
                        break;
                    case 'photos':
                        $gapParts[] = "(s.image_store_front IS NULL OR s.image_store_front = ''
                                 OR f.image_left_side IS NULL OR f.image_left_side = ''
                                 OR f.image_right_side IS NULL OR f.image_right_side = '')";
                        break;
                    case 'iot':
                        if (AppConfig::IOT_ENABLED) {
                            $needsIotJoin = true;
                            $gapParts[] = 'iot.id IS NULL';
                        }
                        break;
                    case 'any':
                        $anyGaps = "s.latitude IS NULL OR s.longitude IS NULL
                                 OR (s.gps_accuracy IS NOT NULL AND s.gps_accuracy >= 1000)
                                 OR s.image_store_front IS NULL OR s.image_store_front = ''
                                 OR f.image_left_side IS NULL OR f.image_left_side = ''
                                 OR f.image_right_side IS NULL OR f.image_right_side = ''";
                        if (AppConfig::IOT_ENABLED) {
                            $needsIotJoin = true;
                            $anyGaps .= ' OR iot.id IS NULL';
                        }
                        $gapParts[] = "({$anyGaps})";
                        break;
                }
            }
            if ($gapParts) {
                $where[] = '(' . implode(' OR ', $gapParts) . ')';
            }
        }

        // KYC filter — pending / done (window) / failed (latest result at current store)
        $kycList = self::normalizeFilterList($filters['kyc'] ?? null);
        $kycList = array_values(array_intersect($kycList, ['pending', 'done', 'failed']));
        $lastKycResultExpr = "(SELECT k2.result FROM `kyc_verifications` k2
            WHERE k2.freezer_id = f.id AND k2.store_id = f.store_id
            ORDER BY k2.verified_at DESC, k2.id DESC LIMIT 1)";
        if ($kycList) {
            $gapMonths = (int)FreezerLifecycle::KYC_GAP_MONTHS;
            if ($gapMonths < 1) {
                $gapMonths = 6;
            }
            $dueExpr = FreezerLifecycle::kycDueSqlExpr('f', $gapMonths);
            $kycParts = [];
            $needsToday = in_array('pending', $kycList, true) || in_array('done', $kycList, true);
            if ($needsToday) {
                // Quote once in SQL — avoid unused / duplicate named PDO binds.
                $todaySql = Database::getConnection()->quote(date('Y-m-d'));
                if (in_array('pending', $kycList, true)) {
                    $kycParts[] = "{$dueExpr} <= {$todaySql}";
                }
                if (in_array('done', $kycList, true)) {
                    $kycParts[] = "{$dueExpr} > {$todaySql}";
                }
            }
            if (in_array('failed', $kycList, true)) {
                $kycParts[] = "UPPER(TRIM(COALESCE({$lastKycResultExpr}, ''))) = 'FAIL'";
            }
            if ($kycParts) {
                $where[] = '(' . implode(' OR ', $kycParts) . ')';
            }
        }

        return [$where, $params, $needsIotJoin];
    }

    public static function getFiltered(array $filters = [], int $limit = 50, int $offset = 0): array {
        $pdo = Database::getConnection();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$where, $params] = self::buildListFilters($filters);
        $whereClause = implode(' AND ', $where);

        $sql = "SELECT f.*, 
                       s.store_name, s.outlet_id, s.city as store_city, s.state as store_state,
                       s.latitude as store_latitude, s.longitude as store_longitude,
                       s.gps_accuracy as store_gps_accuracy,
                       s.image_store_front as store_image_front,
                       ft.name as type_name, b.name as brand_name,
                       iot.device_serial, iot.power_status as iot_power_status,
                       (
                           SELECT at.tag_code FROM `asset_tags` at
                           WHERE at.freezer_id = f.id
                           ORDER BY at.id DESC LIMIT 1
                       ) AS tag_code,
                       (
                           SELECT MAX(k.verified_at) FROM `kyc_verifications` k
                           WHERE k.freezer_id = f.id AND k.store_id = f.store_id
                       ) AS last_kyc_at,
                       (
                           SELECT k3.result FROM `kyc_verifications` k3
                           WHERE k3.freezer_id = f.id AND k3.store_id = f.store_id
                           ORDER BY k3.verified_at DESC, k3.id DESC LIMIT 1
                       ) AS last_kyc_result
                FROM `freezers` f
                INNER JOIN `stores` s ON f.store_id = s.id
                LEFT JOIN `freezer_types` ft ON f.freezer_type = ft.code
                LEFT JOIN `brands` b ON f.company_code = b.code
                LEFT JOIN `iot_devices` iot ON f.id = iot.freezer_id
                WHERE {$whereClause}
                ORDER BY f.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function countFiltered(array $filters = []): int {
        $pdo = Database::getConnection();
        [$where, $params, $needsIotJoin] = self::buildListFilters($filters);
        $whereClause = implode(' AND ', $where);

        $iotJoin = $needsIotJoin
            ? 'LEFT JOIN `iot_devices` iot ON f.id = iot.freezer_id'
            : '';

        $sql = "SELECT COUNT(*)
                FROM `freezers` f
                INNER JOIN `stores` s ON f.store_id = s.id
                {$iotJoin}
                WHERE {$whereClause}";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array{zone?:string,city?:string} $filters  empty zone/city = All
     */
    public static function getDashboardStats(array $filters = []): array {
        $pdo = Database::getConnection();
        self::ensureSimColumns();

        $zone = strtoupper(trim((string)($filters['zone'] ?? '')));
        $city = trim((string)($filters['city'] ?? ''));
        if ($zone === 'ALL') {
            $zone = '';
        }
        if (strcasecmp($city, 'All') === 0) {
            $city = '';
        }

        $where = ['f.deleted_at IS NULL'];
        $params = [];
        if ($zone !== '') {
            $where[] = 'UPPER(TRIM(s.zone)) = :zone';
            $params['zone'] = $zone;
        }
        if ($city !== '') {
            $where[] = 's.city = :city';
            $params['city'] = $city;
        }
        $whereSql = implode(' AND ', $where);
        // Filter needs a store match; "All" keeps freezers even without a store.
        $join = ($zone !== '' || $city !== '') ? 'INNER' : 'LEFT';
        $fromSql = "FROM `freezers` f {$join} JOIN `stores` s ON s.id = f.store_id";

        $stmt = $pdo->prepare("SELECT COUNT(*) {$fromSql} WHERE {$whereSql}");
        $stmt->execute($params);
        $totalFreezers = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT f.store_id) {$fromSql}
             WHERE {$whereSql} AND f.store_id IS NOT NULL"
        );
        $stmt->execute($params);
        $totalStores = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) {$fromSql} WHERE {$whereSql} AND f.status = 'Active'");
        $stmt->execute($params);
        $activeUnits = (int)$stmt->fetchColumn();

        $totalIot = (int)$pdo->query("SELECT COUNT(*) FROM `iot_devices`")->fetchColumn();

        $stmtType = $pdo->prepare(
            "SELECT f.freezer_type, ft.name, COUNT(*) as cnt
             {$fromSql}
             LEFT JOIN `freezer_types` ft ON f.freezer_type = ft.code
             WHERE {$whereSql}
             GROUP BY f.freezer_type, ft.name"
        );
        $stmtType->execute($params);
        $byType = $stmtType->fetchAll();

        $recentStmt = $pdo->prepare(
            "SELECT f.*, s.store_name, s.city as store_city, s.zone as store_zone
             {$fromSql}
             WHERE {$whereSql}
             ORDER BY f.id DESC LIMIT 5"
        );
        $recentStmt->execute($params);
        $recent = $recentStmt->fetchAll();

        // Installation trends (period: month | 6months | year | custom)
        $trendMeta = self::resolveTrendRange($filters);
        $trend = [];
        $bucket = $trendMeta['bucket']; // day | month
        if ($bucket === 'day') {
            $cursor = strtotime($trendMeta['from']);
            $endTs = strtotime($trendMeta['to']);
            while ($cursor !== false && $cursor <= $endTs) {
                $key = date('Y-m-d', $cursor);
                $trend[$key] = [
                    'label' => date('d M', $cursor),
                    'count' => 0,
                    'target' => 0,
                ];
                $cursor = strtotime('+1 day', $cursor);
            }
            $groupExpr = "DATE(f.installation_date)";
        } else {
            $cursor = strtotime(date('Y-m-01', strtotime($trendMeta['from'])));
            $endTs = strtotime(date('Y-m-01', strtotime($trendMeta['to'])));
            while ($cursor !== false && $cursor <= $endTs) {
                $key = date('Y-m', $cursor);
                $trend[$key] = [
                    'label' => date('M Y', $cursor),
                    'count' => 0,
                    'target' => 0,
                ];
                $cursor = strtotime('+1 month', $cursor);
            }
            $groupExpr = "DATE_FORMAT(f.installation_date, '%Y-%m')";
        }

        $trendParams = $params;
        $trendParams['trend_from'] = $trendMeta['from'];
        $trendParams['trend_to'] = $trendMeta['to'];
        $trendStmt = $pdo->prepare(
            "SELECT {$groupExpr} AS bucket_key, COUNT(*) AS cnt
             {$fromSql}
             WHERE {$whereSql}
               AND f.installation_date IS NOT NULL
               AND f.installation_date >= :trend_from
               AND f.installation_date <= :trend_to
             GROUP BY bucket_key"
        );
        $trendStmt->execute($trendParams);
        foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $key = (string)($r['bucket_key'] ?? '');
            if (isset($trend[$key])) {
                $trend[$key]['count'] = (int)$r['cnt'];
            }
        }
        $avg = 0;
        $n = count($trend);
        if ($n > 0) {
            $avg = (int)round(array_sum(array_column($trend, 'count')) / $n);
        }
        foreach ($trend as &$t) {
            $t['target'] = max($avg, $t['count']);
        }
        unset($t);

        // By zone for doughnut
        $zoneStmt = $pdo->prepare(
            "SELECT UPPER(TRIM(COALESCE(NULLIF(s.zone, ''), 'OTHER'))) AS zone, COUNT(*) AS cnt
             {$fromSql}
             WHERE {$whereSql}
             GROUP BY UPPER(TRIM(COALESCE(NULLIF(s.zone, ''), 'OTHER')))
             ORDER BY cnt DESC"
        );
        $zoneStmt->execute($params);
        $byZone = $zoneStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Top stores by freezer count
        $topStmt = $pdo->prepare(
            "SELECT s.store_name, s.city, s.outlet_id, COUNT(f.id) AS installs
             {$fromSql}
             WHERE {$whereSql} AND f.store_id IS NOT NULL
             GROUP BY s.id, s.store_name, s.city, s.outlet_id
             ORDER BY installs DESC, s.store_name ASC
             LIMIT 5"
        );
        $topStmt->execute($params);
        $topStores = $topStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Recent activities: latest KYC + recent registrations
        $activities = [];
        $kycAct = $pdo->prepare(
            "SELECT k.result, k.verified_at, k.notes, f.unique_freezer_id,
                    s.store_name, s.city, u.name AS tech_name
             FROM `kyc_verifications` k
             INNER JOIN `freezers` f ON f.id = k.freezer_id AND f.deleted_at IS NULL
             LEFT JOIN `stores` s ON s.id = k.store_id
             LEFT JOIN `users` u ON u.id = k.verified_by
             WHERE 1=1"
            . ($zone !== '' ? ' AND UPPER(TRIM(s.zone)) = :zone' : '')
            . ($city !== '' ? ' AND s.city = :city' : '')
            . " ORDER BY k.verified_at DESC, k.id DESC LIMIT 8"
        );
        $kycAct->execute($params);
        foreach ($kycAct->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $res = strtoupper(trim((string)($r['result'] ?? 'PASS')));
            $activities[] = [
                'kind' => $res === 'FAIL' ? 'issue' : 'success',
                'title' => $res === 'FAIL' ? 'KYC Failed' : 'KYC Passed',
                'subtitle' => trim(($r['store_name'] ?? '') . ($r['city'] ? ' · ' . $r['city'] : '')),
                'meta' => (string)($r['unique_freezer_id'] ?? ''),
                'when' => (string)($r['verified_at'] ?? ''),
                'href' => 'profile_view.php?id=' . urlencode((string)($r['unique_freezer_id'] ?? '')),
                'badge' => $res === 'FAIL' ? 'Issue' : 'Success',
            ];
        }
        foreach ($recent as $r) {
            $activities[] = [
                'kind' => 'pending',
                'title' => 'Freezer Registered',
                'subtitle' => trim(($r['store_name'] ?? '') . (!empty($r['store_city']) ? ' · ' . $r['store_city'] : '')),
                'meta' => (string)($r['unique_freezer_id'] ?? ''),
                'when' => (string)($r['installation_date'] ?? ''),
                'href' => 'profile_view.php?id=' . urlencode((string)($r['unique_freezer_id'] ?? '')),
                'badge' => 'Registered',
            ];
        }
        usort($activities, static function ($a, $b) {
            return strcmp((string)($b['when'] ?? ''), (string)($a['when'] ?? ''));
        });
        $activities = array_slice($activities, 0, 6);

        return [
            'total_freezers' => $totalFreezers,
            'total_stores' => $totalStores,
            'active_units' => $activeUnits,
            'total_iot' => $totalIot,
            'by_type' => $byType,
            'recent' => $recent,
            'trend' => array_values($trend),
            'trend_meta' => $trendMeta,
            'by_zone' => $byZone,
            'top_stores' => $topStores,
            'activities' => $activities,
            'filters' => ['zone' => $zone, 'city' => $city],
        ];
    }

    /**
     * Resolve installation-trend date range from filters.
     * period: month | 6months | year | custom
     *
     * @return array{period:string,from:string,to:string,bucket:string,label:string}
     */
    public static function resolveTrendRange(array $filters = []): array {
        $period = strtolower(trim((string)($filters['trend_period'] ?? '6months')));
        if (!in_array($period, ['month', '6months', 'year', 'custom'], true)) {
            $period = '6months';
        }

        $today = date('Y-m-d');
        $from = '';
        $to = $today;
        $bucket = 'month';
        $label = 'Last 6 months';

        if ($period === 'month') {
            $from = date('Y-m-01');
            $bucket = 'day';
            $label = 'This month';
        } elseif ($period === 'year') {
            $from = date('Y-m-01', strtotime('-11 months'));
            $bucket = 'month';
            $label = 'Last 1 year';
        } elseif ($period === 'custom') {
            $fromIn = trim((string)($filters['trend_from'] ?? ''));
            $toIn = trim((string)($filters['trend_to'] ?? ''));
            $fromOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIn) === 1;
            $toOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIn) === 1;
            $from = $fromOk ? $fromIn : date('Y-m-d', strtotime('-29 days'));
            $to = $toOk ? $toIn : $today;
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            $days = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
            $bucket = $days <= 45 ? 'day' : 'month';
            $label = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
        } else {
            // 6months (default)
            $from = date('Y-m-01', strtotime('-5 months'));
            $bucket = 'month';
            $label = 'Last 6 months';
            $period = '6months';
        }

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'bucket' => $bucket,
            'label' => $label,
        ];
    }

    /** Distinct zones that have at least one live freezer. */
    public static function dashboardZones(): array {
        $pdo = Database::getConnection();
        self::ensureSimColumns();
        $stmt = $pdo->query(
            "SELECT DISTINCT UPPER(TRIM(s.zone)) AS zone
             FROM `stores` s
             INNER JOIN `freezers` f ON f.store_id = s.id AND f.deleted_at IS NULL
             WHERE s.zone IS NOT NULL AND TRIM(s.zone) <> ''
             ORDER BY zone ASC"
        );
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (!empty($r['zone'])) {
                $out[] = $r['zone'];
            }
        }
        return $out;
    }

    /** Distinct cities (optionally within a zone) that have live freezers. */
    public static function dashboardCities(?string $zone = null): array {
        $pdo = Database::getConnection();
        self::ensureSimColumns();
        $zone = strtoupper(trim((string)$zone));
        if ($zone === 'ALL') {
            $zone = '';
        }
        $sql = "SELECT DISTINCT s.city
                FROM `stores` s
                INNER JOIN `freezers` f ON f.store_id = s.id AND f.deleted_at IS NULL
                WHERE s.city IS NOT NULL AND TRIM(s.city) <> ''";
        $params = [];
        if ($zone !== '') {
            $sql .= " AND UPPER(TRIM(s.zone)) = :zone";
            $params['zone'] = $zone;
        }
        $sql .= " ORDER BY s.city ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (!empty($r['city'])) {
                $out[] = $r['city'];
            }
        }
        return $out;
    }

    public static function logAudit(string $action, ?int $entityId, ?string $freezerIdCode, string $details): void {
        try {
            $pdo = Database::getConnection();
            $user = \App\Services\AuthService::currentUser();
            $userId = $user['id'] ?? null;
            $userName = $user['name'] ?? 'System';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            $stmt = $pdo->prepare("INSERT INTO `audit_logs` 
                (`user_id`, `user_name`, `action`, `entity_type`, `entity_id`, `freezer_id_code`, `details`, `ip_address`)
                VALUES (:uid, :uname, :action, 'FREEZER', :eid, :fid, :details, :ip)");
            $stmt->execute([
                'uid' => $userId,
                'uname' => $userName,
                'action' => $action,
                'eid' => $entityId,
                'fid' => $freezerIdCode,
                'details' => $details,
                'ip' => $ip,
            ]);
            if (class_exists(\App\Services\AppLogger::class)) {
                \App\Services\AppLogger::audit('freezer.' . strtolower($action), $details, [
                    'entity_id' => $entityId,
                    'freezer_id' => $freezerIdCode,
                    'user_id' => $userId,
                    'user_name' => $userName,
                ]);
            }
        } catch (\Throwable $e) {
            error_log("Failed to log audit: " . $e->getMessage());
            if (class_exists(\App\Services\AppLogger::class)) {
                \App\Services\AppLogger::error('audit.write_failed', $e->getMessage(), [
                    'action' => $action,
                    'entity_id' => $entityId,
                    'freezer_id' => $freezerIdCode,
                ]);
            }
        }
    }
}
