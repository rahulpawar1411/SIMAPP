<?php
namespace App\Services;

use App\Config\AppConfig;
use RuntimeException;
use finfo;

class FileUploadService {

    /**
     * Handles single image upload with rigorous validation.
     *
     * @param array $file $_FILES['image_field']
     * @param string $subDir 'freezers' or 'iot'
     * @param string $prefix Prefix for generated filename
     * @return array [success => bool, path => string|null, error => string|null]
     */
    public static function handleImageUpload(array $file, string $subDir = 'freezers', string $prefix = 'img'): array {
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
        $randomHex = bin2hex(random_bytes(10));
        $timestamp = time();
        $safeFileName = sprintf('%s_%d_%s.%s', $prefix, $timestamp, $randomHex, $extension);

        $targetDir = match ($subDir) {
            'iot' => AppConfig::UPLOAD_IOT,
            'stores' => AppConfig::UPLOAD_STORES,
            default => AppConfig::UPLOAD_FREEZERS,
        };
        if (!in_array($subDir, ['iot', 'stores', 'freezers'], true)) {
            $subDir = 'freezers';
        }
        $targetFilePath = $targetDir . '/' . $safeFileName;
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
        $safeFileName = sprintf('%s_%d_%s.%s', $prefix, time(), bin2hex(random_bytes(8)), $extension);

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
