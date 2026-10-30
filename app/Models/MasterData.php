<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class MasterData {

    public static function getFreezerTypes(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM `freezer_types` WHERE `is_active` = 1 ORDER BY `code` ASC");
        return $stmt->fetchAll();
    }

    public static function getBrands(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM `brands` WHERE `is_active` = 1 ORDER BY `name` ASC");
        return $stmt->fetchAll();
    }

    /** Resolve CSV/UI value to freezer type code. Accepts code (DF) or full name (Deep Freezer). */
    public static function resolveFreezerTypeCode(string $value): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $upper = strtoupper($value);
        foreach (self::getFreezerTypes() as $ft) {
            if (strtoupper((string)$ft['code']) === $upper) {
                return strtoupper((string)$ft['code']);
            }
            if (strcasecmp((string)$ft['name'], $value) === 0) {
                return strtoupper((string)$ft['code']);
            }
        }
        // Loose contains match on name
        foreach (self::getFreezerTypes() as $ft) {
            if (stripos((string)$ft['name'], $value) !== false || stripos($value, (string)$ft['name']) !== false) {
                return strtoupper((string)$ft['code']);
            }
        }
        return null;
    }

    /** Resolve CSV/UI value to brand code. Accepts code (RW) or full name (Rockwell). */
    public static function resolveBrandCode(string $value): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $upper = strtoupper($value);
        foreach (self::getBrands() as $b) {
            if (strtoupper((string)$b['code']) === $upper) {
                return strtoupper((string)$b['code']);
            }
            if (strcasecmp((string)$b['name'], $value) === 0) {
                return strtoupper((string)$b['code']);
            }
        }
        foreach (self::getBrands() as $b) {
            if (stripos((string)$b['name'], $value) !== false || stripos($value, (string)$b['name']) !== false) {
                return strtoupper((string)$b['code']);
            }
        }
        return null;
    }

    public static function freezerTypeName(string $code): string {
        foreach (self::getFreezerTypes() as $ft) {
            if (strcasecmp((string)$ft['code'], $code) === 0) {
                return (string)$ft['name'];
            }
        }
        return $code;
    }

    public static function brandName(string $code): string {
        foreach (self::getBrands() as $b) {
            if (strcasecmp((string)$b['code'], $code) === 0) {
                return (string)$b['name'];
            }
        }
        return $code;
    }

    /**
     * City → 3-letter location prefix (AMD, BPL, MUM, PUN…).
     * Location codes are PREFIX + zone number: AMD1, AMD2, AMD3.
     */
    public static function getCityLocationPrefixes(): array {
        return [
            'Ahmedabad' => 'AMD',
            'Bhopal' => 'BPL',
            'Mumbai' => 'MUM',
            'Pune' => 'PUN',
            'Surat' => 'SUR',
            'Vadodara' => 'BRD',
            'Rajkot' => 'RAJ',
            'Indore' => 'IND',
            'Nagpur' => 'NAG',
            'Thane' => 'THA',
            'Nashik' => 'NAS',
            'Navi Mumbai' => 'NVM',
            'New Delhi' => 'DEL',
            'South Delhi' => 'DEL',
            'Bengaluru' => 'BLR',
            'Hyderabad' => 'HYD',
            'Chennai' => 'CHN',
            'Kolkata' => 'KOL',
            'Jaipur' => 'JAI',
            'Lucknow' => 'LKO',
            'Chandigarh' => 'CHD',
            'Guwahati' => 'GAU',
            'Patna' => 'PAT',
            'Bhubaneswar' => 'BBI',
        ];
    }

    /**
     * City-center GPS (approx) — used when Excel import leaves lat/lng blank
     * so profile map still shows. Exact pin = Store Front GPS capture later.
     *
     * @return array<string, array{lat: float, lng: float}>
     */
    public static function getCityGpsCenters(): array {
        return [
            'Ahmedabad' => ['lat' => 23.0338, 'lng' => 72.5570],
            'Surat' => ['lat' => 21.1702, 'lng' => 72.8311],
            'Vadodara' => ['lat' => 22.3072, 'lng' => 73.1812],
            'Rajkot' => ['lat' => 22.3039, 'lng' => 70.8022],
            'Gandhinagar' => ['lat' => 23.2156, 'lng' => 72.6369],
            'Bhavnagar' => ['lat' => 21.7645, 'lng' => 72.1519],
            'Mumbai' => ['lat' => 18.9986, 'lng' => 72.8258],
            'Pune' => ['lat' => 18.5204, 'lng' => 73.8567],
            'Nagpur' => ['lat' => 21.1458, 'lng' => 79.0882],
            'Thane' => ['lat' => 19.2183, 'lng' => 72.9781],
            'Nashik' => ['lat' => 19.9975, 'lng' => 73.7898],
            'Aurangabad' => ['lat' => 19.8762, 'lng' => 75.3433],
            'Navi Mumbai' => ['lat' => 19.0330, 'lng' => 73.0297],
            'Bhopal' => ['lat' => 23.2599, 'lng' => 77.4126],
            'Indore' => ['lat' => 22.7196, 'lng' => 75.8577],
            'Gwalior' => ['lat' => 26.2183, 'lng' => 78.1828],
            'Jabalpur' => ['lat' => 23.1815, 'lng' => 79.9864],
            'Ujjain' => ['lat' => 23.1765, 'lng' => 75.7885],
            'Raipur' => ['lat' => 21.2514, 'lng' => 81.6296],
            'New Delhi' => ['lat' => 28.6139, 'lng' => 77.2090],
            'South Delhi' => ['lat' => 28.5355, 'lng' => 77.2100],
            'Noida' => ['lat' => 28.5355, 'lng' => 77.3910],
            'Gurugram' => ['lat' => 28.4595, 'lng' => 77.0266],
            'Jaipur' => ['lat' => 26.9124, 'lng' => 75.7873],
            'Lucknow' => ['lat' => 26.8467, 'lng' => 80.9462],
            'Kanpur' => ['lat' => 26.4499, 'lng' => 80.3319],
            'Chandigarh' => ['lat' => 30.7333, 'lng' => 76.7794],
            'Bengaluru' => ['lat' => 12.9716, 'lng' => 77.5946],
            'Hyderabad' => ['lat' => 17.3850, 'lng' => 78.4867],
            'Chennai' => ['lat' => 13.0827, 'lng' => 80.2707],
            'Kolkata' => ['lat' => 22.5726, 'lng' => 88.3639],
            'Patna' => ['lat' => 25.5941, 'lng' => 85.1376],
            'Bhubaneswar' => ['lat' => 20.2961, 'lng' => 85.8245],
            'Guwahati' => ['lat' => 26.1445, 'lng' => 91.7362],
            'Panaji' => ['lat' => 15.4909, 'lng' => 73.8278],
        ];
    }

    /**
     * Resolve approx GPS for a city name. Returns null if unknown.
     *
     * @return array{lat: float, lng: float, accuracy: float}|null
     */
    public static function resolveCityGps(string $city): ?array {
        $city = trim($city);
        if ($city === '') {
            return null;
        }
        $map = self::getCityGpsCenters();
        foreach ($map as $name => $gps) {
            if (strcasecmp($name, $city) === 0) {
                return ['lat' => $gps['lat'], 'lng' => $gps['lng'], 'accuracy' => 5000.0];
            }
        }
        $needle = strtolower($city);
        foreach ($map as $name => $gps) {
            if (str_contains($needle, strtolower($name)) || str_contains(strtolower($name), $needle)) {
                return ['lat' => $gps['lat'], 'lng' => $gps['lng'], 'accuracy' => 5000.0];
            }
        }
        return null;
    }

    /** Build AMD1 / AMD2 / AMD3 style codes for a city prefix. */
    public static function locationCodesForPrefix(string $prefix, int $zones = 3): array {
        $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', $prefix));
        if ($prefix === '') {
            $prefix = 'LOC';
        }
        $prefix = substr($prefix, 0, 3);
        $codes = [];
        for ($i = 1; $i <= max(1, $zones); $i++) {
            $codes[] = $prefix . $i;
        }
        return $codes;
    }

    /** Resolve 3-letter prefix for a city name (master map or first 3 letters). */
    public static function prefixForCity(string $city): string {
        $city = trim($city);
        if ($city === '') {
            return 'LOC';
        }
        $map = self::getCityLocationPrefixes();
        foreach ($map as $name => $prefix) {
            if (strcasecmp($name, $city) === 0) {
                return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $prefix), 0, 3));
            }
        }
        // Partial match (e.g. "Ahmedabad East")
        $needle = strtolower($city);
        foreach ($map as $name => $prefix) {
            if (str_contains($needle, strtolower($name)) || str_contains(strtolower($name), $needle)) {
                return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $prefix), 0, 3));
            }
        }
        $fallback = strtoupper(preg_replace('/[^A-Za-z]/', '', $city));
        return substr($fallback !== '' ? $fallback : 'LOC', 0, 3);
    }

    /**
     * Auto location code from city (e.g. Ahmedabad → AMD1).
     * If $preferred is a valid PREFIX1/2/3 for that city, keep it.
     */
    public static function resolveLocationCodeForCity(string $city, ?string $preferred = null): string {
        $prefix = self::prefixForCity($city);
        $codes = self::locationCodesForPrefix($prefix, 3);
        $preferred = strtoupper(trim((string)$preferred));
        if ($preferred !== '' && in_array($preferred, $codes, true)) {
            return $preferred;
        }
        // Prefer PREFIX1 when blank / unknown
        return $codes[0] ?? ($prefix . '1');
    }

    public static function getCommonLocationCodes(): array {
        $out = [];
        foreach (self::getCityLocationPrefixes() as $city => $prefix) {
            foreach (self::locationCodesForPrefix($prefix, 3) as $code) {
                $out[] = [
                    'code' => $code,
                    'city' => $city,
                    'name' => $code,
                ];
            }
        }
        return $out;
    }

    public static function getCustomerList(): array {
        return [
            'Baskin Robbins India',
            'The Viral Fever (TVF)',
            'Havmor Ice Cream',
            'Amul (GCMMF)',
            'Vadilal Industries',
            'Kwality Walls (HUL)',
            'Creambell Ice Creams',
            'Mother Dairy',
            'Commercial Supermarket Partner'
        ];
    }

    public static function getConditions(): array {
        return [
            'WORKING CONDITION',
            'BRAND NEW INSTALLATION',
            'NEEDS ROUTINE MAINTENANCE',
            'UNDER REPAIR',
            'TEMPERATURE ALERT'
        ];
    }

    /**
     * Comprehensive Hierarchical Geographical Master:
     * Zone -> State -> Cities (with standard Location Code, default PIN, and coordinates)
     */
    public static function getGeoHierarchy(): array {
        return [
            'CENTRAL' => [
                'Madhya Pradesh' => [
                    ['city' => 'Bhopal', 'code' => 'BPL1', 'prefix' => 'BPL', 'pin' => '462001', 'lat' => 23.2599, 'lng' => 77.4126],
                    ['city' => 'Indore', 'code' => 'IND1', 'pin' => '452001', 'lat' => 22.7196, 'lng' => 75.8577],
                    ['city' => 'Gwalior', 'code' => 'GWL1', 'pin' => '474001', 'lat' => 26.2183, 'lng' => 78.1828],
                    ['city' => 'Jabalpur', 'code' => 'JAB1', 'pin' => '482001', 'lat' => 23.1815, 'lng' => 79.9864],
                    ['city' => 'Ujjain', 'code' => 'UJJ1', 'pin' => '456001', 'lat' => 23.1765, 'lng' => 75.7885],
                    ['city' => 'Sagar', 'code' => 'SAG1', 'pin' => '470001', 'lat' => 23.8388, 'lng' => 78.7378],
                    ['city' => 'Ratlam', 'code' => 'RAT1', 'pin' => '457001', 'lat' => 23.3315, 'lng' => 75.0367],
                    ['city' => 'Rewa', 'code' => 'REW1', 'pin' => '486001', 'lat' => 24.5362, 'lng' => 81.3037],
                ],
                'Chhattisgarh' => [
                    ['city' => 'Raipur', 'code' => 'RAI1', 'pin' => '492001', 'lat' => 21.2514, 'lng' => 81.6296],
                    ['city' => 'Bilaspur', 'code' => 'BIL1', 'pin' => '495001', 'lat' => 22.0797, 'lng' => 82.1409],
                    ['city' => 'Durg-Bhilai', 'code' => 'DUR1', 'pin' => '490006', 'lat' => 21.2167, 'lng' => 81.4333],
                    ['city' => 'Korba', 'code' => 'KRB1', 'pin' => '495677', 'lat' => 22.3595, 'lng' => 82.7501],
                ],
            ],
            'WEST' => [
                'Gujarat' => [
                    ['city' => 'Ahmedabad', 'code' => 'AMD1', 'prefix' => 'AMD', 'pin' => '380058', 'lat' => 23.0338, 'lng' => 72.5570],
                    ['city' => 'Surat', 'code' => 'SUR1', 'prefix' => 'SUR', 'pin' => '395001', 'lat' => 21.1702, 'lng' => 72.8311],
                    ['city' => 'Vadodara', 'code' => 'BRD1', 'prefix' => 'BRD', 'pin' => '390001', 'lat' => 22.3072, 'lng' => 73.1812],
                    ['city' => 'Rajkot', 'code' => 'RAJ1', 'prefix' => 'RAJ', 'pin' => '360001', 'lat' => 22.3039, 'lng' => 70.8022],
                    ['city' => 'Gandhinagar', 'code' => 'GND1', 'prefix' => 'GND', 'pin' => '382010', 'lat' => 23.2156, 'lng' => 72.6369],
                    ['city' => 'Bhavnagar', 'code' => 'BHV1', 'prefix' => 'BHV', 'pin' => '364001', 'lat' => 21.7645, 'lng' => 72.1519],
                    ['city' => 'Jamnagar', 'code' => 'JAM1', 'prefix' => 'JAM', 'pin' => '361001', 'lat' => 22.4707, 'lng' => 70.0577],
                ],
                'Maharashtra' => [
                    ['city' => 'Mumbai', 'code' => 'MUM1', 'prefix' => 'MUM', 'pin' => '400018', 'lat' => 18.9986, 'lng' => 72.8258],
                    ['city' => 'Pune', 'code' => 'PUN1', 'prefix' => 'PUN', 'pin' => '411001', 'lat' => 18.5204, 'lng' => 73.8567],
                    ['city' => 'Nagpur', 'code' => 'NAG1', 'prefix' => 'NAG', 'pin' => '440001', 'lat' => 21.1458, 'lng' => 79.0882],
                    ['city' => 'Thane', 'code' => 'THA1', 'prefix' => 'THA', 'pin' => '400601', 'lat' => 19.2183, 'lng' => 72.9781],
                    ['city' => 'Nashik', 'code' => 'NAS1', 'prefix' => 'NAS', 'pin' => '422001', 'lat' => 19.9975, 'lng' => 73.7898],
                    ['city' => 'Aurangabad', 'code' => 'AUR1', 'prefix' => 'AUR', 'pin' => '431001', 'lat' => 19.8762, 'lng' => 75.3433],
                    ['city' => 'Navi Mumbai', 'code' => 'NVM1', 'prefix' => 'NVM', 'pin' => '400703', 'lat' => 19.0330, 'lng' => 73.0297],
                    ['city' => 'Kolhapur', 'code' => 'KLP1', 'prefix' => 'KLP', 'pin' => '416001', 'lat' => 16.7050, 'lng' => 74.2433],
                ],
                'Goa' => [
                    ['city' => 'Panaji', 'code' => 'GOA1', 'pin' => '403001', 'lat' => 15.4909, 'lng' => 73.8278],
                    ['city' => 'Margao', 'code' => 'GOA2', 'pin' => '403601', 'lat' => 15.2832, 'lng' => 73.9862],
                ],
            ],
            'NORTH' => [
                'Delhi NCR' => [
                    ['city' => 'New Delhi', 'code' => 'DEL1', 'pin' => '110001', 'lat' => 28.6139, 'lng' => 77.2090],
                    ['city' => 'South Delhi', 'code' => 'DEL2', 'pin' => '110017', 'lat' => 28.5355, 'lng' => 77.2100],
                    ['city' => 'Noida', 'code' => 'NOI1', 'pin' => '201301', 'lat' => 28.5355, 'lng' => 77.3910],
                    ['city' => 'Gurugram', 'code' => 'GGN1', 'pin' => '122001', 'lat' => 28.4595, 'lng' => 77.0266],
                ],
                'Rajasthan' => [
                    ['city' => 'Jaipur', 'code' => 'JAI1', 'pin' => '302001', 'lat' => 26.9124, 'lng' => 75.7873],
                    ['city' => 'Jodhpur', 'code' => 'JOD1', 'pin' => '342001', 'lat' => 26.2389, 'lng' => 73.0243],
                    ['city' => 'Udaipur', 'code' => 'UDA1', 'pin' => '313001', 'lat' => 24.5854, 'lng' => 73.7125],
                    ['city' => 'Kota', 'code' => 'KOT1', 'pin' => '324001', 'lat' => 25.2138, 'lng' => 75.8648],
                ],
                'Uttar Pradesh' => [
                    ['city' => 'Lucknow', 'code' => 'LKO1', 'pin' => '226001', 'lat' => 26.8467, 'lng' => 80.9462],
                    ['city' => 'Kanpur', 'code' => 'KNP1', 'pin' => '208001', 'lat' => 26.4499, 'lng' => 80.3319],
                    ['city' => 'Varanasi', 'code' => 'VAR1', 'pin' => '221001', 'lat' => 25.3176, 'lng' => 82.9739],
                    ['city' => 'Agra', 'code' => 'AGR1', 'pin' => '282001', 'lat' => 27.1767, 'lng' => 78.0081],
                    ['city' => 'Prayagraj', 'code' => 'ALL1', 'pin' => '211001', 'lat' => 25.4358, 'lng' => 81.8463],
                ],
                'Punjab' => [
                    ['city' => 'Chandigarh', 'code' => 'CHD1', 'pin' => '160001', 'lat' => 30.7333, 'lng' => 76.7794],
                    ['city' => 'Ludhiana', 'code' => 'LDH1', 'pin' => '141001', 'lat' => 30.9010, 'lng' => 75.8573],
                    ['city' => 'Amritsar', 'code' => 'ASR1', 'pin' => '143001', 'lat' => 31.6340, 'lng' => 74.8723],
                ],
                'Haryana' => [
                    ['city' => 'Faridabad', 'code' => 'FBD1', 'pin' => '121001', 'lat' => 28.4089, 'lng' => 77.3178],
                    ['city' => 'Panipat', 'code' => 'PAN1', 'pin' => '132103', 'lat' => 29.3909, 'lng' => 76.9635],
                ],
            ],
            'SOUTH' => [
                'Karnataka' => [
                    ['city' => 'Bengaluru', 'code' => 'BLR1', 'pin' => '560001', 'lat' => 12.9716, 'lng' => 77.5946],
                    ['city' => 'Mysuru', 'code' => 'MYS1', 'pin' => '570001', 'lat' => 12.2958, 'lng' => 76.6394],
                    ['city' => 'Hubballi', 'code' => 'HUB1', 'pin' => '580020', 'lat' => 15.3647, 'lng' => 75.1240],
                    ['city' => 'Mangaluru', 'code' => 'MNG1', 'pin' => '575001', 'lat' => 12.9141, 'lng' => 74.8560],
                ],
                'Telangana' => [
                    ['city' => 'Hyderabad', 'code' => 'HYD1', 'pin' => '500001', 'lat' => 17.3850, 'lng' => 78.4867],
                    ['city' => 'Warangal', 'code' => 'WAR1', 'pin' => '506002', 'lat' => 17.9689, 'lng' => 79.5941],
                ],
                'Tamil Nadu' => [
                    ['city' => 'Chennai', 'code' => 'CHN1', 'pin' => '600001', 'lat' => 13.0827, 'lng' => 80.2707],
                    ['city' => 'Coimbatore', 'code' => 'CBE1', 'pin' => '641001', 'lat' => 11.0168, 'lng' => 76.9558],
                    ['city' => 'Madurai', 'code' => 'MDU1', 'pin' => '625001', 'lat' => 9.9252, 'lng' => 78.1198],
                ],
                'Kerala' => [
                    ['city' => 'Kochi', 'code' => 'COK1', 'pin' => '682001', 'lat' => 9.9312, 'lng' => 76.2673],
                    ['city' => 'Thiruvananthapuram', 'code' => 'TRV1', 'pin' => '695001', 'lat' => 8.5241, 'lng' => 76.9366],
                ],
                'Andhra Pradesh' => [
                    ['city' => 'Visakhapatnam', 'code' => 'VIZ1', 'pin' => '530001', 'lat' => 17.6868, 'lng' => 83.2185],
                    ['city' => 'Vijayawada', 'code' => 'VIJ1', 'pin' => '520001', 'lat' => 16.5062, 'lng' => 80.6480],
                ],
            ],
            'EAST' => [
                'West Bengal' => [
                    ['city' => 'Kolkata', 'code' => 'KOL1', 'pin' => '700001', 'lat' => 22.5726, 'lng' => 88.3639],
                    ['city' => 'Howrah', 'code' => 'HOW1', 'pin' => '711101', 'lat' => 22.5958, 'lng' => 88.2636],
                    ['city' => 'Siliguri', 'code' => 'SLG1', 'pin' => '734001', 'lat' => 26.7271, 'lng' => 88.3953],
                ],
                'Bihar' => [
                    ['city' => 'Patna', 'code' => 'PAT1', 'pin' => '800001', 'lat' => 25.5941, 'lng' => 85.1376],
                    ['city' => 'Gaya', 'code' => 'GAY1', 'pin' => '823001', 'lat' => 24.7914, 'lng' => 85.0002],
                ],
                'Odisha' => [
                    ['city' => 'Bhubaneswar', 'code' => 'BBI1', 'pin' => '751001', 'lat' => 20.2961, 'lng' => 85.8245],
                    ['city' => 'Cuttack', 'code' => 'CTC1', 'pin' => '753001', 'lat' => 20.4625, 'lng' => 85.8830],
                ],
                'Jharkhand' => [
                    ['city' => 'Ranchi', 'code' => 'RNC1', 'pin' => '834001', 'lat' => 23.3441, 'lng' => 85.3096],
                    ['city' => 'Jamshedpur', 'code' => 'IXW1', 'pin' => '831001', 'lat' => 22.8046, 'lng' => 86.2029],
                ],
                'Assam' => [
                    ['city' => 'Guwahati', 'code' => 'GAU1', 'pin' => '781001', 'lat' => 26.1445, 'lng' => 91.7362],
                ],
            ]
        ];
    }
}

