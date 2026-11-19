<?php
/**
 * Seed: Sheraton Traders, Khar West — store front + freezer full photos
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\MasterData;
use App\Models\Store;

AppConfig::initUploadDirs();
Freezer::ensureSimColumns();

$assets = 'C:\\Users\\Lenovo\\.cursor\\projects\\c-wamp64-www-DF-application\\assets\\';
$storeSrc = $assets . 'WhatsApp_Image_2026-11-18_at_2.35.09_PM-ec42f754-3204-4989-bbbc-41cc739feb13.jpg';
$freezerSrc = $assets . 'WhatsApp_Image_2026-11-18_at_2.39.46_PM-59871097-d1f3-4fbd-b2a8-85f0a631bfaf.jpg';

if (!is_file($storeSrc)) {
    $g = glob($assets . 'WhatsApp_Image_2026-11-18_at_2.35*.jpg');
    $storeSrc = $g[0] ?? '';
}
if (!is_file($freezerSrc)) {
    $g = glob($assets . 'WhatsApp_Image_2026-11-18_at_2.39*.jpg');
    $freezerSrc = $g[0] ?? '';
}
if ($storeSrc === '' || !is_file($storeSrc) || $freezerSrc === '' || !is_file($freezerSrc)) {
    fwrite(STDERR, "Photos not found.\n");
    exit(1);
}

$storeRel = 'uploads/stores/sheraton_traders_khar_storefront.jpg';
$freezerRel = 'uploads/freezers/sheraton_traders_khar_freezer_full.jpg';
$root = dirname(__DIR__);
if (!@copy($storeSrc, $root . '/' . $storeRel) || !@copy($freezerSrc, $root . '/' . $freezerRel)) {
    fwrite(STDERR, "Copy failed.\n");
    exit(1);
}

$lat = 19.075208;
$lng = 72.825587;
$mapsLink = 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng);

$city = 'Mumbai';
$pincode = '400052';
$locationCode = MasterData::resolveLocationCodeForCity($city, 'MUM1');
$outletId = Store::generateOutletId([
    'city' => $city,
    'pincode' => $pincode,
    'location_code' => $locationCode,
]);

$storeId = Store::create([
    'outlet_id' => $outletId,
    'store_name' => 'Sheraton Traders',
    'customer_name' => 'FreshFields',
    'distributor_name' => '',
    'contact_person' => '',
    'phone_number' => '8652715984',
    'email' => '',
    'address' => 'Ground Floor, Sunvision Spenta, 3rd Rd, Khar, Ram Krishna Nagar, Khar West',
    'city' => $city,
    'state' => 'Maharashtra',
    'pincode' => $pincode,
    'zone' => 'WEST',
    'location_type' => 'Retail Store',
    'latitude' => $lat,
    'longitude' => $lng,
    'gps_accuracy' => 12,
    'gps_captured_at' => '2026-10-31 14:02:00',
    'location_link' => $mapsLink,
    'image_store_front' => $storeRel,
    'status' => 'Active',
]);

$freezerId = Freezer::create([
    'store_id' => $storeId,
    'freezer_type' => 'DF',
    'company_code' => 'OT',
    'model_number' => 'FRESHFIELDS',
    'capacity_litres' => 550,
    'freezer_condition' => 'WORKING CONDITION',
    'freezer_placed_at' => 'Inside shop (sales floor)',
    'location_code' => $locationCode,
    'pincode' => $pincode,
    'install_month_year' => '1026',
    'installation_date' => '2026-10-31',
    'image_left_side' => $freezerRel,
    'image_right_side' => null,
    'status' => 'Active',
    'notes' => 'Sheraton Traders, Khar West. FreshFields / Plantaway / Graviss chest freezer. Seeded from GPS Map Camera photos.',
    'sim_number' => null,
    'sim_company' => null,
]);

$freezer = Freezer::findById($freezerId);

echo "OK store_id={$storeId} outlet={$outletId}\n";
echo "OK freezer_id={$freezerId} unique_id=" . ($freezer['unique_freezer_id'] ?? '') . "\n";
echo "Phone: 8652715984\n";
echo "Maps: {$mapsLink}\n";
echo "View: profile_view.php?id={$freezerId}\n";
