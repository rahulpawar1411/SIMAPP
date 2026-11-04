<?php
namespace App\Services;

use App\Config\AppConfig;
use RuntimeException;
use finfo;

class FileUploadService {

    /**
     * Map upload role / input key → readable image-type label for filename.
     */
    public static function imageTypeLabel(string $role): string {
        $map = [
            'store_front' => 'Store-Front',
            'aadhar_front' => 'Aadhaar-Front',
            'aadhar_back' => 'Aadhaar-Back',
            'aadhar_card' => 'Aadhaar-Front',
            'frz_left' => 'Freezer-Left-Side',
            'frz_right' => 'Freezer-Right-Side',
            'image_left_side' => 'Freezer-Left-Side',
            'image_right_side' => 'Freezer-Right-Side',
            'image_store_front' => 'Store-Front',
            'image_aadhar_card' => 'Aadhaar-Front',
            'image_aadhar_back' => 'Aadhaar-Back',
            'image_iot_device' => 'IoT-Device',
            'iot_dev' => 'IoT-Device',
            'image_sim' => 'SIM-Number',
            'sim_img' => 'SIM-Number',
            'kyc_store' => 'KYC-Store-Front',
            'kyc_frz' => 'KYC-Freezer',
            'image_freezer' => 'KYC-Freezer',
        ];
        $key = strtolower(trim($role));
        if (isset($map[$key])) {
            return $map[$key];
        }
        $clean = preg_replace('/[^A-Za-z0-9]+/', '-', $role);
        $clean = trim((string)$clean, '-');
        return $clean !== '' ? $clean : 'Photo';
    }

    /**
     * Build safe filename: TagCode-YYYYMMDD-StoreName-ImageType.ext
     * (no HHMMSS — image type from input/role instead)
     *
     * @param array{tag_code?:string,store_name?:string,datetime?:string,role?:string} $meta
     */
    public static function buildNamedFileName(string $extension, string $roleFallback = 'img', array $meta = []): string {
        $tag = strtoupper(trim((string)($meta['tag_code'] ?? '')));
        $tag = preg_replace('/[^A-Z0-9\-]/', '', $tag) ?: 'TAG';

        // Date only (YYYYMMDD) — time replaced by image type
        $dt = trim((string)($meta['datetime'] ?? ''));
        if (preg_match('/^(\d{8})/', $dt, $m)) {
            $datePart = $m[1];
        } else {
            $datePart = date('Ymd');
        }

        $store = trim((string)($meta['store_name'] ?? ''));
        $store = preg_replace('/\s+/', '-', $store);
        $store = preg_replace('/[^A-Za-z0-9\-_]/', '', $store) ?: 'Store';
        $store = substr($store, 0, 40);

        $role = trim((string)($meta['role'] ?? $roleFallback));
        $typeLabel = self::imageTypeLabel($role);

        return $tag . '-' . $datePart . '-' . $store . '-' . $typeLabel . '.' . $extension;
    }

    /**
     * Handles single image upload with rigorous validation.
     *
     * @param array $file $_FILES['image_field']
     * @param string $subDir 'freezers' or 'iot'
     * @param string $prefix Role suffix in filename (store_front, frz_left, …)
     * @param array{tag_code?:string,store_name?:string,datetime?:string} $meta Naming context
     * @return array [success => bool, path => string|null, error => string|null]
     */
    public static function handleImageUpload(array $file, string $subDir = 'freezers', string $prefix = 'img', array $meta = []): array {
        AppConfig::initUploadDirs();

        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'error' => 'Invalid file upload parameters.', 'path' => null];
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return ['success' => false, 'error' => 'No file was uploaded.', 'path' => null];
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'error' => 'File exceeds maximum upload size (max 10MB).', 'path' => null];
            default:
                return ['success' => false, 'error' => 'Upload error code: ' . $file['error'], 'path' => null];
        }

        // Check file size
        if ($file['size'] > AppConfig::MAX_FILE_SIZE) {
            return ['success' => false, 'error' => 'File size exceeds 10MB limit.', 'path' => null];
        }

        // Verify MIME type using finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!array_key_exists($mime, AppConfig::ALLOWED_IMAGE_MIMES)) {
            return [
                'success' => false,
                'error' => "Invalid file format ($mime). Only JPG, PNG, and WebP images are permitted.",
                'path' => null
            ];
        }

        // Additional image integrity check
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return ['success' => false, 'error' => 'Uploaded file is not a valid image.', 'path' => null];
        }

        $extension = AppConfig::ALLOWED_IMAGE_MIMES[$mime];
        $meta['role'] = $prefix;
        if (empty($meta['datetime'])) {
            $meta['datetime'] = date('Ymd');
        }
        $safeFileName = self::buildNamedFileName($extension, $prefix, $meta);

        $targetDir = match ($subDir) {
            'iot' => AppConfig::UPLOAD_IOT,
            'stores' => AppConfig::UPLOAD_STORES,
            default => AppConfig::UPLOAD_FREEZERS,
        };
        if (!in_array($subDir, ['iot', 'stores', 'freezers'], true)) {
            $subDir = 'freezers';
        }

        // Uniqueness: same tag/date/store/type → append short id (keep type readable)
        $targetFilePath = $targetDir . '/' . $safeFileName;
        if (file_exists($targetFilePath)) {
            $typeLabel = self::imageTypeLabel($prefix);
            $tag = preg_replace('/[^A-Z0-9\-]/', '', strtoupper(trim((string)($meta['tag_code'] ?? '')))) ?: 'TAG';
            $datePart = preg_match('/^(\d{8})/', (string)($meta['datetime'] ?? ''), $m) ? $m[1] : date('Ymd');
            $store = preg_replace('/[^A-Za-z0-9\-_]/', '', preg_replace('/\s+/', '-', trim((string)($meta['store_name'] ?? 'Store')))) ?: 'Store';
            $store = substr($store, 0, 40);
            $safeFileName = $tag . '-' . $datePart . '-' . $store . '-' . $typeLabel . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.' . $extension;
            $targetFilePath = $targetDir . '/' . $safeFileName;
        }
        $relativeWebPath = 'uploads/' . $subDir . '/' . $safeFileName;

        if (!move_uploaded_file($file['tmp_name'], $targetFilePath)) {
            return ['success' => false, 'error' => 'Failed to save uploaded file to disk.', 'path' => null];
        }

        return [
            'success' => true,
            'path' => $relativeWebPath,
            'full_path' => $targetFilePath,
            'mime' => $mime,
            'size' => $file['size'],
            'error' => null
        ];
    }

    /**
     * Promote a file from uploads/import into stores|freezers|iot.
     * $filename = exact basename from CSV (e.g. store_cg_front.jpg). Returns relative web path or null.
     */
    public static function promoteImportImage(?string $filename, string $subDir = 'freezers', string $prefix = 'img'): ?string {
        $filename = trim((string)$filename);
        if ($filename === '') {
            return null;
        }

        // Basename only — block path traversal
        $filename = basename(str_replace(['\\', "\0"], ['/', ''], $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new RuntimeException('Invalid image filename.');
        }

        AppConfig::initUploadDirs();
        $source = AppConfig::UPLOAD_IMPORT . '/' . $filename;
        if (!is_file($source)) {
            throw new RuntimeException("Import image not found in uploads/import/: {$filename}");
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($source);
        if (!array_key_exists($mime, AppConfig::ALLOWED_IMAGE_MIMES)) {
            throw new RuntimeException("Invalid image type for {$filename}. Use JPG, PNG, or WebP.");
        }
        if (@getimagesize($source) === false) {
            throw new RuntimeException("File is not a valid image: {$filename}");
        }
        if (filesize($source) > AppConfig::MAX_FILE_SIZE) {
            throw new RuntimeException("Image exceeds 10MB: {$filename}");
        }

        $extension = AppConfig::ALLOWED_IMAGE_MIMES[$mime];
        $safeFileName = self::buildNamedFileName($extension, $prefix, [
            'tag_code' => 'IMPORT',
            'store_name' => pathinfo($filename, PATHINFO_FILENAME) ?: 'Store',
            'datetime' => date('Ymd'),
            'role' => $prefix,
        ]);

        $targetDir = match ($subDir) {
            'iot' => AppConfig::UPLOAD_IOT,
            'stores' => AppConfig::UPLOAD_STORES,
            default => AppConfig::UPLOAD_FREEZERS,
        };
        $targetFilePath = $targetDir . '/' . $safeFileName;
        $relativeWebPath = 'uploads/' . $subDir . '/' . $safeFileName;

        if (!copy($source, $targetFilePath)) {
            throw new RuntimeException("Failed to copy import image: {$filename}");
        }

        return $relativeWebPath;
    }

    /**
     * Safely deletes an existing uploaded file from disk.
     */
    public static function deleteFile(?string $relativePath): void {
        if (empty($relativePath)) {
            return;
        }

        // Prevent path traversal
        $normalized = str_replace(['..', '\\'], ['', '/'], $relativePath);
        $fullPath = AppConfig::UPLOAD_DIR . '/../' . ltrim($normalized, '/');
        $real = realpath($fullPath);

        if ($real && file_exists($real) && strpos($real, realpath(AppConfig::UPLOAD_DIR)) === 0) {
            @unlink($real);
        }
    }
}
