<?php
namespace App\Services;

use App\Models\AssetTag;
use App\Models\Freezer;

/**
 * KYC may only start after the physical QR/Tag for that freezer was scanned
 * (or typed) in this session — blocks doing KYC from list/profile alone.
 */
class KycScanGate {

    /** Seconds the scan unlock stays valid. */
    public const TTL = 3600;

    public static function mark(int $freezerId): void {
        AuthService::startSession();
        if (!isset($_SESSION['kyc_scan_ok']) || !is_array($_SESSION['kyc_scan_ok'])) {
            $_SESSION['kyc_scan_ok'] = [];
        }
        $_SESSION['kyc_scan_ok'][$freezerId] = time();
    }

    public static function check(int $freezerId): bool {
        AuthService::startSession();
        $ts = $_SESSION['kyc_scan_ok'][$freezerId] ?? null;
        if ($ts === null || !is_numeric($ts)) {
            return false;
        }
        if ((time() - (int)$ts) > self::TTL) {
            unset($_SESSION['kyc_scan_ok'][$freezerId]);
            return false;
        }
        return true;
    }

    public static function consume(int $freezerId): void {
        AuthService::startSession();
        unset($_SESSION['kyc_scan_ok'][$freezerId]);
    }

    public static function scannerUrl(array $freezer): string {
        $uid = (string)($freezer['unique_freezer_id'] ?? '');
        return 'qr_scanner.php?purpose=kyc&expect=' . rawurlencode($uid);
    }

    /**
     * Resolve scanned / typed payload to a freezer row.
     * @return array|null freezer row
     */
    public static function resolveToFreezer(string $raw): ?array {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // URL with ?id= or ?t=
        try {
            $url = parse_url($raw);
            if (!empty($url['query'])) {
                parse_str($url['query'], $q);
                if (!empty($q['t'])) {
                    $tag = AssetTag::findByCode(trim((string)$q['t']));
                    if ($tag && !empty($tag['freezer_id'])) {
                        return Freezer::findById((int)$tag['freezer_id']);
                    }
                    return null;
                }
                if (!empty($q['id'])) {
                    $idParam = trim((string)$q['id']);
                    if (ctype_digit($idParam)) {
                        return Freezer::findById((int)$idParam);
                    }
                    return Freezer::findByUniqueId($idParam);
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }

        if (preg_match('/^RO-[A-Z0-9]+$/i', $raw)) {
            $tag = AssetTag::findByCode(strtoupper($raw));
            if ($tag && !empty($tag['freezer_id'])) {
                return Freezer::findById((int)$tag['freezer_id']);
            }
            return null;
        }

        if (ctype_digit($raw)) {
            return Freezer::findById((int)$raw);
        }

        return Freezer::findByUniqueId($raw);
    }

    /**
     * Unlock KYC if scanned code matches the expected Unique Freezer ID.
     * @return array{ok:bool, freezer:?array, error:?string}
     */
    public static function tryUnlock(string $scannedRaw, string $expectUniqueId): array {
        $expectUniqueId = trim($expectUniqueId);
        $freezer = self::resolveToFreezer($scannedRaw);
        if (!$freezer) {
            return ['ok' => false, 'freezer' => null, 'error' => 'QR / code not found or tag not assigned to a freezer.'];
        }
        $uid = (string)($freezer['unique_freezer_id'] ?? '');
        if ($expectUniqueId !== '' && strcasecmp($uid, $expectUniqueId) !== 0) {
            AppLogger::failed('kyc.scan_mismatch', 'Wrong asset scanned for KYC', [
                'expected' => $expectUniqueId,
                'scanned' => $uid,
            ]);
            return [
                'ok' => false,
                'freezer' => $freezer,
                'error' => 'Wrong asset. Scanned ' . $uid . ' but KYC is for ' . $expectUniqueId . '.',
            ];
        }
        self::mark((int)$freezer['id']);
        AppLogger::info('kyc.scan_ok', 'KYC unlocked after QR scan', [
            'freezer_id' => (int)$freezer['id'],
            'unique_freezer_id' => $uid,
        ]);
        return ['ok' => true, 'freezer' => $freezer, 'error' => null];
    }
}
