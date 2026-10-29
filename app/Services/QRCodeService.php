<?php
namespace App\Services;

use App\Config\AppConfig;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;
use Exception;

class QRCodeService {

    /**
     * Generates a QR Code for the given Freezer ID and saves it to disk.
     * Returns relative web path (e.g. 'uploads/qrcodes/DF-RW-....svg').
     */
    public static function generateAndSave(string $freezerId, ?string $customPayloadUrl = null): array {
        AppConfig::initUploadDirs();

        // Safe filename
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $freezerId);
        $fileName = 'qr_' . $safeName . '.svg';
        $fullPath = AppConfig::UPLOAD_QR . '/' . $fileName;
        $relPath = 'uploads/qrcodes/' . $fileName;

        // Content encoded in QR
        // If customPayloadUrl is provided, use it; otherwise link to the profile view URL
        $qrPayload = $customPayloadUrl ?: self::getProfileScanUrl($freezerId);

        try {
            $options = new QROptions([
                'version' => QRCode::VERSION_AUTO,
                'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                'eccLevel' => QRCode::ECC_M,
                'addQuietzone' => true,
                'quietzoneSize' => 3,
                'scale' => 6,
                'svgUseFill' => true,
                'svgDefs' => '',
            ]);

            $qrcode = new QRCode($options);
            $svgContent = $qrcode->render($qrPayload);

            // If chillerlan rendered as data URI 'data:image/svg+xml;base64,...', decode to raw SVG for file save
            if (strpos($svgContent, 'data:image/svg+xml;base64,') === 0) {
                $rawSvg = base64_decode(substr($svgContent, strlen('data:image/svg+xml;base64,')));
            } else {
                $rawSvg = $svgContent;
            }

            file_put_contents($fullPath, $rawSvg);

            return [
                'success' => true,
                'file_path' => $relPath,
                'full_path' => $fullPath,
                'payload' => $qrPayload,
                'svg_data_uri' => $svgContent,
            ];
        } catch (Exception $e) {
            error_log("QRCode generation error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'file_path' => null,
                'payload' => $qrPayload,
                'svg_data_uri' => null,
            ];
        }
    }

    /**
     * Builds the target URL that will be opened when scanned with a mobile phone camera.
     */
    public static function getProfileScanUrl(string $freezerId): string {
        $baseUrl = AppConfig::getBaseUrl();
        return $baseUrl . '/profile_view.php?id=' . urlencode($freezerId);
    }

    /**
     * Generates a base64 Data URI on the fly for immediate inline display.
     */
    public static function getInlineDataUri(string $freezerId): string {
        $payload = self::getProfileScanUrl($freezerId);
        $options = new QROptions([
            'version' => QRCode::VERSION_AUTO,
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_M,
            'addQuietzone' => true,
            'scale' => 5,
        ]);
        $qrcode = new QRCode($options);
        return $qrcode->render($payload);
    }
}
