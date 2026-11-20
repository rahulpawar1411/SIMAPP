<?php
namespace App\Services;

use App\Config\Database;
use PDO;
use InvalidArgumentException;

class FreezerIdGenerator {

    /**
     * Sanitizes and standardizes model number for ID inclusion.
     */
    public static function sanitizeModel(string $model): string {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $model));
        return $clean !== '' ? $clean : 'GENERIC';
    }

    /**
     * Normalizes PIN for Freezer ID — full 6 digits.
     */
    public static function extractPinCode(string $pincode): string {
        $digits = preg_replace('/\D/', '', $pincode);
        if (strlen($digits) >= 6) {
            return substr($digits, 0, 6);
        }
        return str_pad($digits, 6, '0', STR_PAD_LEFT);
    }

    /** @deprecated Use extractPinCode() — kept for callers still expecting the old name */
    public static function extractPinLast3(string $pincode): string {
        return self::extractPinCode($pincode);
    }

    /**
     * Formats installation date or MM/YY string to MMYY.
     */
    public static function formatInstallMonthYear(string $dateOrMmyy): string {
        $clean = preg_replace('/\D/', '', $dateOrMmyy);
        if (strlen($clean) === 4) {
            // Already MMYY
            return $clean;
        }
        // Try parsing as standard date
        $time = strtotime($dateOrMmyy);
        if ($time !== false) {
            return date('my', $time); // '0324'
        }
        return date('my');
    }

    /**
     * Zone → 3-letter unit prefix (…001 style).
     * WEST=WAA, NORTH=NAA, SOUTH=SAA, EAST=EAA, CENTRAL=CAA
     */
    public static function zoneUnitPrefix(?string $zone = null): string {
        $z = strtoupper(trim((string)$zone));
        // Accept common typos / short codes
        if ($z === 'WAST' || $z === 'WE' || $z === 'W') {
            $z = 'WEST';
        }
        return match ($z) {
            'WEST' => 'WAA',
            'NORTH' => 'NAA',
            'SOUTH' => 'SAA',
            'EAST' => 'EAA',
            'CENTRAL' => 'CAA',
            default => 'WAA', // default West
        };
    }

    /**
     * Next sequential unit code for a zone: WAA001, NAA001, …
     */
    public static function nextSequentialUnitSequence(?string $zone = null, ?int $excludeId = null): string {
        $prefix = self::zoneUnitPrefix($zone);
        $pdo = Database::getConnection();
        $sql = "SELECT `unit_sequence`
                FROM `freezers`
                WHERE `unit_sequence` REGEXP :re";
        $params = ['re' => '^' . $prefix . '[0-9]{3,}$'];
        if ($excludeId !== null) {
            $sql .= " AND `id` != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }
        $sql .= " ORDER BY CAST(SUBSTRING(`unit_sequence`, " . (strlen($prefix) + 1) . ") AS UNSIGNED) DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $last = strtoupper(trim((string)($stmt->fetchColumn() ?: '')));
        $nextNum = 1;
        if ($last !== '' && preg_match('/^' . preg_quote($prefix, '/') . '([0-9]+)$/', $last, $m)) {
            $nextNum = ((int)$m[1]) + 1;
        }
        if ($nextNum > 999999) {
            throw new \RuntimeException("Unit sequence overflow for prefix {$prefix}.");
        }
        return $prefix . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @deprecated Prefer nextSequentialUnitSequence() — kept for rare clash fallback
     */
    public static function randomUnitSequence(): string {
        return self::nextSequentialUnitSequence(null);
    }

    /**
     * Builds the prefix string for the freezer ID:
     * TYPE-BRAND-MODEL-LOCATION-MMYY-PIN6-
     * e.g. DF-RW-SFR550DDU-AMD1-0324-380058-
     */
    public static function buildPrefix(
        string $freezerType,
        string $companyCode,
        string $modelNumber,
        string $locationCode,
        string $pincode,
        string $installMonthYear
    ): string {
        $type = strtoupper(trim($freezerType));
        $company = strtoupper(trim($companyCode));
        $model = self::sanitizeModel($modelNumber);
        $loc = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $locationCode)));
        $pin = self::extractPinCode($pincode);
        $mmyy = self::formatInstallMonthYear($installMonthYear);

        return sprintf('%s-%s-%s-%s-%s-%s-', $type, $company, $model, $loc, $mmyy, $pin);
    }

    /**
     * Generates a complete unique freezer ID with zone sequential unit sequence (WAA001…)
     * or a preferred sequence if given.
     *
     * @param string|null $zone Store zone (WEST/NORTH/SOUTH/EAST/CENTRAL) for WAA/NAA/…
     */
    public static function generateUniqueId(
        string $freezerType,
        string $companyCode,
        string $modelNumber,
        string $locationCode,
        string $pincode,
        string $installMonthYear,
        ?string $preferredUnitSeq = null,
        ?int $excludeId = null,
        ?string $zone = null
    ): array {
        $idPrefix = self::buildPrefix($freezerType, $companyCode, $modelNumber, $locationCode, $pincode, $installMonthYear);
        $zonePrefix = self::zoneUnitPrefix($zone);
        $useAuto = ($preferredUnitSeq === null || trim($preferredUnitSeq) === '');
        if ($useAuto) {
            $seq = self::nextSequentialUnitSequence($zone, $excludeId);
        } else {
            $seq = strtoupper(trim($preferredUnitSeq));
        }

        // Loop: unique full ID + unique unit_sequence vs existing rows
        $maxAttempts = 500;
        $attempt = 0;
        $candidateId = $idPrefix . $seq;

        while ($attempt < $maxAttempts) {
            $idTaken = self::isDuplicate($candidateId, $excludeId);
            $seqTaken = self::isUnitSequenceDuplicate($seq, $excludeId);
            if (!$idTaken && !$seqTaken) {
                return [
                    'freezer_id' => $candidateId,
                    'prefix' => $idPrefix,
                    'unit_sequence' => $seq,
                    'freezer_type' => strtoupper(trim($freezerType)),
                    'company_code' => strtoupper(trim($companyCode)),
                    'model_number' => self::sanitizeModel($modelNumber),
                    'location_code' => strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $locationCode))),
                    'pin_last3' => self::extractPinCode($pincode),
                    'pin_code' => self::extractPinCode($pincode),
                    'install_month_year' => self::formatInstallMonthYear($installMonthYear),
                    'zone_prefix' => $zonePrefix,
                ];
            }
            // Clash → bump AAA### number (same zone prefix)
            if (preg_match('/^([A-Z]{3})([0-9]+)$/', $seq, $m)) {
                $seq = $m[1] . str_pad((string)(((int)$m[2]) + 1), max(3, strlen($m[2])), '0', STR_PAD_LEFT);
            } else {
                $seq = self::nextSequentialUnitSequence($zone, $excludeId);
            }
            $candidateId = $idPrefix . $seq;
            $attempt++;
        }

        throw new \RuntimeException("Unable to generate unique Freezer ID after {$maxAttempts} attempts.");
    }

    /**
     * Checks if a freezer ID already exists in the database.
     */
    public static function isDuplicate(string $freezerId, ?int $excludeId = null): bool {
        $pdo = Database::getConnection();
        $sql = "SELECT COUNT(*) FROM `freezers` WHERE `unique_freezer_id` = :fid";
        $params = ['fid' => trim($freezerId)];

        if ($excludeId !== null) {
            $sql .= " AND `id` != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * True if unit_sequence is already used on any freezer (existing / legacy rows).
     */
    public static function isUnitSequenceDuplicate(string $unitSequence, ?int $excludeId = null): bool {
        $seq = strtoupper(trim($unitSequence));
        if ($seq === '') {
            return false;
        }
        $pdo = Database::getConnection();
        $sql = "SELECT COUNT(*) FROM `freezers` WHERE UPPER(`unit_sequence`) = :seq";
        $params = ['seq' => $seq];

        if ($excludeId !== null) {
            $sql .= " AND `id` != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * Validates an ID string against the standard 7-part pattern:
     * New:  DF-RW-SFR550DDU-AMD1-0324-380058-AAA004  (MMYY then PIN6)
     * Legacy: DF-RW-SFR550DDU-AMD1-380058-0324-AAA004 (PIN then MMYY) still accepted
     */
    public static function validateIdFormat(string $id): array {
        $id = trim($id);
        // New order: …-LOCATION-MMYY-PIN-SEQ
        $patternNew = '/^([A-Z0-9]{2,6})-([A-Z0-9]{2,6})-([A-Z0-9_-]{2,30})-([A-Z0-9]{2,10})-([0-9]{4})-([0-9]{3}|[0-9]{6})-([A-Z0-9]{3,10})$/i';
        // Legacy order: …-LOCATION-PIN-MMYY-SEQ
        $patternLegacy = '/^([A-Z0-9]{2,6})-([A-Z0-9]{2,6})-([A-Z0-9_-]{2,30})-([A-Z0-9]{2,10})-([0-9]{3}|[0-9]{6})-([0-9]{4})-([A-Z0-9]{3,10})$/i';

        if (preg_match($patternNew, $id, $matches)) {
            return [
                'valid' => true,
                'error' => null,
                'parts' => [
                    'full' => strtoupper($id),
                    'freezer_type' => strtoupper($matches[1]),
                    'company_code' => strtoupper($matches[2]),
                    'model_number' => strtoupper($matches[3]),
                    'location_code' => strtoupper($matches[4]),
                    'install_month_year' => $matches[5],
                    'pincode_last3' => $matches[6],
                    'unit_sequence' => strtoupper($matches[7]),
                ],
            ];
        }

        if (preg_match($patternLegacy, $id, $matches)) {
            return [
                'valid' => true,
                'error' => null,
                'parts' => [
                    'full' => strtoupper($id),
                    'freezer_type' => strtoupper($matches[1]),
                    'company_code' => strtoupper($matches[2]),
                    'model_number' => strtoupper($matches[3]),
                    'location_code' => strtoupper($matches[4]),
                    'pincode_last3' => $matches[5],
                    'install_month_year' => $matches[6],
                    'unit_sequence' => strtoupper($matches[7]),
                ],
            ];
        }

        return [
            'valid' => false,
            'error' => "ID does not match expected format: {TYPE}-{COMPANY}-{MODEL}-{LOCATION}-{MMYY}-{PIN6}-{UNITSEQ} (e.g. DF-RW-SFR550DDU-AMD1-0324-380058-AAA004)",
            'parts' => null,
        ];
    }
}
