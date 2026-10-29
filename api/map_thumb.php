<?php
/**
 * Proxy + pin: OpenStreetMap tile thumbnail for GPS stamp (avoids browser CORS).
 */
header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 0;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : 0;
$z = 16;

if ($lat < -85 || $lat > 85 || $lng < -180 || $lng > 180) {
    $lat = 19.0760;
    $lng = 72.8777;
}

$size = 280;
$n = 2 ** $z;
$xTile = (int)floor(($lng + 180.0) / 360.0 * $n);
$latRad = deg2rad($lat);
$yTile = (int)floor((1.0 - log(tan($latRad) + (1.0 / cos($latRad))) / M_PI) / 2.0 * $n);
$xTile = max(0, min($n - 1, $xTile));
$yTile = max(0, min($n - 1, $yTile));

$tileUrl = "https://tile.openstreetmap.org/{$z}/{$xTile}/{$yTile}.png";

$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: ReeferON-SIM/2.1 (GPS stamp; local asset tool)\r\nAccept: image/png\r\n",
        'timeout' => 8,
    ],
]);

$tileData = @file_get_contents($tileUrl, false, $ctx);
$out = imagecreatetruecolor($size, $size);

if ($tileData !== false) {
    $tile = @imagecreatefromstring($tileData);
    if ($tile) {
        imagecopyresampled($out, $tile, 0, 0, 0, 0, $size, $size, imagesx($tile), imagesy($tile));
        imagedestroy($tile);
    } else {
        $bg = imagecolorallocate($out, 30, 41, 59);
        imagefilledrectangle($out, 0, 0, $size, $size, $bg);
    }
} else {
    $bg = imagecolorallocate($out, 30, 41, 59);
    imagefilledrectangle($out, 0, 0, $size, $size, $bg);
}

// Red map pin at center
$cx = (int)($size / 2);
$cy = (int)($size / 2) - 8;
$red = imagecolorallocate($out, 220, 38, 38);
$white = imagecolorallocate($out, 255, 255, 255);
imagefilledellipse($out, $cx, $cy, 28, 28, $red);
imagefilledellipse($out, $cx, $cy, 12, 12, $white);
// Pin tip
$points = [$cx, $cy + 34, $cx - 12, $cy + 10, $cx + 12, $cy + 10];
imagefilledpolygon($out, $points, $red);

imagepng($out);
imagedestroy($out);
