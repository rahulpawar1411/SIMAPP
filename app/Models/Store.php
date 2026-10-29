<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class Store {

    /**
     * Normalize store phone to +91XXXXXXXXXX (10-digit Indian mobile). Empty → ''.
     */
    public static function normalizePhone(string $phone): string {
        $result = User::normalizeIndianPhone($phone);
        if (!$result['success']) {
            throw new \InvalidArgumentException($result['error'] ?? 'Invalid phone number.');
        }
        return $result['phone'] ?? '';
    }

    /**
     * Normalize Aadhaar to 12 digits. Empty → ''. Invalid → exception.
     */
    public static function normalizeAadhar(string $aadhar): string {
        $digits = preg_replace('/\D/', '', $aadhar);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) !== 12) {
            throw new \InvalidArgumentException('Aadhaar number must be exactly 12 digits.');
        }
        return $digits;
    }

    /** FSSAI licence — typically 14 digits. Empty → ''. */
    public static function normalizeFssai(string $fssai): string {
        $digits = preg_replace('/\D/', '', $fssai);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) !== 14) {
            throw new \InvalidArgumentException('FSSAI number must be exactly 14 digits.');
        }
        return $digits;
    }

    /** GSTIN — 15 alphanumeric. Empty → ''. */
    public static function normalizeGst(string $gst): string {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $gst));
        if ($clean === '') {
            return '';
        }
        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $clean)) {
            throw new \InvalidArgumentException('GST number must be a valid 15-character GSTIN.');
        }
        return $clean;
    }

    public static function create(array $data): int {
        $pdo = Database::getConnection();
        $phone = self::normalizePhone((string)($data['phone_number'] ?? ''));
        $aadhar = self::normalizeAadhar((string)($data['aadhar_number'] ?? ''));
        $fssai = self::normalizeFssai((string)($data['fssai_number'] ?? ''));
        $gst = self::normalizeGst((string)($data['gst_number'] ?? ''));

        $sql = "INSERT INTO `stores` (
            `outlet_id`, `store_name`, `customer_name`, `distributor_name`,
            `contact_person`, `phone_number`, `email`, `aadhar_number`,
            `fssai_number`, `gst_number`, `address`,
            `city`, `state`, `pincode`, `zone`, `location_type`,
            `latitude`, `longitude`, `gps_accuracy`, `gps_captured_at`,
            `image_store_front`, `status`, `created_by`
        ) VALUES (
            :outlet_id, :store_name, :customer_name, :distributor_name,
            :contact_person, :phone_number, :email, :aadhar_number,
            :fssai_number, :gst_number, :address,
            :city, :state, :pincode, :zone, :location_type,
            :latitude, :longitude, :gps_accuracy, :gps_captured_at,
            :image_store_front, :status, :created_by
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'outlet_id' => trim($data['outlet_id']),
            'store_name' => trim($data['store_name']),
            'customer_name' => trim($data['customer_name'] ?? 'Baskin Robbins'),
            'distributor_name' => trim($data['distributor_name'] ?? ''),
            'contact_person' => trim($data['contact_person'] ?? ''),
            'phone_number' => $phone,
            'email' => trim($data['email'] ?? ''),
            'aadhar_number' => $aadhar !== '' ? $aadhar : null,
            'fssai_number' => $fssai !== '' ? $fssai : null,
            'gst_number' => $gst !== '' ? $gst : null,
            'address' => trim($data['address']),
            'city' => trim($data['city']),
            'state' => trim($data['state']),
            'pincode' => trim($data['pincode']),
            'zone' => trim($data['zone'] ?? 'WEST'),
            'location_type' => trim($data['location_type'] ?? 'Retail Outlet'),
            'latitude' => !empty($data['latitude']) ? (float)$data['latitude'] : null,
            'longitude' => !empty($data['longitude']) ? (float)$data['longitude'] : null,
            'gps_accuracy' => !empty($data['gps_accuracy']) ? (float)$data['gps_accuracy'] : null,
            'gps_captured_at' => !empty($data['gps_captured_at']) ? $data['gps_captured_at'] : (!empty($data['latitude']) ? date('Y-m-d H:i:s') : null),
            'image_store_front' => $data['image_store_front'] ?? null,
            'status' => $data['status'] ?? 'Active',
            'created_by' => $data['created_by'] ?? null,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $data): bool {
        $pdo = Database::getConnection();
        $phone = self::normalizePhone((string)($data['phone_number'] ?? ''));
        $aadhar = self::normalizeAadhar((string)($data['aadhar_number'] ?? ''));
        $fssai = self::normalizeFssai((string)($data['fssai_number'] ?? ''));
        $gst = self::normalizeGst((string)($data['gst_number'] ?? ''));

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
            `image_store_front` = COALESCE(:image_store_front, `image_store_front`),
            `status` = :status
        WHERE `id` = :id";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'outlet_id' => trim($data['outlet_id']),
            'store_name' => trim($data['store_name']),
            'customer_name' => trim($data['customer_name'] ?? 'Baskin Robbins'),
            'distributor_name' => trim($data['distributor_name'] ?? ''),
            'contact_person' => trim($data['contact_person'] ?? ''),
            'phone_number' => $phone,
            'email' => trim($data['email'] ?? ''),
            'aadhar_number' => $aadhar !== '' ? $aadhar : null,
            'fssai_number' => $fssai !== '' ? $fssai : null,
            'gst_number' => $gst !== '' ? $gst : null,
            'address' => trim($data['address']),
            'city' => trim($data['city']),
            'state' => trim($data['state']),
            'pincode' => trim($data['pincode']),
            'zone' => trim($data['zone'] ?? 'WEST'),
            'location_type' => trim($data['location_type'] ?? 'Retail Outlet'),
            'latitude' => !empty($data['latitude']) ? (float)$data['latitude'] : null,
            'longitude' => !empty($data['longitude']) ? (float)$data['longitude'] : null,
            'gps_accuracy' => !empty($data['gps_accuracy']) ? (float)$data['gps_accuracy'] : null,
            'gps_captured_at' => !empty($data['gps_captured_at']) ? $data['gps_captured_at'] : (!empty($data['latitude']) ? date('Y-m-d H:i:s') : null),
            'image_store_front' => $data['image_store_front'] ?? null,
            'status' => $data['status'] ?? 'Active',
        ]);
    }

    public static function findById(int $id): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `stores` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByOutletId(string $outletId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `stores` WHERE `outlet_id` = :oid LIMIT 1");
        $stmt->execute(['oid' => trim($outletId)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getAll(?string $search = null, int $limit = 100): array {
        $pdo = Database::getConnection();
        if ($search) {
            $stmt = $pdo->prepare("SELECT * FROM `stores` 
                WHERE `store_name` LIKE :q 
                   OR `outlet_id` LIKE :q 
                   OR `city` LIKE :q 
                   OR `pincode` LIKE :q 
                ORDER BY `id` DESC LIMIT :limit");
            $stmt->bindValue(':q', '%' . $search . '%', PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $pdo->prepare("SELECT * FROM `stores` ORDER BY `id` DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function generateOutletId(string $city = 'AMD'): string {
        $pdo = Database::getConnection();
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $city), 0, 3));
        if (strlen($prefix) < 3) {
            $prefix = 'OUT';
        }
        $prefix = 'W-' . $prefix;

        $stmt = $pdo->prepare("SELECT `outlet_id` FROM `stores` WHERE `outlet_id` LIKE :p ORDER BY `id` DESC LIMIT 1");
        $stmt->execute(['p' => $prefix . '-%']);
        $last = $stmt->fetchColumn();

        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $nextNum = ((int)$m[1]) + 1;
        } else {
            $nextNum = 20540;
        }

        return $prefix . '-' . $nextNum;
    }
}
