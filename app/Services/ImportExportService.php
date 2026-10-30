<?php
namespace App\Services;

use App\Models\Store;
use App\Models\Freezer;
use App\Models\MasterData;
use App\Services\FileUploadService;
use Exception;

class ImportExportService {

    /**
     * Canonical CSV columns — Freezer ID parts kept in sequence order:
     * TYPE → BRAND → MODEL → LOCATION → MMYY → PIN → (random seq auto) → UNIQUE_ID
     */
    public static function hubColumns(): array {
        return [
            // Filter sequence: Zone → State → City → PIN → location type
            'zone',
            'state',
            'city',
            'pincode',
            'location_type',
            'location_code',
            // Store identity
            'outlet_id',
            'store_name',
            'customer_name',
            'distributor_name',
            'address',
            'latitude',
            'longitude',
            // Operator / compliance
            'contact_person',
            'phone_number',
            'email',
            'aadhar_number',
            'fssai_number',
            'gst_number',
            // Store + Aadhaar photos (filename → uploads/import/)
            'image_store_front',
            'image_aadhar_card',
            'image_aadhar_back',
            // Full names in CSV; system maps to short codes for Unique ID (Deep Freezer→DF, Rockwell→RW)
            'freezer_type',
            'company',
            'model_number',
            'installation_date',
            'unique_freezer_id',
            'capacity_litres',
            'freezer_condition',
            'status',
            // Freezer photos
            'image_left_side',
            'image_right_side',
            // IoT device (optional — blank = no device)
            'iot_serial',
            'iot_imei',
            'iot_sim_number',
            'iot_sim_company',
            'iot_type',
            'iot_owner',
            'iot_model',
            'image_iot_device',
            'notes',
        ];
    }

    public static function sampleRows(): array {
        // Combined Store + Freezer hub samples (latest rules)
        // Blank outlet_id → auto AMD1-380009-0001 style
        // Blank unique_freezer_id → auto from ID parts
        // Photos = filename only in uploads/import/
        $base = [
            'zone' => 'WEST',
            'state' => 'Gujarat',
            'city' => 'Ahmedabad',
            'pincode' => '380009',
            'location_type' => 'Retail Store',
            'location_code' => 'AMD1', // same style as freezer Unique ID location part
            'outlet_id' => '', // blank → auto e.g. AMD1-380009-0001
            'store_name' => 'Baskin Robbins - CG Road Hub',
            'customer_name' => 'Baskin Robbins India',
            'distributor_name' => '',
            'address' => 'Shop 12, CG Road',
            'latitude' => '', // blank → auto city-center GPS on import (map still shows)
            'longitude' => '',
            'contact_person' => 'Store Manager',
            'phone_number' => '9876543210',
            'email' => 'manager@example.com',
            'aadhar_number' => '',
            'fssai_number' => '',
            'gst_number' => '',
            'image_store_front' => 'store_1_front.jpg',
            'image_aadhar_card' => 'store_1_aadhar_front.jpg',
            'image_aadhar_back' => 'store_1_aadhar_back.jpg',
            'freezer_type' => 'Deep Freezer',
            'company' => 'Rockwell',
            'model_number' => 'SFR550DDU',
            'installation_date' => date('Y-m-d'),
            'unique_freezer_id' => '', // blank → auto (random unit seq)
            'capacity_litres' => '550',
            'freezer_condition' => 'WORKING CONDITION',
            'status' => 'Active',
            'image_left_side' => 'freezer_1_left.jpg',
            'image_right_side' => 'freezer_1_right.jpg',
            'iot_serial' => 'RF-IOT-99210',
            'iot_imei' => '864201048291048',
            'iot_sim_number' => '8991000900123456789',
            'iot_sim_company' => 'Jio',
            'iot_type' => 'Temperature & GPS',
            'iot_owner' => 'ReeferON Own Device',
            'iot_model' => 'RF-T100',
            'image_iot_device' => 'iot_1_device.jpg',
            'notes' => 'Photos REQUIRED · blank lat/lng = city GPS · blank IDs = auto · files in uploads/import/',
        ];

        // Row1: new store + freezer + IoT (unit seq auto-random)
        // Row2: SAME store (reuse outlet after first import) + 2nd freezer + IoT
        // Row3: different store + freezer + IoT
        $rows = [];
        $rows[] = $base;

        $row2 = $base;
        $row2['outlet_id'] = ''; // after import #1, paste generated AMD1-380009-0001 here to link same store
        $row2['image_store_front'] = 'store_1_front.jpg'; // required (or reuse same filename)
        $row2['image_left_side'] = 'freezer_2_left.jpg';
        $row2['image_right_side'] = 'freezer_2_right.jpg';
        $row2['iot_serial'] = 'RF-IOT-99211';
        $row2['iot_imei'] = '';
        $row2['iot_sim_number'] = '8991000900123456790';
        $row2['iot_sim_company'] = 'Airtel';
        $row2['image_iot_device'] = 'iot_2_device.jpg';
        $row2['notes'] = '2nd freezer same store — set outlet_id after row1 · store + freezer photos required';
        $rows[] = $row2;

        $row3 = $base;
        $row3['store_name'] = 'Baskin Robbins - SG Highway Hub';
        $row3['address'] = 'Near SG Highway';
        $row3['pincode'] = '380054';
        $row3['location_code'] = 'AMD1';
        $row3['outlet_id'] = '';
        $row3['image_store_front'] = 'store_2_front.jpg';
        $row3['image_aadhar_card'] = 'store_2_aadhar_front.jpg';
        $row3['image_aadhar_back'] = 'store_2_aadhar_back.jpg';
        $row3['image_left_side'] = 'freezer_3_left.jpg';
        $row3['image_right_side'] = 'freezer_3_right.jpg';
        $row3['iot_serial'] = 'RF-IOT-99212';
        $row3['iot_imei'] = '';
        $row3['iot_sim_number'] = '8991000900123456791';
        $row3['iot_sim_company'] = 'VI';
        $row3['image_iot_device'] = 'iot_3_device.jpg';
        $row3['notes'] = 'New store → auto outlet_id e.g. AMD1-380054-0001';
        $rows[] = $row3;

        return $rows;
    }

    public static function storeColumns(): array {
        return [
            // Filter sequence
            'zone',
            'state',
            'city',
            'pincode',
            'location_type',
            'location_code',
            // Store identity (blank outlet_id → AMD1-380009-0001)
            'outlet_id',
            'store_name',
            'customer_name',
            'distributor_name',
            'address',
            'latitude',
            'longitude',
            // Operator / compliance
            'contact_person',
            'phone_number',
            'email',
            'aadhar_number',
            'fssai_number',
            'gst_number',
            'status',
            // Photos
            'image_store_front',
            'image_aadhar_card',
            'image_aadhar_back',
        ];
    }

    public static function freezerColumns(): array {
        return [
            // Filter / link by location + store
            'zone',
            'state',
            'city',
            'pincode',
            'location_code',
            'outlet_id',
            'store_name',
            // Freezer ID sequence
            'freezer_type',
            'company',
            'model_number',
            'installation_date',
            'unique_freezer_id',
            'capacity_litres',
            'freezer_condition',
            'status',
            // Photos
            'image_left_side',
            'image_right_side',
            'notes',
        ];
    }

    public static function iotColumns(): array {
        return [
            // Filter helpers (optional on IoT sheet)
            'zone',
            'city',
            'outlet_id',
            'store_name',
            'unique_freezer_id',
            'iot_serial',
            'iot_imei',
            'iot_sim_number',
            'iot_sim_company',
            'iot_type',
            'iot_owner',
            'iot_model',
            'image_iot_device',
            'notes',
        ];
    }

    private static function streamCsv(string $filename, array $columns, array $rows): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }

    public static function hubColumnSection(string $col): string {
        static $iot = [
            'iot_serial', 'iot_imei', 'iot_sim_number', 'iot_sim_company',
            'iot_type', 'iot_owner', 'iot_model',
            'iot_battery_percentage', 'iot_power_status', 'iot_last_ping_at',
            'image_iot_device',
        ];
        static $freezer = [
            'freezer_type', 'freezer_type_code', 'company', 'company_code', 'model_number',
            'installation_date', 'install_month_year', 'pincode_last3', 'unit_sequence',
            'unique_freezer_id', 'capacity_litres', 'freezer_condition', 'status',
            'image_left_side', 'image_right_side', 'qr_code_path', 'qr_code_data',
            'freezer_created_at', 'freezer_updated_at',
        ];
        if (in_array($col, $iot, true)) {
            return 'iot';
        }
        if (in_array($col, $freezer, true)) {
            return 'freezer';
        }
        if ($col === 'notes') {
            return 'notes';
        }
        return 'store';
    }

    /**
     * Full export columns — full names + short codes + all store/freezer/IoT fields.
     */
    public static function exportColumns(): array {
        return [
            // STORE
            'zone',
            'state',
            'city',
            'pincode',
            'location_type',
            'location_code',
            'outlet_id',
            'store_name',
            'customer_name',
            'distributor_name',
            'address',
            'latitude',
            'longitude',
            'gps_accuracy',
            'gps_captured_at',
            'contact_person',
            'phone_number',
            'email',
            'aadhar_number',
            'fssai_number',
            'gst_number',
            'store_status',
            'image_store_front',
            'image_aadhar_card',
            'image_aadhar_back',
            'store_created_at',
            'store_updated_at',
            // FREEZER — full name then short code
            'freezer_type',
            'freezer_type_code',
            'company',
            'company_code',
            'model_number',
            'installation_date',
            'install_month_year',
            'pincode_last3',
            'unit_sequence',
            'unique_freezer_id',
            'capacity_litres',
            'freezer_condition',
            'status',
            'image_left_side',
            'image_right_side',
            'qr_code_path',
            'qr_code_data',
            'freezer_created_at',
            'freezer_updated_at',
            // IOT
            'iot_serial',
            'iot_imei',
            'iot_sim_number',
            'iot_sim_company',
            'iot_type',
            'iot_owner',
            'iot_model',
            'iot_battery_percentage',
            'iot_power_status',
            'iot_last_ping_at',
            'image_iot_device',
            'notes',
        ];
    }

    /** Real .xlsx — only header cell fill colors differ (Store / Freezer / IoT). */
    private static function streamXlsx(
        string $filename,
        array $columns,
        array $rows,
        callable $sectionOf,
        string $sheetName = 'Hub Template'
    ): void {
        (new ExcelXlsxWriter($sectionOf))
            ->setSheetName($sheetName)
            ->setColumns($columns)
            ->setRows($rows)
            ->stream($filename);
    }

    /** Combined Store + Freezer + IoT hub template (normal Excel .xlsx). */
    public static function streamTemplate(): void {
        self::streamXlsx(
            'reeferon_hub_store_freezer_iot_template_v3.xlsx',
            self::hubColumns(),
            self::sampleRows(),
            [self::class, 'hubColumnSection']
        );
    }

    /** Same columns as hub Excel, plain CSV for direct import. */
    public static function streamTemplateCsv(): void {
        self::streamCsv(
            'reeferon_hub_store_freezer_iot_template_v3.csv',
            self::hubColumns(),
            self::sampleRows()
        );
    }

    public static function streamStoreTemplate(): void {
        $row = [
            'zone' => 'WEST',
            'state' => 'Gujarat',
            'city' => 'Ahmedabad',
            'pincode' => '380009',
            'location_type' => 'Retail Store',
            'location_code' => 'AMD1',
            'outlet_id' => '', // auto → AMD1-380009-0001
            'store_name' => 'Baskin Robbins - CG Road Hub',
            'customer_name' => 'Baskin Robbins India',
            'distributor_name' => '',
            'address' => 'Shop 12, CG Road',
            'latitude' => '',
            'longitude' => '',
            'contact_person' => 'Store Manager',
            'phone_number' => '9876543210',
            'email' => 'manager@example.com',
            'aadhar_number' => '',
            'fssai_number' => '',
            'gst_number' => '',
            'status' => 'Active',
            'image_store_front' => 'store_cg_road_front.jpg',
            'image_aadhar_card' => 'manager_aadhar_front.jpg',
            'image_aadhar_back' => 'manager_aadhar_back.jpg',
        ];
        self::streamXlsx(
            'reeferon_store_template_v2.xlsx',
            self::storeColumns(),
            [$row],
            static fn () => 'store'
        );
    }

    public static function streamFreezerTemplate(): void {
        $rows = [];
        foreach ([1, 2, 3] as $i) {
            $rows[] = [
                'zone' => 'WEST',
                'state' => 'Gujarat',
                'city' => 'Ahmedabad',
                'pincode' => '380009',
                'location_code' => '',
                'outlet_id' => '',
                'store_name' => 'Baskin Robbins - Sample Hub' . ($i > 1 ? ' ' . $i : ''),
                'freezer_type' => 'Deep Freezer',
                'company' => 'Rockwell',
                'model_number' => 'SFR550DDU',
                'installation_date' => date('Y-m-d'),
                'unique_freezer_id' => '', // blank → auto random unit seq
                'capacity_litres' => '550',
                'freezer_condition' => 'WORKING CONDITION',
                'status' => 'Active',
                'image_left_side' => "freezer_{$i}_left.jpg",
                'image_right_side' => "freezer_{$i}_right.jpg",
                'notes' => 'Filter zone/state/city · blank outlet_id ok if store exists by name match later · photos in uploads/import/',
            ];
        }
        self::streamXlsx(
            'reeferon_freezer_template_v2.xlsx',
            self::freezerColumns(),
            $rows,
            static function (string $col): string {
                if (in_array($col, ['zone', 'state', 'city', 'pincode', 'location_code', 'outlet_id', 'store_name'], true)) {
                    return 'store';
                }
                if ($col === 'notes') {
                    return 'notes';
                }
                return 'freezer';
            }
        );
    }

    public static function streamIotTemplate(): void {
        $row = [
            'zone' => 'WEST',
            'city' => 'Ahmedabad',
            'outlet_id' => '',
            'store_name' => 'Baskin Robbins - Sample Hub',
            'unique_freezer_id' => 'DF-RW-SFR550DDU-AMD1-0326-380009-A7K2M9',
            'iot_serial' => 'RF-IOT-99210',
            'iot_imei' => '864201048291048',
            'iot_sim_number' => '8991000900123456789',
            'iot_sim_company' => 'Jio',
            'iot_type' => 'Temperature & GPS',
            'iot_owner' => 'ReeferON Own Device',
            'iot_model' => 'RF-T100',
            'image_iot_device' => 'iot_AAA001_device.jpg',
            'notes' => 'Link IoT to existing freezer Unique ID',
        ];
        $row2 = [
            'zone' => 'WEST',
            'city' => 'Ahmedabad',
            'outlet_id' => '',
            'store_name' => 'Baskin Robbins - Sample Hub 2',
            'unique_freezer_id' => 'DF-RW-SFR550DDU-AMD1-0326-380009-B8L3N0',
            'iot_serial' => 'RF-IOT-99211',
            'iot_imei' => '',
            'iot_sim_number' => '8991000900123456790',
            'iot_sim_company' => 'Airtel',
            'iot_type' => 'Temperature & GPS',
            'iot_owner' => 'ReeferON Own Device',
            'iot_model' => 'RF-T100',
            'image_iot_device' => 'iot_AAA002_device.jpg',
            'notes' => '',
        ];
        self::streamXlsx(
            'reeferon_iot_template.xlsx',
            self::iotColumns(),
            [$row, $row2],
            static function (string $col): string {
                if (in_array($col, ['iot_serial', 'iot_imei', 'iot_sim_number', 'iot_sim_company', 'iot_type', 'iot_owner', 'iot_model', 'image_iot_device'], true)) {
                    return 'iot';
                }
                if ($col === 'notes') {
                    return 'notes';
                }
                if ($col === 'unique_freezer_id') {
                    return 'freezer';
                }
                return 'store';
            }
        );
    }

    /** Full hub export as .xlsx — all details (full name + short code) + colored headers. */
    public static function streamExport(): void {
        $mappedRows = self::buildExportRows();
        self::streamXlsx(
            'reeferon_hub_export_' . date('Ymd_His') . '.xlsx',
            self::exportColumns(),
            $mappedRows,
            [self::class, 'hubColumnSection'],
            'Hub Export'
        );
    }

    /** Same full export as CSV (no colors). */
    public static function streamExportCsv(): void {
        $mappedRows = self::buildExportRows();
        self::streamCsv(
            'reeferon_hub_export_' . date('Ymd_His') . '.csv',
            self::exportColumns(),
            $mappedRows
        );
    }

    /** @return list<array<string, scalar|null>> */
    private static function buildExportRows(): array {
        $pdo = \App\Config\Database::getConnection();
        $sql = "SELECT f.*,
                       s.store_name, s.outlet_id, s.customer_name, s.distributor_name,
                       s.contact_person, s.phone_number, s.email AS store_email, s.aadhar_number,
                       s.fssai_number, s.gst_number,
                       s.address AS store_address, s.city AS store_city, s.state AS store_state,
                       s.pincode AS store_pincode, s.zone AS store_zone, s.location_type,
                       s.latitude AS store_latitude, s.longitude AS store_longitude,
                       s.gps_accuracy, s.gps_captured_at,
                       s.image_store_front, s.image_aadhar_card, s.image_aadhar_back,
                       s.status AS store_status,
                       s.created_at AS store_created_at, s.updated_at AS store_updated_at,
                       i.device_serial AS iot_serial, i.imei_or_mac AS iot_imei,
                       i.sim_number AS iot_sim_number, i.sim_company AS iot_sim_company,
                       i.device_type AS iot_type, i.device_owner AS iot_owner, i.oem_model AS iot_model,
                       i.battery_percentage AS iot_battery_percentage,
                       i.power_status AS iot_power_status, i.last_ping_at AS iot_last_ping_at
                FROM `freezers` f
                INNER JOIN `stores` s ON f.store_id = s.id
                LEFT JOIN `iot_devices` i ON i.freezer_id = f.id
                ORDER BY f.unit_sequence ASC, f.unique_freezer_id ASC, f.id ASC
                LIMIT 5000";
        $rows = $pdo->query($sql)->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $phone = preg_replace('/\D/', '', (string)($r['phone_number'] ?? ''));
            if (strlen($phone) === 12 && str_starts_with($phone, '91')) {
                $phone = substr($phone, 2);
            }
            $phone = strlen($phone) >= 10 ? substr($phone, -10) : $phone;

            $typeCode = strtoupper(trim((string)($r['freezer_type'] ?? '')));
            $brandCode = strtoupper(trim((string)($r['company_code'] ?? '')));

            $out[] = [
                'zone' => $r['store_zone'] ?? 'WEST',
                'state' => $r['store_state'] ?? '',
                'city' => $r['store_city'] ?? '',
                'pincode' => $r['store_pincode'] ?? '',
                'location_type' => $r['location_type'] ?? 'Retail Store',
                'location_code' => $r['location_code'] ?? '',
                'outlet_id' => $r['outlet_id'] ?? '',
                'store_name' => $r['store_name'] ?? '',
                'customer_name' => $r['customer_name'] ?? '',
                'distributor_name' => $r['distributor_name'] ?? '',
                'address' => $r['store_address'] ?? '',
                'latitude' => $r['store_latitude'] ?? '',
                'longitude' => $r['store_longitude'] ?? '',
                'gps_accuracy' => $r['gps_accuracy'] ?? '',
                'gps_captured_at' => $r['gps_captured_at'] ?? '',
                'contact_person' => $r['contact_person'] ?? '',
                'phone_number' => $phone,
                'email' => $r['store_email'] ?? '',
                'aadhar_number' => $r['aadhar_number'] ?? '',
                'fssai_number' => $r['fssai_number'] ?? '',
                'gst_number' => $r['gst_number'] ?? '',
                'store_status' => $r['store_status'] ?? '',
                'image_store_front' => $r['image_store_front'] ?? '',
                'image_aadhar_card' => $r['image_aadhar_card'] ?? '',
                'image_aadhar_back' => $r['image_aadhar_back'] ?? '',
                'store_created_at' => $r['store_created_at'] ?? '',
                'store_updated_at' => $r['store_updated_at'] ?? '',
                'freezer_type' => MasterData::freezerTypeName($typeCode),
                'freezer_type_code' => $typeCode,
                'company' => MasterData::brandName($brandCode),
                'company_code' => $brandCode,
                'model_number' => $r['model_number'] ?? '',
                'installation_date' => $r['installation_date'] ?? '',
                'install_month_year' => $r['install_month_year'] ?? '',
                'pincode_last3' => $r['pincode_last3'] ?? '',
                'unit_sequence' => $r['unit_sequence'] ?? '',
                'unique_freezer_id' => $r['unique_freezer_id'] ?? '',
                'capacity_litres' => $r['capacity_litres'] ?? '',
                'freezer_condition' => $r['freezer_condition'] ?? '',
                'status' => $r['status'] ?? 'Active',
                'image_left_side' => $r['image_left_side'] ?? '',
                'image_right_side' => $r['image_right_side'] ?? '',
                'qr_code_path' => $r['qr_code_path'] ?? '',
                'qr_code_data' => $r['qr_code_data'] ?? '',
                'freezer_created_at' => $r['created_at'] ?? '',
                'freezer_updated_at' => $r['updated_at'] ?? '',
                'iot_serial' => $r['iot_serial'] ?? '',
                'iot_imei' => $r['iot_imei'] ?? '',
                'iot_sim_number' => $r['iot_sim_number'] ?? '',
                'iot_sim_company' => $r['iot_sim_company'] ?? '',
                'iot_type' => $r['iot_type'] ?? '',
                'iot_owner' => $r['iot_owner'] ?? '',
                'iot_model' => $r['iot_model'] ?? '',
                'iot_battery_percentage' => $r['iot_battery_percentage'] ?? '',
                'iot_power_status' => $r['iot_power_status'] ?? '',
                'iot_last_ping_at' => $r['iot_last_ping_at'] ?? '',
                'image_iot_device' => $r['image_iot_device'] ?? '',
                'notes' => $r['notes'] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Import CSV hub file. Returns summary array.
     */
    public static function importHubCsv(string $tmpPath, int $createdBy): array {
        $fh = fopen($tmpPath, 'r');
        if (!$fh) {
            throw new Exception('Unable to read uploaded CSV file.');
        }

        // Skip BOM
        $bom = fread($fh, 3);
        if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            rewind($fh);
        }

        $header = fgetcsv($fh);
        if (!$header || count($header) < 5) {
            fclose($fh);
            throw new Exception('CSV header row is missing or invalid.');
        }

        $header = array_map(function ($h) {
            return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)));
        }, $header);

        $required = ['store_name', 'address', 'city', 'pincode', 'freezer_type', 'model_number'];
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                fclose($fh);
                throw new Exception("CSV is missing required column: {$col}");
            }
        }
        if (!in_array('company', $header, true) && !in_array('company_code', $header, true)) {
            fclose($fh);
            throw new Exception('CSV is missing required column: company (full name e.g. Rockwell)');
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;

        while (($data = fgetcsv($fh)) !== false) {
            $rowNum++;
            if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? trim((string)$data[$i]) : '';
            }

            try {
                self::importOneRow($row, $createdBy);
                $created++;
            } catch (Exception $e) {
                $skipped++;
                if (count($errors) < 25) {
                    $errors[] = "Row {$rowNum}: " . $e->getMessage();
                }
            }
        }

        fclose($fh);

        return [
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
            'rows_read' => max(0, $rowNum - 1),
        ];
    }

    private static function importOneRow(array $row, int $createdBy): void {
        $storeName = $row['store_name'] ?? '';
        $address = $row['address'] ?? '';
        $city = $row['city'] ?? '';
        $pincode = preg_replace('/\D/', '', $row['pincode'] ?? '');
        $freezerTypeRaw = trim($row['freezer_type'] ?? '');
        $companyRaw = trim($row['company'] ?? ($row['company_code'] ?? ($row['brand'] ?? '')));
        $modelNumber = trim($row['model_number'] ?? '');
        $locationCode = MasterData::resolveLocationCodeForCity($city, $row['location_code'] ?? '');

        if ($storeName === '' || $address === '' || $city === '') {
            throw new Exception('store_name, address and city are required.');
        }
        if (!preg_match('/^\d{6}$/', $pincode)) {
            throw new Exception('pincode must be 6 digits.');
        }
        if ($freezerTypeRaw === '' || $companyRaw === '' || $modelNumber === '') {
            throw new Exception('freezer_type, company and model_number are required.');
        }

        // Full names in CSV → short codes for Unique ID (Deep Freezer→DF, Rockwell→RW)
        $freezerType = MasterData::resolveFreezerTypeCode($freezerTypeRaw);
        $companyCode = MasterData::resolveBrandCode($companyRaw);
        if (!$freezerType) {
            throw new Exception("Unknown freezer_type '{$freezerTypeRaw}'. Use full name e.g. Deep Freezer.");
        }
        if (!$companyCode) {
            throw new Exception("Unknown company '{$companyRaw}'. Use full name e.g. Rockwell.");
        }

        $outletId = trim($row['outlet_id'] ?? '');
        $existingStore = $outletId !== '' ? Store::findByOutletId($outletId) : null;

        $installDate = trim($row['installation_date'] ?? '');
        if ($installDate === '') {
            $installDate = date('Y-m-d');
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $installDate)) {
            [$dd, $mm, $yyyy] = explode('/', $installDate);
            $installDate = "{$yyyy}-{$mm}-{$dd}";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $installDate)) {
            $ts = strtotime($installDate);
            if ($ts === false) {
                throw new Exception('Invalid installation_date. Use YYYY-MM-DD.');
            }
            $installDate = date('Y-m-d', $ts);
        }

        $uniqueId = strtoupper(trim($row['unique_freezer_id'] ?? ''));
        if ($uniqueId !== '' && FreezerIdGenerator::isDuplicate($uniqueId)) {
            throw new Exception("Freezer ID '{$uniqueId}' already exists.");
        }

        // Photos REQUIRED (same as profile form): store front + freezer left/right
        $storeFrontName = trim((string)($row['image_store_front'] ?? ''));
        $leftName = trim((string)($row['image_left_side'] ?? ''));
        $rightName = trim((string)($row['image_right_side'] ?? ''));
        $iotSerialCheck = trim((string)($row['iot_serial'] ?? ''));
        $iotImgName = trim((string)($row['image_iot_device'] ?? ''));

        if ($leftName === '' || $rightName === '') {
            throw new Exception('image_left_side and image_right_side are required (filenames in uploads/import/).');
        }
        if ($storeFrontName === '' && !$existingStore) {
            throw new Exception('image_store_front is required for new stores (filename in uploads/import/).');
        }
        if ($storeFrontName === '' && $existingStore && empty($existingStore['image_store_front'])) {
            throw new Exception('image_store_front is required (existing store has no store photo yet).');
        }
        if ($iotSerialCheck !== '' && $iotImgName === '') {
            throw new Exception('image_iot_device is required when iot_serial is provided.');
        }

        $imgStoreFront = null;
        $imgLeft = null;
        $imgRight = null;
        $imgIot = null;
        $imgAadhar = null;
        $imgAadharBack = null;
        try {
            if ($storeFrontName !== '') {
                $imgStoreFront = FileUploadService::promoteImportImage($storeFrontName, 'stores', 'store_front');
            } elseif ($existingStore) {
                $imgStoreFront = $existingStore['image_store_front'] ?? null;
            }
            $imgAadhar = FileUploadService::promoteImportImage($row['image_aadhar_card'] ?? '', 'stores', 'aadhar_front');
            $imgAadharBack = FileUploadService::promoteImportImage($row['image_aadhar_back'] ?? '', 'stores', 'aadhar_back');
            $imgLeft = FileUploadService::promoteImportImage($leftName, 'freezers', 'frz_left');
            $imgRight = FileUploadService::promoteImportImage($rightName, 'freezers', 'frz_right');
            if ($iotImgName !== '') {
                $imgIot = FileUploadService::promoteImportImage($iotImgName, 'iot', 'iot_dev');
            }
        } catch (\Throwable $e) {
            throw new Exception($e->getMessage());
        }

        // Excel users often skip lat/lng — fill city-center GPS so profile map still shows.
        // Exact pin: capture later via Store Front GPS on profile edit.
        $latIn = trim((string)($row['latitude'] ?? ''));
        $lngIn = trim((string)($row['longitude'] ?? ''));
        $lat = $latIn !== '' ? (float)$latIn : null;
        $lng = $lngIn !== '' ? (float)$lngIn : null;
        $gpsAccuracy = null;
        if ($lat === null || $lng === null) {
            $cityGps = MasterData::resolveCityGps($city);
            if ($cityGps) {
                $lat = $cityGps['lat'];
                $lng = $cityGps['lng'];
                $gpsAccuracy = $cityGps['accuracy']; // 5000m = city approx
            }
        }

        $storePayload = [
            'outlet_id' => $outletId !== '' ? $outletId : Store::generateOutletId([
                'city' => $city,
                'pincode' => $pincode,
                'location_code' => $row['location_code'] ?? '',
            ]),
            'store_name' => $storeName,
            'customer_name' => $row['customer_name'] ?? 'Baskin Robbins',
            'distributor_name' => $row['distributor_name'] ?? '',
            'contact_person' => $row['contact_person'] ?? '',
            'phone_number' => $row['phone_number'] ?? '',
            'email' => $row['email'] ?? '',
            'aadhar_number' => $row['aadhar_number'] ?? '',
            'fssai_number' => $row['fssai_number'] ?? '',
            'gst_number' => $row['gst_number'] ?? '',
            'address' => $address,
            'city' => $city,
            'state' => trim($row['state'] ?? '') !== '' ? trim($row['state']) : 'Unknown',
            'pincode' => $pincode,
            'zone' => strtoupper(trim($row['zone'] ?? 'WEST')) ?: 'WEST',
            'location_type' => $row['location_type'] ?? 'Retail Store',
            'latitude' => $lat,
            'longitude' => $lng,
            'gps_accuracy' => $gpsAccuracy,
            'image_store_front' => $imgStoreFront,
            'image_aadhar_card' => $imgAadhar,
            'image_aadhar_back' => $imgAadharBack,
            'status' => 'Active',
            'created_by' => $createdBy,
        ];

        if ($existingStore) {
            $storeId = (int)$existingStore['id'];
            Store::update($storeId, array_merge($storePayload, [
                'outlet_id' => $existingStore['outlet_id'],
            ]));
        } else {
            // Ensure unique outlet if blank generator collided
            if ($outletId !== '' && Store::findByOutletId($outletId)) {
                throw new Exception("Outlet ID '{$outletId}' already exists.");
            }
            $storeId = Store::create($storePayload);
        }

        Freezer::create([
            'unique_freezer_id' => $uniqueId !== '' ? $uniqueId : null,
            'store_id' => $storeId,
            'freezer_type' => $freezerType,
            'company_code' => $companyCode,
            'model_number' => $modelNumber,
            'capacity_litres' => ($row['capacity_litres'] ?? '') !== '' ? (int)$row['capacity_litres'] : null,
            'freezer_condition' => $row['freezer_condition'] ?? 'WORKING CONDITION',
            'location_code' => $locationCode,
            'pincode' => $pincode,
            'install_month_year' => date('my', strtotime($installDate)),
            'unit_sequence' => null, // always auto-random on import
            'installation_date' => $installDate,
            'image_left_side' => $imgLeft,
            'image_right_side' => $imgRight,
            'image_iot_device' => $imgIot,
            'iot_serial' => trim($row['iot_serial'] ?? ''),
            'iot_imei' => trim($row['iot_imei'] ?? ''),
            'iot_sim_number' => trim($row['iot_sim_number'] ?? ''),
            'iot_sim_company' => trim($row['iot_sim_company'] ?? ''),
            'iot_type' => trim($row['iot_type'] ?? '') !== '' ? trim($row['iot_type']) : 'Temperature & GPS',
            'iot_owner' => trim($row['iot_owner'] ?? '') !== '' ? trim($row['iot_owner']) : 'ReeferON Own Device',
            'iot_model' => trim($row['iot_model'] ?? '') !== '' ? trim($row['iot_model']) : 'RF-T100',
            'status' => ($row['status'] ?? '') !== '' ? $row['status'] : 'Active',
            'notes' => $row['notes'] ?? '',
            'created_by' => $createdBy,
        ]);
    }
}
