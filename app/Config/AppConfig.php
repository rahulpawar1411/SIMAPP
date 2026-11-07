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
            ['value' => self::ROLE_TECHNICIAN, 'label' => 'Technician — Field scan / register / KYC'],
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

    /** Default SIM / ICCID prefill (user can edit) */
    public const DEFAULT_SIM_NUMBER = '89919225034008649';
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
