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

        $lat = !empty($data['latitude']) ? (float)$data['latitude'] : null;
        $lng = !empty($data['longitude']) ? (float)$data['longitude'] : null;
        $locationLink = self::resolveLocationLink($data['location_link'] ?? null, $lat, $lng);

        $sql = "INSERT INTO `stores` (
            `outlet_id`, `store_name`, `customer_name`, `distributor_name`,
            `contact_person`, `phone_number`, `email`, `aadhar_number`,
            `fssai_number`, `gst_number`, `address`,
            `city`, `state`, `pincode`, `zone`, `location_type`,
            `latitude`, `longitude`, `gps_accuracy`, `gps_captured_at`, `location_link`,
            `image_store_front`, `image_aadhar_card`, `image_aadhar_back`, `status`, `created_by`
        ) VALUES (
            :outlet_id, :store_name, :customer_name, :distributor_name,
            :contact_person, :phone_number, :email, :aadhar_number,
            :fssai_number, :gst_number, :address,
            :city, :state, :pincode, :zone, :location_type,
            :latitude, :longitude, :gps_accuracy, :gps_captured_at, :location_link,
            :image_store_front, :image_aadhar_card, :image_aadhar_back, :status, :created_by
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
            'latitude' => $lat,
            'longitude' => $lng,
            'gps_accuracy' => !empty($data['gps_accuracy']) ? (float)$data['gps_accuracy'] : null,
            'gps_captured_at' => !empty($data['gps_captured_at']) ? $data['gps_captured_at'] : ($lat !== null ? date('Y-m-d H:i:s') : null),
            'location_link' => $locationLink,
            'image_store_front' => $data['image_store_front'] ?? null,
            'image_aadhar_card' => $data['image_aadhar_card'] ?? null,
            'image_aadhar_back' => $data['image_aadhar_back'] ?? null,
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
            `location_link` = :location_link,
            `image_store_front` = COALESCE(:image_store_front, `image_store_front`),
            `image_aadhar_card` = COALESCE(:image_aadhar_card, `image_aadhar_card`),
            `image_aadhar_back` = COALESCE(:image_aadhar_back, `image_aadhar_back`),
            `status` = :status
        WHERE `id` = :id";

        $lat = !empty($data['latitude']) ? (float)$data['latitude'] : null;
        $lng = !empty($data['longitude']) ? (float)$data['longitude'] : null;
        $locationLink = self::resolveLocationLink($data['location_link'] ?? null, $lat, $lng);

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
            'latitude' => $lat,
            'longitude' => $lng,
            'gps_accuracy' => !empty($data['gps_accuracy']) ? (float)$data['gps_accuracy'] : null,
            'gps_captured_at' => !empty($data['gps_captured_at']) ? $data['gps_captured_at'] : ($lat !== null ? date('Y-m-d H:i:s') : null),
            'location_link' => $locationLink,
            'image_store_front' => $data['image_store_front'] ?? null,
            'image_aadhar_card' => $data['image_aadhar_card'] ?? null,
            'image_aadhar_back' => $data['image_aadhar_back'] ?? null,
            'status' => $data['status'] ?? 'Active',
        ]);
    }

    /** WhatsApp-style Google Maps share URL from coordinates. */
    public static function buildMapsLink(float $latitude, float $longitude): string {
        return 'https://maps.google.com/?q=' . rawurlencode(
            number_format($latitude, 6, '.', '') . ',' . number_format($longitude, 6, '.', '')
        );
    }

    public static function resolveLocationLink(?string $provided, ?float $lat, ?float $lng): ?string {
        $link = trim((string)$provided);
        if ($link !== '' && preg_match('#^https?://#i', $link)) {
            return $link;
        }
        if ($lat !== null && $lng !== null) {
            return self::buildMapsLink($lat, $lng);
        }
        return null;
    }

    /** Update only GPS fields + maps location link (current location save). */
    public static function updateGps(int $id, float $latitude, float $longitude, ?float $accuracy = null, ?string $locationLink = null): bool {
        $pdo = Database::getConnection();
        $link = self::resolveLocationLink($locationLink, $latitude, $longitude);
        $stmt = $pdo->prepare(
            "UPDATE `stores` SET
                `latitude` = :lat,
                `longitude` = :lng,
                `gps_accuracy` = :acc,
                `gps_captured_at` = NOW(),
                `location_link` = :link
             WHERE `id` = :id"
        );
        return $stmt->execute([
            'id' => $id,
            'lat' => $latitude,
            'lng' => $longitude,
            'acc' => $accuracy,
            'link' => $link,
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

    /**
     * Auto Store / Outlet ID — same building blocks as Freezer ID location + PIN:
     * LOCATION_CODE - PINCODE - SEQ
     * Example: AMD1-380009-0001  (same AMD1 + 380009 style as freezer Unique ID)
     *
     * @param string|array $cityOrData City string (legacy) or store fields array
     */
    public static function generateOutletId(string|array $cityOrData = 'AMD', string $zone = 'WEST', string $pincode = '', string $locationType = 'Retail Store'): string {
        $preferredLoc = '';
        if (is_array($cityOrData)) {
            $city = (string)($cityOrData['city'] ?? 'AMD');
            $pincode = (string)($cityOrData['pincode'] ?? $pincode);
            $preferredLoc = (string)($cityOrData['location_code'] ?? '');
        } else {
            $city = $cityOrData;
        }

        // Same as freezer: Ahmedabad → AMD1 / AMD2 / AMD3
        $locationCode = MasterData::resolveLocationCodeForCity($city, $preferredLoc !== '' ? $preferredLoc : null);

        $pinDigits = preg_replace('/\D/', '', $pincode);
        // Full 6-digit PIN like freezer Unique ID (…-380009-…)
        if (strlen($pinDigits) === 6) {
            $pinPart = $pinDigits;
        } elseif (strlen($pinDigits) >= 3) {
            $pinPart = str_pad(substr($pinDigits, -6), 6, '0', STR_PAD_LEFT);
        } else {
            $pinPart = '000000';
        }

        // AMD1-380009
        $prefix = strtoupper($locationCode) . '-' . $pinPart;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT `outlet_id` FROM `stores` WHERE `outlet_id` LIKE :p ORDER BY `id` DESC LIMIT 1");
        $stmt->execute(['p' => $prefix . '-%']);
        $last = $stmt->fetchColumn();

        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $nextNum = ((int)$m[1]) + 1;
        } else {
            $nextNum = 1;
        }

        return $prefix . '-' . str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);
    }
}
