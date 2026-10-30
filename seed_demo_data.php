<?php
// Seed realistic demo stores, freezers, sample images, QR codes, and IoT telemetry

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Config\AppConfig;
use App\Models\Store;
use App\Models\Freezer;
use App\Models\IoTDevice;
use App\Services\QRCodeService;

AppConfig::initUploadDirs();

echo "Seeding initial store and freezer records...\n";

// Helper to generate a clean sample SVG image
function createSampleImage(string $path, string $title, string $subtitle, string $bgColor = '#1e293b', string $accentColor = '#3b82f6'): void {
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" width="100%" height="100%">
  <defs>
    <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$bgColor}" />
      <stop offset="100%" stop-color="#0f172a" />
    </linearGradient>
    <pattern id="grid" width="40" height="40" patternUnits="userSpaceOnUse">
      <path d="M 40 0 L 0 0 0 40" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="1"/>
    </pattern>
  </defs>
  <rect width="800" height="600" fill="url(#grad)" />
  <rect width="800" height="600" fill="url(#grid)" />
  
  <!-- Outer Frame -->
  <rect x="50" y="50" width="700" height="500" rx="20" fill="none" stroke="{$accentColor}" stroke-width="3" stroke-dasharray="10 5" opacity="0.6"/>
  
  <!-- Icon Graphics -->
  <g transform="translate(400, 240)">
    <circle r="70" fill="{$accentColor}" opacity="0.15"/>
    <circle r="50" fill="{$accentColor}" opacity="0.25"/>
    <path d="M-25,-20 L25,-20 L35,25 L-35,25 Z" fill="none" stroke="{$accentColor}" stroke-width="4" stroke-linejoin="round"/>
    <line x1="-35" y1="5" x2="35" y2="5" stroke="{$accentColor}" stroke-width="3"/>
    <circle cx="0" cy="15" r="4" fill="{$accentColor}"/>
  </g>
  
  <text x="400" y="370" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="28" font-weight="bold" fill="#ffffff" text-anchor="middle">{$title}</text>
  <text x="400" y="410" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="16" fill="#94a3b8" text-anchor="middle">{$subtitle}</text>
  <text x="400" y="440" font-family="monospace" font-size="14" fill="{$accentColor}" text-anchor="middle">ReeferON RaaS Asset Verification System</text>
  
  <!-- Watermark Tag -->
  <rect x="70" y="70" width="160" height="32" rx="6" fill="rgba(15, 23, 42, 0.8)"/>
  <text x="150" y="92" font-family="sans-serif" font-size="12" font-weight="600" fill="#38bdf8" text-anchor="middle">LIVE VERIFIED</text>
</svg>
SVG;
    file_put_contents($path, $svg);
}

// Create sample image files
$img1Left = AppConfig::UPLOAD_FREEZERS . '/demo_frz_sfr550_left.svg';
$img1Right = AppConfig::UPLOAD_FREEZERS . '/demo_frz_sfr550_right.svg';
$img1Iot = AppConfig::UPLOAD_IOT . '/demo_iot_device_rf100.svg';

createSampleImage($img1Left, 'Freezer Left Side View', 'Rockwell SFR 550 DDU • Clean Condenser & Power Inlet', '#1e293b', '#38bdf8');
createSampleImage($img1Right, 'Freezer Right Side View', 'Rockwell SFR 550 DDU • Safety Label & Temperature Dial', '#1e293b', '#0ea5e9');
createSampleImage($img1Iot, 'IoT Sensor Device Attachment', 'ReeferON RF-T100 Temp/GPS Tracker • Antenna Fixed', '#0f172a', '#10b981');

// Create Store 1: Ahmedabad Store
$store1Id = Store::create([
    'outlet_id' => 'W-TVFPL-20538',
    'store_name' => 'Baskin Robbins - CG Road Hub',
    'customer_name' => 'Baskin Robbins India',
    'distributor_name' => 'Royal Distributor West',
    'contact_person' => 'Ashish Jyothi',
    'phone_number' => '+91 88790 33720',
    'email' => 'ashish.jyothi@baskinrobbinsindia.com',
    'address' => 'Shop 4, Ground Floor, CG Square Mall, CG Road, Navrangpura',
    'city' => 'Ahmedabad',
    'state' => 'Gujarat',
    'pincode' => '380058',
    'zone' => 'WEST',
    'location_type' => 'High Street Parlour',
    'latitude' => 23.033812,
    'longitude' => 72.557014,
    'gps_accuracy' => 3.2,
    'status' => 'Active',
]);

// Create Freezer 1: Exactly the sample ID requested: DF-RW-SFR550DDU-AMD1-058-0324-AAA004
$freezer1Id = Freezer::create([
    'unique_freezer_id' => 'DF-RW-SFR550DDU-AMD1-058-0324-AAA004',
    'store_id' => $store1Id,
    'freezer_type' => 'DF',
    'company_code' => 'RW',
    'model_number' => 'SFR550DDU',
    'capacity_litres' => 550,
    'freezer_condition' => 'WORKING CONDITION',
    'location_code' => 'AMD1',
    'pincode' => '380058',
    'install_month_year' => '0324',
    'unit_sequence' => 'AAA004',
    'installation_date' => '2024-03-15',
    'image_left_side' => 'uploads/freezers/demo_frz_sfr550_left.svg',
    'image_right_side' => 'uploads/freezers/demo_frz_sfr550_right.svg',
    'image_iot_device' => 'uploads/iot/demo_iot_device_rf100.svg',
    'status' => 'Active',
    'notes' => 'Installed at primary counter. IoT device calibrated to -22°C baseline.',
    'iot_serial' => 'RF-IOT-99210',
    'iot_imei' => '864201048291048',
    'iot_type' => 'Temperature & GPS',
    'iot_owner' => 'ReeferON Own Device',
    'iot_model' => 'RF-T100',
]);
IoTDevice::seedSampleTelemetry($freezer1Id);

// Create Store 2: Mumbai Lower Parel (from Propelpad SIM.xlsx)
$store2Id = Store::create([
    'outlet_id' => 'W-TVFPL-20534',
    'store_name' => 'Zafran - Lower Parel Outlet',
    'customer_name' => 'The Viral Fever (TVF)',
    'distributor_name' => 'Royal Distributor',
    'contact_person' => 'Pramod Kharvar',
    'phone_number' => '+91 91520 17467',
    'email' => 'pramod.kharvar@baskinrobbinsindia.com',
    'address' => 'Shop No 2, Ground Floor, Shivmudra CHS, Bldg No 3 LR Pappan Road, Off E Moses Road Worli',
    'city' => 'Mumbai',
    'state' => 'Maharashtra',
    'pincode' => '400018',
    'zone' => 'WEST',
    'location_type' => 'Market',
    'latitude' => 18.998620,
    'longitude' => 72.825830,
    'gps_accuracy' => 4.5,
    'status' => 'Active',
]);

// Create Freezer 2: Deep Chest Freezer (DCF) Voltas
$freezer2Id = Freezer::create([
    'unique_freezer_id' => 'DCF-VL-CHF500-MUM1-018-0424-T7K2M9',
    'store_id' => $store2Id,
    'freezer_type' => 'DCF',
    'company_code' => 'VL',
    'model_number' => 'CHF500',
    'capacity_litres' => 500,
    'freezer_condition' => 'WORKING CONDITION',
    'location_code' => 'MUM1',
    'pincode' => '400018',
    'install_month_year' => '0424',
    'unit_sequence' => 'T7K2M9',
    'installation_date' => '2024-04-10',
    'image_left_side' => 'uploads/freezers/demo_frz_sfr550_left.svg',
    'image_right_side' => 'uploads/freezers/demo_frz_sfr550_right.svg',
    'image_iot_device' => 'uploads/iot/demo_iot_device_rf100.svg',
    'status' => 'Active',
    'notes' => 'Chest freezer dedicated for ice cream tubs.',
    'iot_serial' => 'RF-IOT-99211',
    'iot_imei' => '864201048291049',
    'iot_type' => 'Temperature & GPS',
    'iot_owner' => 'ReeferON Own Device',
    'iot_model' => 'RF-T100',
]);
IoTDevice::seedSampleTelemetry($freezer2Id);

echo "Demo stores, freezers, and telemetry seeded successfully!\n";
