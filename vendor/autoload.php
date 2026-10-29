<?php
// Autoloader for SIM App & Vendor libraries (PSR-4)

spl_autoload_register(function ($class) {
    $prefixes = [
        'App\\' => __DIR__ . '/../app/',
        'chillerlan\\QRCode\\' => __DIR__ . '/chillerlan/php-qrcode/src/',
        'chillerlan\\Settings\\' => __DIR__ . '/chillerlan/php-settings-container/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
    }
    return false;
});
