<?php
namespace App\Config;

class AppConfig {
    public const APP_NAME = 'ReeferON - SIM Freezer & Store Management';
    public const APP_SHORT_NAME = 'ReeferON SIM';
    public const VERSION = '2.1.0';

    // File Uploads
    public const UPLOAD_DIR = __DIR__ . '/../../uploads';
    public const UPLOAD_FREEZERS = self::UPLOAD_DIR . '/freezers';
    public const UPLOAD_STORES = self::UPLOAD_DIR . '/stores';
    public const UPLOAD_IOT = self::UPLOAD_DIR . '/iot';
    public const UPLOAD_QR = self::UPLOAD_DIR . '/qrcodes';
    /** Staging folder for CSV import photos (filename listed in CSV columns) */
    public const UPLOAD_IMPORT = self::UPLOAD_DIR . '/import';

    public const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    public const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    // User Roles
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_TECHNICIAN = 'technician';
    public const ROLE_VIEWER = 'viewer';

    /** @return list<array{value:string,label:string}> */
    public static function getUserRoles(): array {
        return [
            ['value' => self::ROLE_SUPER_ADMIN, 'label' => 'Super Admin — Full access'],
            ['value' => self::ROLE_TECHNICIAN, 'label' => 'Technician — Field scan / register'],
            ['value' => self::ROLE_VIEWER, 'label' => 'Viewer — View only'],
        ];
    }

    /** @return list<string> */
    public static function allowedRoles(): array {
        return array_column(self::getUserRoles(), 'value');
    }

    /** Store / user area zones (same as profile forms). */
    public const ZONES = ['CENTRAL', 'WEST', 'NORTH', 'SOUTH', 'EAST'];

    /** @return list<string> */
    public static function zones(): array {
        return self::ZONES;
    }

    /** IoT capture / telemetry UI (serial, SIM, photos, dashboard) — next phase */
    public const IOT_ENABLED = false;

    /**
     * Elixia VTS live tracking (warehouse / freezer GPS + status + temp).
     * Shown as a separate tab on the same store profile — not mixed with app fields.
     * Use HTTP: HTTPS on this host returns the web app HTML instead of JSON.
     */
    public const ELIXIA_VTS_ENABLED = true;
    public const ELIXIA_VTS_BASE_URL = 'http://speed.elixiatech.com/modules/api/vts/api.php';
    /** Rotate this key in production; do not commit real secrets long-term. */
    public const ELIXIA_VTS_USERKEY = '2c104e67604b1b88d8a7f17673084effba0b57b0';
    public const ELIXIA_VTS_PAGE_SIZE = 40; // API rejects larger page sizes
    public const ELIXIA_VTS_CACHE_SECONDS = 180; // 3 minutes
    public const ELIXIA_VTS_CACHE_FILE = __DIR__ . '/../../uploads/cache/elixia_vts_warehouse.json';

    /**
     * Optional outbound notify webhook (WhatsApp/SMS gateway).
     * Empty = log-only via NotifyService. HTTPS POST JSON when set.
     */
    public const NOTIFY_WEBHOOK_URL = '';

    /**
     * JWT auth (HS256). Change JWT_SECRET in production (min 32 chars).
     * Cookie holds the token for browser pages; APIs may send Authorization: Bearer.
     */
    public const JWT_SECRET = 'change-me-reeferon-df-jwt-secret-32chars-min';
    public const JWT_TTL_SECONDS = 60 * 60 * 12; // 12 hours
    public const JWT_COOKIE = 'reeferon_jwt';

    /**
     * Default Super Admin / seed password (local demo).
     * Change this before any shared or production deploy.
     */
    public const DEFAULT_ADMIN_PASSWORD = 'Admin@123';

    /** Default SIM / ICCID prefill (user can edit) */
    public const DEFAULT_SIM_NUMBER = '89919225034008649389';
    public const DEFAULT_SIM_COMPANY = 'Airtel';

    public static function getBaseUrl(): string {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($script));
        $dir = ($dir === '/' || $dir === '.') ? '' : $dir;
        return rtrim($protocol . $host . $dir, '/');
    }

    public static function initUploadDirs(): void {
        $dirs = [
            self::UPLOAD_DIR,
            self::UPLOAD_FREEZERS,
            self::UPLOAD_STORES,
            self::UPLOAD_IOT,
            self::UPLOAD_QR,
            self::UPLOAD_IMPORT,
        ];
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                mkdir($d, 0777, true);
            }
        }
    }
}
