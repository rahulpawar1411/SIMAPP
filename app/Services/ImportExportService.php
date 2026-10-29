<?php
namespace App\Services;

use App\Models\Store;
use App\Models\Freezer;
use Exception;

class ImportExportService {

    /** Canonical CSV columns for Store + Freezer hub import/export */
    public static function hubColumns(): array {
        return [
            'store_name',
            'outlet_id',
            'customer_name',
            'distributor_name',
            'contact_person',
            'phone_number',
            'email',
            'aadhar_number',
            'fssai_number',
            'gst_number',
            'address',
            'city',
            'state',
            'pincode',
            'zone',
            'location_type',
            'latitude',
            'longitude',
            'freezer_type',
            'company_code',
            'model_number',
            'capacity_litres',
            'freezer_condition',
            'location_code',
            'installation_date',
            'unit_sequence',
            'unique_freezer_id',
            'status',
            'notes',
        ];
    }

    public static function sampleRow(): array {
        return [
            'store_name' => 'Baskin Robbins - Sample Hub',
            'outlet_id' => '',
            'customer_name' => 'Baskin Robbins India',
            'distributor_name' => '',
            'contact_person' => 'Store Manager',
            'phone_number' => '9876543210',
            'email' => 'manager@example.com',
            'aadhar_number' => '',
            'fssai_number' => '',
            'gst_number' => '',
            'address' => 'Shop 12, CG Road',
            'city' => 'Ahmedabad',
            'state' => 'Gujarat',
            'pincode' => '380009',
            'zone' => 'WEST',
            'location_type' => 'Retail Store',
            'latitude' => '23.033800',
            'longitude' => '72.557000',
            'freezer_type' => 'DF',
            'company_code' => 'RW',
            'model_number' => 'SFR550DDU',
            'capacity_litres' => '550',
            'freezer_condition' => 'WORKING CONDITION',
            'location_code' => 'AMD1',
            'installation_date' => date('Y-m-d'),
            'unit_sequence' => '',
            'unique_freezer_id' => '',
            'status' => 'Active',
            'notes' => 'Imported via hub CSV',
        ];
    }

    public static function streamTemplate(): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=reeferon_store_freezer_template.csv');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
        fputcsv($out, self::hubColumns());
        fputcsv($out, array_values(self::sampleRow()));
        fclose($out);
    }

    public static function streamExport(): void {
        $pdo = \App\Config\Database::getConnection();
        $sql = "SELECT f.*,
                       s.store_name, s.outlet_id, s.customer_name, s.distributor_name,
                       s.contact_person, s.phone_number, s.email as store_email, s.aadhar_number,
                       s.fssai_number, s.gst_number,
                       s.address as store_address, s.city as store_city, s.state as store_state,
                       s.pincode as store_pincode, s.zone as store_zone, s.location_type,
                       s.latitude as store_latitude, s.longitude as store_longitude
                FROM `freezers` f
                INNER JOIN `stores` s ON f.store_id = s.id
                ORDER BY f.id DESC
                LIMIT 5000";
        $rows = $pdo->query($sql)->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=reeferon_store_freezer_export_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, self::hubColumns());

        foreach ($rows as $r) {
            $phone = preg_replace('/\D/', '', (string)($r['phone_number'] ?? ''));
            if (strlen($phone) === 12 && str_starts_with($phone, '91')) {
                $phone = substr($phone, 2);
            }
            $phone = strlen($phone) >= 10 ? substr($phone, -10) : $phone;

            fputcsv($out, [
                $r['store_name'] ?? '',
                $r['outlet_id'] ?? '',
                $r['customer_name'] ?? '',
                $r['distributor_name'] ?? '',
                $r['contact_person'] ?? '',
                $phone,
                $r['store_email'] ?? '',
                $r['aadhar_number'] ?? '',
                $r['fssai_number'] ?? '',
                $r['gst_number'] ?? '',
                $r['store_address'] ?? '',
                $r['store_city'] ?? '',
                $r['store_state'] ?? '',
                $r['store_pincode'] ?? '',
                $r['store_zone'] ?? 'WEST',
                $r['location_type'] ?? 'Retail Store',
                $r['store_latitude'] ?? '',
                $r['store_longitude'] ?? '',
                $r['freezer_type'] ?? '',
                $r['company_code'] ?? '',
                $r['model_number'] ?? '',
                $r['capacity_litres'] ?? '',
                $r['freezer_condition'] ?? '',
                $r['location_code'] ?? '',
                $r['installation_date'] ?? '',
                $r['unit_sequence'] ?? '',
                $r['unique_freezer_id'] ?? '',
                $r['status'] ?? 'Active',
                $r['notes'] ?? '',
            ]);
        }
        fclose($out);
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

        $required = ['store_name', 'address', 'city', 'pincode', 'freezer_type', 'company_code', 'model_number', 'location_code'];
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                fclose($fh);
                throw new Exception("CSV is missing required column: {$col}");
            }
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
        $freezerType = strtoupper(trim($row['freezer_type'] ?? ''));
        $companyCode = strtoupper(trim($row['company_code'] ?? ''));
        $modelNumber = trim($row['model_number'] ?? '');
        $locationCode = strtoupper(trim($row['location_code'] ?? ''));

        if ($storeName === '' || $address === '' || $city === '') {
            throw new Exception('store_name, address and city are required.');
        }
        if (!preg_match('/^\d{6}$/', $pincode)) {
            throw new Exception('pincode must be 6 digits.');
        }
        if ($freezerType === '' || $companyCode === '' || $modelNumber === '' || $locationCode === '') {
            throw new Exception('freezer_type, company_code, model_number and location_code are required.');
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

        $storePayload = [
            'outlet_id' => $outletId !== '' ? $outletId : Store::generateOutletId($city),
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
            'latitude' => ($row['latitude'] ?? '') !== '' ? (float)$row['latitude'] : null,
            'longitude' => ($row['longitude'] ?? '') !== '' ? (float)$row['longitude'] : null,
            'gps_accuracy' => null,
            'image_store_front' => null,
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
            'unit_sequence' => ($row['unit_sequence'] ?? '') !== '' ? strtoupper(trim($row['unit_sequence'])) : null,
            'installation_date' => $installDate,
            'status' => ($row['status'] ?? '') !== '' ? $row['status'] : 'Active',
            'notes' => $row['notes'] ?? '',
            'created_by' => $createdBy,
        ]);
    }
}
