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
        $sql = "INSERT INTO `freezers` (
            `unique_freezer_id`, `store_id`, `freezer_type`, `company_code`,
            `model_number`, `capacity_litres`, `freezer_condition`, `location_code`,
            `pincode_last3`, `install_month_year`, `unit_sequence`, `installation_date`,
            `image_left_side`, `image_right_side`, `image_iot_device`, `image_sim`,
            `qr_code_path`, `qr_code_data`, `status`, `notes`, `sim_number`, `sim_company`, `created_by`
        ) VALUES (
            :unique_freezer_id, :store_id, :freezer_type, :company_code,
            :model_number, :capacity_litres, :freezer_condition, :location_code,
            :pincode_last3, :install_month_year, :unit_sequence, :installation_date,
            :image_left_side, :image_right_side, :image_iot_device, :image_sim,
            :qr_code_path, :qr_code_data, :status, :notes, :sim_number, :sim_company, :created_by
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
            $where[] = "(f.unique_freezer_id LIKE :q 
                        OR s.store_name LIKE :q 
                        OR s.outlet_id LIKE :q 
                        OR s.city LIKE :q 
                        OR f.model_number LIKE :q
                        OR EXISTS (
                            SELECT 1 FROM `asset_tags` atq
                            WHERE atq.freezer_id = f.id AND atq.tag_code LIKE :q
                        ))";
            $params['q'] = '%' . trim($filters['q']) . '%';
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

        // KYC filter — due = installation/last KYC + 6 months (multi: pending / done)
        $kycList = self::normalizeFilterList($filters['kyc'] ?? null);
        $kycList = array_values(array_intersect($kycList, ['pending', 'done']));
        if ($kycList && count($kycList) === 1) {
            $kyc = $kycList[0];
            $gapMonths = (int)FreezerLifecycle::KYC_GAP_MONTHS;
            if ($gapMonths < 1) {
                $gapMonths = 6;
            }
            $dueExpr = FreezerLifecycle::kycDueSqlExpr('f', $gapMonths);
            $params['kyc_today'] = date('Y-m-d');
            if ($kyc === 'pending') {
                $where[] = "{$dueExpr} <= :kyc_today";
            } else {
                $where[] = "{$dueExpr} > :kyc_today";
            }
        }
        // If both pending + done checked, no KYC constraint (shows all)

        return [$where, $params, $needsIotJoin];
    }

    public static function getFiltered(array $filters = [], int $limit = 50, int $offset = 0): array {
        $pdo = Database::getConnection();
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
                       ) AS last_kyc_at
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

    public static function getDashboardStats(): array {
        $pdo = Database::getConnection();
        self::ensureSimColumns();

        $totalFreezers = (int)$pdo->query("SELECT COUNT(*) FROM `freezers` WHERE `deleted_at` IS NULL")->fetchColumn();
        $totalStores = (int)$pdo->query("SELECT COUNT(*) FROM `stores`")->fetchColumn();
        $activeUnits = (int)$pdo->query("SELECT COUNT(*) FROM `freezers` WHERE `status` = 'Active' AND `deleted_at` IS NULL")->fetchColumn();
        $totalIot = (int)$pdo->query("SELECT COUNT(*) FROM `iot_devices`")->fetchColumn();

        // Count by type
        $stmtType = $pdo->query("SELECT f.freezer_type, ft.name, COUNT(*) as cnt 
                                 FROM `freezers` f 
                                 LEFT JOIN `freezer_types` ft ON f.freezer_type = ft.code 
                                 WHERE f.deleted_at IS NULL
                                 GROUP BY f.freezer_type, ft.name");
        $byType = $stmtType->fetchAll();

        // Recent freezers
        $recentStmt = $pdo->query("SELECT f.*, s.store_name, s.city as store_city 
                                   FROM `freezers` f 
                                   INNER JOIN `stores` s ON f.store_id = s.id 
                                   WHERE f.deleted_at IS NULL
                                   ORDER BY f.id DESC LIMIT 5");
        $recent = $recentStmt->fetchAll();

        return [
            'total_freezers' => $totalFreezers,
            'total_stores' => $totalStores,
            'active_units' => $activeUnits,
            'total_iot' => $totalIot,
            'by_type' => $byType,
            'recent' => $recent,
        ];
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
        } catch (\Throwable $e) {
            error_log("Failed to log audit: " . $e->getMessage());
        }
    }
}
