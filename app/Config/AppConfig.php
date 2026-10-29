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

    public const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    public const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    // User Roles
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_SUB_ADMIN = 'sub_admin';
    public const ROLE_TECHNICIAN = 'technician';
    public const ROLE_VIEWER = 'viewer';

    /** IoT capture / telemetry UI — set true to re-enable */
    public const IOT_ENABLED = false;

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
        ];
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                mkdir($d, 0777, true);
            }
        }
    }
}
