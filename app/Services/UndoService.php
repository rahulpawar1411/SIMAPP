<?php
namespace App\Services;

use App\Models\AppNotification;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;
use App\Models\Store;
use App\Models\User;

/**
 * Short-lived Undo for the toast button.
 * Stores a snapshot in the session (about 3 minutes), then restores on Undo.
 * Covers: profile edit/delete, approvals, AMC, admin lock, and user On/Off/Edit/Delete.
 */
class UndoService {
    private const SESSION_KEY = 'df_undo_actions';
    /** Undo stays available for this many seconds after the action. */
    private const TTL_SECONDS = 180;

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
                'image_sim_back' => $freezer['image_sim_back'] ?? null,
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

    /**
     * Build undo payload for a single approve/reject (null if not safe).
     *
     * @param array $rowBefore notification row before decision
     * @param array $result approve() result or ['type'=>..., 'undoable'=>true] for reject
     */
    public static function buildApprovalUndo(array $rowBefore, string $decision, array $result = []): ?array {
        $notifId = (int)($rowBefore['id'] ?? 0);
        if ($notifId <= 0) {
            return null;
        }
        $type = (string)($rowBefore['type'] ?? '');
        $decision = strtolower($decision) === 'reject' ? 'reject' : 'approve';

        if ($decision === 'approve' && empty($result['undoable'])) {
            return null;
        }
        // Store-move create+transfer is not toast-undoable
        if ($type === AppNotification::TYPE_KYC_STORE_MOVE && $decision === 'approve') {
            return null;
        }

        return [
            'notif_id' => $notifId,
            'decision' => $decision,
            'type' => $type,
            'payload' => AppNotification::decodePayload($rowBefore),
            'kyc_id' => (int)($result['kyc_id'] ?? 0),
            'label' => (string)($rowBefore['unique_freezer_id'] ?? ('#' . (int)($rowBefore['freezer_id'] ?? 0))),
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
            return match ($type) {
                'edit' => self::undoEdit(is_array($payload) ? $payload : []),
                'delete' => self::undoDelete(is_array($payload) ? $payload : []),
                'approval_decision' => self::undoApprovalDecision(is_array($payload) ? $payload : []),
                'approval_bulk' => self::undoApprovalBulk(is_array($payload) ? $payload : []),
                'kyc_same' => self::undoKycSame(is_array($payload) ? $payload : []),
                'admin_lock' => self::undoAdminLock(is_array($payload) ? $payload : []),
                'user_delete' => self::undoUserDelete(is_array($payload) ? $payload : []),
                'user_status' => self::undoUserStatus(is_array($payload) ? $payload : []),
                'user_edit' => self::undoUserEdit(is_array($payload) ? $payload : []),
                default => ['success' => false, 'error' => 'Unknown undo action.'],
            };
        } catch (\Throwable $e) {
            AppLogger::exception($e, 'undo.apply.' . ($type !== '' ? $type : 'unknown'));
            return [
                'success' => false,
                'error' => AppLogger::friendlyMessage($e, 'Undo failed. Please try again.'),
            ];
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

    private static function undoApprovalDecision(array $payload): array {
        $notifId = (int)($payload['notif_id'] ?? 0);
        if ($notifId <= 0) {
            return ['success' => false, 'error' => 'Invalid approval snapshot.'];
        }
        $row = AppNotification::findById($notifId);
        if (!$row) {
            return ['success' => false, 'error' => 'Request no longer exists.'];
        }
        if (($row['status'] ?? '') === AppNotification::STATUS_PENDING) {
            return ['success' => true, 'message' => 'Already pending.', 'redirect' => 'notifications.php'];
        }

        // If tech already used the unlock, cannot safely revert approve
        $currentPayload = AppNotification::decodePayload($row);
        if (
            ($payload['decision'] ?? '') === 'approve'
            && ($payload['type'] ?? '') === AppNotification::TYPE_PROFILE_EDIT
            && !empty($currentPayload['consumed'])
        ) {
            return ['success' => false, 'error' => 'Technician already used this edit unlock.'];
        }

        $kycId = (int)($payload['kyc_id'] ?? 0);
        if ($kycId > 0) {
            $del = FreezerLifecycle::deleteKycVerification($kycId);
            if (empty($del['success'])) {
                return ['success' => false, 'error' => $del['error'] ?? 'Could not remove AMC record.'];
            }
        }

        $prevPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : null;
        AppNotification::revertToPending($notifId, $prevPayload);

        $label = (string)($payload['label'] ?? 'request');
        $decision = (string)($payload['decision'] ?? 'decision');
        return [
            'success' => true,
            'message' => ucfirst($decision) . ' undone for ' . $label,
            'redirect' => 'notifications.php',
        ];
    }

    private static function undoApprovalBulk(array $payload): array {
        $items = $payload['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return ['success' => false, 'error' => 'Nothing to undo.'];
        }
        $ok = 0;
        $fail = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                $fail++;
                continue;
            }
            $res = self::undoApprovalDecision($item);
            if (!empty($res['success'])) {
                $ok++;
            } else {
                $fail++;
            }
        }
        if ($ok === 0) {
            return ['success' => false, 'error' => 'Could not undo any decisions.'];
        }
        $msg = 'Undid ' . $ok . ' decision' . ($ok === 1 ? '' : 's');
        if ($fail > 0) {
            $msg .= ' (' . $fail . ' skipped)';
        }
        return [
            'success' => true,
            'message' => $msg,
            'redirect' => 'notifications.php',
        ];
    }

    private static function undoKycSame(array $payload): array {
        $kycId = (int)($payload['kyc_id'] ?? 0);
        $freezerId = (int)($payload['freezer_id'] ?? 0);
        $uid = (string)($payload['unique_freezer_id'] ?? '');
        if ($kycId <= 0) {
            return ['success' => false, 'error' => 'Invalid AMC snapshot.'];
        }
        $del = FreezerLifecycle::deleteKycVerification($kycId);
        if (empty($del['success'])) {
            return ['success' => false, 'error' => $del['error'] ?? 'Could not undo AMC.'];
        }
        if ($freezerId > 0) {
            KycScanGate::mark($freezerId);
        }
        $label = $uid !== '' ? $uid : ('#' . ($del['freezer_id'] ?? $freezerId));
        return [
            'success' => true,
            'message' => 'AMC undone for ' . $label,
            'redirect' => 'freezer_kyc.php?id=' . (int)($del['freezer_id'] ?? $freezerId) . '&from=profile',
        ];
    }

    private static function undoAdminLock(array $payload): array {
        $freezerId = (int)($payload['freezer_id'] ?? 0);
        $sessionKey = (string)($payload['session_key'] ?? '');
        $meta = $payload['unlock_meta'] ?? null;
        if ($freezerId <= 0 || $sessionKey === '' || !is_array($meta)) {
            return ['success' => false, 'error' => 'Invalid lock snapshot.'];
        }
        $user = AuthService::currentUser();
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0 || (int)($meta['uid'] ?? 0) !== $uid) {
            return ['success' => false, 'error' => 'Unlock belongs to another session.'];
        }
        // Refresh unlock clock so admin still has time after undo
        $_SESSION[$sessionKey] = [
            'at' => time(),
            'uid' => $uid,
        ];
        $label = (string)($payload['label'] ?? ('#' . $freezerId));
        return [
            'success' => true,
            'message' => 'Edit unlocked again for ' . $label,
            'redirect' => 'profile_edit.php?id=' . $freezerId,
        ];
    }

    /** Bring back a user that was just deleted (toast Undo). */
    private static function undoUserDelete(array $payload): array {
        $snapshot = $payload['user'] ?? null;
        if (!is_array($snapshot)) {
            return ['success' => false, 'error' => 'Invalid user snapshot.'];
        }
        if (!User::restoreSnapshot($snapshot)) {
            return ['success' => false, 'error' => 'Could not restore user (maybe email already exists).'];
        }
        $label = (string)($payload['label'] ?? $snapshot['name'] ?? 'User');
        return [
            'success' => true,
            'message' => $label . ' restored',
            'redirect' => 'users.php#users-directory',
        ];
    }

    /** Put On/Off status back to the previous value (toast Undo). */
    private static function undoUserStatus(array $payload): array {
        $userId = (int)($payload['user_id'] ?? 0);
        $prevStatus = (($payload['prev_status'] ?? '') === 'inactive') ? 'inactive' : 'active';
        if ($userId <= 0 || !User::findById($userId)) {
            return ['success' => false, 'error' => 'User no longer exists.'];
        }
        if (!User::setStatus($userId, $prevStatus)) {
            return ['success' => false, 'error' => 'Could not restore status.'];
        }
        $label = (string)($payload['label'] ?? ('#' . $userId));
        $msg = $prevStatus === 'active' ? ($label . ' turned On') : ($label . ' turned Off');
        return [
            'success' => true,
            'message' => $msg,
            'redirect' => 'users.php#users-directory',
        ];
    }

    /** Restore user fields from before the last edit (toast Undo). */
    private static function undoUserEdit(array $payload): array {
        $userId = (int)($payload['user_id'] ?? 0);
        $snapshot = $payload['user'] ?? null;
        if ($userId <= 0 || !is_array($snapshot)) {
            return ['success' => false, 'error' => 'Invalid user edit snapshot.'];
        }
        if (!User::restoreEditSnapshot($userId, $snapshot)) {
            return ['success' => false, 'error' => 'Could not undo user edit.'];
        }
        $label = (string)($payload['label'] ?? $snapshot['name'] ?? ('#' . $userId));
        return [
            'success' => true,
            'message' => 'Changes undone for ' . $label,
            'redirect' => 'users.php#users-directory',
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
