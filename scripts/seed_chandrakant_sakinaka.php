<?php
/**
 * Seed one real store+freezer from GPS Map Camera / Google Maps pin:
 * Chandrakant Farsan Mart, Saki Naka, Mumbai
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Models\Freezer;
use App\Models\MasterData;
use App\Models\Store;

AppConfig::initUploadDirs();
Freezer::ensureSimColumns();

$src = 'C:\\Users\\Lenovo\\.cursor\\projects\\c-wamp64-www-DF-application\\assets\\WhatsApp_Image_2026-10-31_at_3.24.08_PM-575ffb9c-5393-4cb2-b489-fdb2d33885e3.jpg';
if (!is_file($src)) {
    // Fallback: any matching WhatsApp image in Cursor assets
    $glob = glob('C:\\Users\\Lenovo\\.cursor\\projects\\c-wamp64-www-DF-application\\assets\\WhatsApp_Image_2026-10-31*.jpg');
    $src = $glob[0] ?? '';
}
if ($src === '' || !is_file($src)) {
    fwrite(STDERR, "Source photo not found.\n");
    exit(1);
}

$storeRel = 'uploads/stores/chandrakant_sakinaka_storefront.jpg';
$freezerRel = 'uploads/freezers/chandrakant_sakinaka_freezer_full.jpg';
$storeAbs = dirname(__DIR__) . '/' . $storeRel;
$freezerAbs = dirname(__DIR__) . '/' . $freezerRel;

if (!@copy($src, $storeAbs)) {
    fwrite(STDERR, "Failed to copy store photo.\n");
    exit(1);
}
if (!@copy($src, $freezerAbs)) {
    fwrite(STDERR, "Failed to copy freezer photo.\n");
    exit(1);
}

$lat = 19.097578;
$lng = 72.8862853;
$mapsLink = 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng);

$city = 'Mumbai';
$pincode = '400072';
$locationCode = MasterData::resolveLocationCodeForCity($city, 'MUM1');
$outletId = Store::generateOutletId([
    'city' => $city,
    'pincode' => $pincode,
    'location_code' => $locationCode,
]);

$storeId = Store::create([
    'outlet_id' => $outletId,
    'store_name' => 'Chandrakant Farsan Mart',
    'customer_name' => 'Hangyo Ice Cream',
    'distributor_name' => '',
    'contact_person' => 'Chandrakant',
    'phone_number' => '',
    'email' => '',
    'address' => '7, Kajupada Pipeline Rd, Ashtavinayak CHS, Krishna Nagar, Safed Pul, Saki Naka',
    'city' => $city,
    'state' => 'Maharashtra',
    'pincode' => $pincode,
    'zone' => 'WEST',
    'location_type' => 'Retail Store',
    'latitude' => $lat,
    'longitude' => $lng,
    'gps_accuracy' => 12,
    'gps_captured_at' => '2026-10-31 15:08:00',
    'location_link' => $mapsLink,
    'image_store_front' => $storeRel,
    'status' => 'Active',
]);

$freezerId = Freezer::create([
    'store_id' => $storeId,
    'freezer_type' => 'DF',
    'company_code' => 'OT',
    'model_number' => 'SNOWLOPS',
    'capacity_litres' => 550,
    'freezer_condition' => 'WORKING CONDITION',
    'freezer_placed_at' => 'Shop entrance / front',
    'location_code' => $locationCode,
    'pincode' => $pincode,
    'install_month_year' => '1026',
    'installation_date' => '2026-10-31',
    'image_left_side' => $freezerRel,
    'image_right_side' => null,
    'status' => 'Active',
    'notes' => 'Seeded from GPS Map Camera + Google Maps Street View (Saki Naka). Outdoor chest freezer with Hangyo / Snowlops branding; Baskin Robbins standee visible left.',
    'sim_number' => null,
    'sim_company' => null,
]);

$freezer = Freezer::findById($freezerId);

echo "OK store_id={$storeId} outlet={$outletId}\n";
echo "OK freezer_id={$freezerId} unique_id=" . ($freezer['unique_freezer_id'] ?? '') . "\n";
echo "Maps: {$mapsLink}\n";
echo "View: profile_view.php?id={$freezerId}\n";
