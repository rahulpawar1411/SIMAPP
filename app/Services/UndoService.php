<?php
namespace App\Services;

use App\Models\Freezer;
use App\Models\Store;

/**
 * Session-backed undo for profile edit / soft-delete (toast Undo button).
 */
class UndoService {
    private const SESSION_KEY = 'df_undo_actions';
    private const TTL_SECONDS = 120;

    public static function captureProfileSnapshot(array $freezer): array {
        $storeId = (int)($freezer['store_id'] ?? 0);
        $store = $storeId > 0 ? Store::findById($storeId) : null;

        return [
            'freezer_id' => (int)($freezer['id'] ?? 0),
            'store_id' => $storeId,
            'label' => (string)($freezer['unique_freezer_id'] ?? ''),
            'freezer' => [
                'unique_freezer_id' => $freezer['unique_freezer_id'] ?? null,
                'store_id' => $storeId,
                'freezer_type' => $freezer['freezer_type'] ?? null,
                'company_code' => $freezer['company_code'] ?? null,
                'model_number' => $freezer['model_number'] ?? null,
                'capacity_litres' => $freezer['capacity_litres'] ?? null,
                'freezer_condition' => $freezer['freezer_condition'] ?? null,
                'location_code' => $freezer['location_code'] ?? null,
                'pincode_last3' => $freezer['pincode_last3'] ?? null,
                'install_month_year' => $freezer['install_month_year'] ?? null,
                'unit_sequence' => $freezer['unit_sequence'] ?? null,
                'installation_date' => $freezer['installation_date'] ?? null,
                'image_left_side' => $freezer['image_left_side'] ?? null,
                'image_right_side' => $freezer['image_right_side'] ?? null,
                'image_iot_device' => $freezer['image_iot_device'] ?? null,
                'image_sim' => $freezer['image_sim'] ?? null,
                'qr_code_path' => $freezer['qr_code_path'] ?? null,
                'qr_code_data' => $freezer['qr_code_data'] ?? null,
                'status' => $freezer['status'] ?? null,
                'notes' => $freezer['notes'] ?? null,
                'sim_number' => $freezer['sim_number'] ?? null,
                'sim_company' => $freezer['sim_company'] ?? null,
            ],
            'store' => $store ? [
                'outlet_id' => $store['outlet_id'] ?? null,
                'store_name' => $store['store_name'] ?? null,
                'customer_name' => $store['customer_name'] ?? null,
                'distributor_name' => $store['distributor_name'] ?? null,
                'contact_person' => $store['contact_person'] ?? null,
                'phone_number' => $store['phone_number'] ?? null,
                'email' => $store['email'] ?? null,
                'aadhar_number' => $store['aadhar_number'] ?? null,
                'fssai_number' => $store['fssai_number'] ?? null,
                'gst_number' => $store['gst_number'] ?? null,
                'address' => $store['address'] ?? null,
                'city' => $store['city'] ?? null,
                'state' => $store['state'] ?? null,
                'pincode' => $store['pincode'] ?? null,
                'zone' => $store['zone'] ?? null,
                'location_type' => $store['location_type'] ?? null,
                'latitude' => $store['latitude'] ?? null,
                'longitude' => $store['longitude'] ?? null,
                'gps_accuracy' => $store['gps_accuracy'] ?? null,
                'gps_captured_at' => $store['gps_captured_at'] ?? null,
                'location_link' => $store['location_link'] ?? null,
                'image_store_front' => $store['image_store_front'] ?? null,
                'image_aadhar_card' => $store['image_aadhar_card'] ?? null,
                'image_aadhar_back' => $store['image_aadhar_back'] ?? null,
                'status' => $store['status'] ?? null,
            ] : null,
        ];
    }

    public static function remember(string $type, array $payload): string {
        AuthService::startSession();
        self::purgeExpired();
        $token = bin2hex(random_bytes(16));
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        $_SESSION[self::SESSION_KEY][$token] = [
            'type' => $type,
            'payload' => $payload,
            'expires' => time() + self::TTL_SECONDS,
        ];
        return $token;
    }

    public static function apply(string $token): array {
        AuthService::startSession();
        self::purgeExpired();
        $entry = $_SESSION[self::SESSION_KEY][$token] ?? null;
        if (!$entry || !is_array($entry)) {
            return ['success' => false, 'error' => 'Undo expired or already used.'];
        }

        $type = (string)($entry['type'] ?? '');
        $payload = $entry['payload'] ?? [];
        unset($_SESSION[self::SESSION_KEY][$token]);

        try {
            if ($type === 'edit') {
                return self::undoEdit($payload);
            }
            if ($type === 'delete') {
                return self::undoDelete($payload);
            }
            return ['success' => false, 'error' => 'Unknown undo action.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function undoEdit(array $payload): array {
        $freezerId = (int)($payload['freezer_id'] ?? 0);
        $storeId = (int)($payload['store_id'] ?? 0);
        $freezerData = $payload['freezer'] ?? null;
        $storeData = $payload['store'] ?? null;

        if ($freezerId <= 0 || !is_array($freezerData)) {
            return ['success' => false, 'error' => 'Invalid edit snapshot.'];
        }

        $current = Freezer::findById($freezerId, true);
        if (!$current) {
            return ['success' => false, 'error' => 'Profile no longer exists.'];
        }

        if ($storeId > 0 && is_array($storeData)) {
            Freezer::restoreStoreSnapshot($storeId, $storeData);
        }
        Freezer::restoreFreezerSnapshot($freezerId, $freezerData);

        $label = (string)($payload['label'] ?? $freezerData['unique_freezer_id'] ?? 'Profile');
        return [
            'success' => true,
            'message' => 'Changes undone for ' . $label,
            'redirect' => 'profile_view.php?id=' . $freezerId,
        ];
    }

    private static function undoDelete(array $payload): array {
        $freezerId = (int)($payload['freezer_id'] ?? 0);
        if ($freezerId <= 0) {
            return ['success' => false, 'error' => 'Invalid delete snapshot.'];
        }
        if (!Freezer::restoreDeleted($freezerId)) {
            return ['success' => false, 'error' => 'Could not restore profile.'];
        }
        $label = (string)($payload['label'] ?? 'Profile');
        return [
            'success' => true,
            'message' => $label . ' restored',
            'redirect' => 'profile_view.php?id=' . $freezerId,
        ];
    }

    private static function purgeExpired(): void {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return;
        }
        $now = time();
        foreach ($_SESSION[self::SESSION_KEY] as $token => $entry) {
            if (!is_array($entry) || ($entry['expires'] ?? 0) < $now) {
                unset($_SESSION[self::SESSION_KEY][$token]);
            }
        }
    }
}
