<?php
namespace App\Models;

use App\Config\Database;
use App\Services\FreezerIdGenerator;
use App\Services\QRCodeService;
use App\Services\FileUploadService;
use PDO;
use Exception;

class Freezer {

    public static function create(array $data): int {
        $pdo = Database::getConnection();

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

        // 2. Generate and save QR Code for this unique ID
        $qrResult = QRCodeService::generateAndSave($uniqueId);
        $qrPath = $qrResult['file_path'] ?? null;
        $qrData = $qrResult['payload'] ?? $uniqueId;

        // 3. Insert Freezer Record
        $sql = "INSERT INTO `freezers` (
            `unique_freezer_id`, `store_id`, `freezer_type`, `company_code`,
            `model_number`, `capacity_litres`, `freezer_condition`, `location_code`,
            `pincode_last3`, `install_month_year`, `unit_sequence`, `installation_date`,
            `image_left_side`, `image_right_side`, `image_iot_device`,
            `qr_code_path`, `qr_code_data`, `status`, `notes`, `created_by`
        ) VALUES (
            :unique_freezer_id, :store_id, :freezer_type, :company_code,
            :model_number, :capacity_litres, :freezer_condition, :location_code,
            :pincode_last3, :install_month_year, :unit_sequence, :installation_date,
            :image_left_side, :image_right_side, :image_iot_device,
            :qr_code_path, :qr_code_data, :status, :notes, :created_by
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
            'qr_code_path' => $qrPath,
            'qr_code_data' => $qrData,
            'status' => $data['status'] ?? 'Active',
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        $freezerId = (int)$pdo->lastInsertId();

        // 4. Create/Link IoT Device record if provided
        if (!empty($data['iot_serial'])) {
            IoTDevice::registerDevice($freezerId, [
                'device_serial' => trim($data['iot_serial']),
                'imei_or_mac' => $data['iot_imei'] ?? null,
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
            `qr_code_path` = :qr_code_path,
            `qr_code_data` = :qr_code_data,
            `status` = :status,
            `notes` = :notes
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
            'qr_code_path' => $qrPath,
            'qr_code_data' => $qrData,
            'status' => $data['status'] ?? $existing['status'],
            'notes' => $data['notes'] ?? $existing['notes'],
        ]);

        // Update IoT device info if passed
        if (!empty($data['iot_serial'])) {
            IoTDevice::registerOrUpdateDevice($id, [
                'device_serial' => trim($data['iot_serial']),
                'imei_or_mac' => $data['iot_imei'] ?? null,
                'device_type' => $data['iot_type'] ?? 'Temperature & GPS',
                'device_owner' => $data['iot_owner'] ?? 'ReeferON Own Device',
                'oem_model' => $data['iot_model'] ?? 'RF-T100',
            ]);
        }

        self::logAudit('UPDATE_PROFILE', $id, $uniqueId, "Freezer {$uniqueId} updated");

        return $res;
    }

    public static function delete(int $id): bool {
        $pdo = Database::getConnection();
        $freezer = self::findById($id);
        if (!$freezer) {
            return false;
        }

        // Delete physical files
        FileUploadService::deleteFile($freezer['image_left_side']);
        FileUploadService::deleteFile($freezer['image_right_side']);
        FileUploadService::deleteFile($freezer['image_iot_device']);
        FileUploadService::deleteFile($freezer['qr_code_path']);

        $stmt = $pdo->prepare("DELETE FROM `freezers` WHERE `id` = :id");
        $deleted = $stmt->execute(['id' => $id]);

        if ($deleted) {
            self::logAudit('DELETE_PROFILE', $id, $freezer['unique_freezer_id'], "Freezer {$freezer['unique_freezer_id']} deleted");
        }

        return $deleted;
    }

    public static function findById(int $id): ?array {
        $pdo = Database::getConnection();
        $sql = "SELECT f.*, 
                       s.store_name, s.outlet_id, s.customer_name, s.distributor_name,
                       s.contact_person, s.phone_number, s.email as store_email, s.aadhar_number,
                       s.fssai_number, s.gst_number,
                       s.address as store_address, s.city as store_city, s.state as store_state,
                       s.pincode as store_pincode, s.zone as store_zone, s.location_type,
                       s.latitude as store_latitude, s.longitude as store_longitude,
                       s.gps_accuracy as store_gps_accuracy, s.gps_captured_at as store_gps_captured_at,
                       s.image_store_front, s.image_aadhar_card, s.image_aadhar_back,
                       ft.name as type_name,
                       b.name as brand_name,
                       iot.device_serial, iot.imei_or_mac, iot.device_type, iot.device_owner,
                       iot.oem_model as iot_model, iot.battery_percentage, iot.power_status as iot_power_status,
                       iot.last_ping_at as iot_last_ping
                FROM `freezers` f
                INNER JOIN `stores` s ON f.store_id = s.id
                LEFT JOIN `freezer_types` ft ON f.freezer_type = ft.code
                LEFT JOIN `brands` b ON f.company_code = b.code
                LEFT JOIN `iot_devices` iot ON f.id = iot.freezer_id
                WHERE f.id = :id
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByUniqueId(string $uniqueId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id FROM `freezers` WHERE `unique_freezer_id` = :uid LIMIT 1");
        $stmt->execute(['uid' => trim($uniqueId)]);
        $id = $stmt->fetchColumn();
        return $id ? self::findById((int)$id) : null;
    }

    public static function getFiltered(array $filters = [], int $limit = 50, int $offset = 0): array {
        $pdo = Database::getConnection();

        $where = ["1=1"];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = "(f.unique_freezer_id LIKE :q 
                        OR s.store_name LIKE :q 
                        OR s.outlet_id LIKE :q 
                        OR s.city LIKE :q 
                        OR f.model_number LIKE :q)";
            $params['q'] = '%' . trim($filters['q']) . '%';
        }

        if (!empty($filters['type'])) {
            $where[] = "f.freezer_type = :type";
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['brand'])) {
            $where[] = "f.company_code = :brand";
            $params['brand'] = $filters['brand'];
        }

        if (!empty($filters['status'])) {
            $where[] = "f.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['city'])) {
            $where[] = "s.city = :city";
            $params['city'] = $filters['city'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT f.*, 
                       s.store_name, s.outlet_id, s.city as store_city, s.state as store_state,
                       s.latitude as store_latitude, s.longitude as store_longitude,
                       ft.name as type_name, b.name as brand_name,
                       iot.device_serial, iot.power_status as iot_power_status
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

        $where = ["1=1"];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = "(f.unique_freezer_id LIKE :q 
                        OR s.store_name LIKE :q 
                        OR s.outlet_id LIKE :q 
                        OR s.city LIKE :q 
                        OR f.model_number LIKE :q)";
            $params['q'] = '%' . trim($filters['q']) . '%';
        }

        if (!empty($filters['type'])) {
            $where[] = "f.freezer_type = :type";
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['brand'])) {
            $where[] = "f.company_code = :brand";
            $params['brand'] = $filters['brand'];
        }

        if (!empty($filters['status'])) {
            $where[] = "f.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['city'])) {
            $where[] = "s.city = :city";
            $params['city'] = $filters['city'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT COUNT(*)
                FROM `freezers` f
                INNER JOIN `stores` s ON f.store_id = s.id
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

        $totalFreezers = (int)$pdo->query("SELECT COUNT(*) FROM `freezers`")->fetchColumn();
        $totalStores = (int)$pdo->query("SELECT COUNT(*) FROM `stores`")->fetchColumn();
        $activeUnits = (int)$pdo->query("SELECT COUNT(*) FROM `freezers` WHERE `status` = 'Active'")->fetchColumn();
        $totalIot = (int)$pdo->query("SELECT COUNT(*) FROM `iot_devices`")->fetchColumn();

        // Count by type
        $stmtType = $pdo->query("SELECT f.freezer_type, ft.name, COUNT(*) as cnt 
                                 FROM `freezers` f 
                                 LEFT JOIN `freezer_types` ft ON f.freezer_type = ft.code 
                                 GROUP BY f.freezer_type, ft.name");
        $byType = $stmtType->fetchAll();

        // Recent freezers
        $recentStmt = $pdo->query("SELECT f.*, s.store_name, s.city as store_city 
                                   FROM `freezers` f 
                                   INNER JOIN `stores` s ON f.store_id = s.id 
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
