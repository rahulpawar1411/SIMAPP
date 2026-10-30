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
     * Random 6-char unit sequence: letters + digits mixed (e.g. A7K2M9).
     * Always includes at least one letter and one digit.
     */
    public static function randomUnitSequence(): string {
        $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $chars = [];
        for ($i = 0; $i < 6; $i++) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }
        // Guarantee letter + digit mix (vs existing AAA001-style or pure digits)
        $joined = implode('', $chars);
        if (!preg_match('/[A-Z]/', $joined) || !preg_match('/[0-9]/', $joined)) {
            $chars[random_int(0, 2)] = chr(random_int(65, 90));
            $chars[random_int(3, 5)] = (string)random_int(0, 9);
        }
        // Shuffle so letter/digit positions vary
        for ($i = 5; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
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
     * Generates a complete unique freezer ID with a random unit sequence (or preferred if given).
     * If unit_sequence already exists in DB (legacy AAA001 etc.), re-rolls a new 6-char mix.
     */
    public static function generateUniqueId(
        string $freezerType,
        string $companyCode,
        string $modelNumber,
        string $locationCode,
        string $pincode,
        string $installMonthYear,
        ?string $preferredUnitSeq = null,
        ?int $excludeId = null
    ): array {
        $prefix = self::buildPrefix($freezerType, $companyCode, $modelNumber, $locationCode, $pincode, $installMonthYear);
        $useRandom = ($preferredUnitSeq === null || trim($preferredUnitSeq) === '');
        $seq = $useRandom ? self::randomUnitSequence() : strtoupper(trim($preferredUnitSeq));

        // Loop: unique full ID + unique unit_sequence vs existing rows
        $maxAttempts = 500;
        $attempt = 0;
        $candidateId = $prefix . $seq;

        while ($attempt < $maxAttempts) {
            $idTaken = self::isDuplicate($candidateId, $excludeId);
            $seqTaken = self::isUnitSequenceDuplicate($seq, $excludeId);
            if (!$idTaken && !$seqTaken) {
                return [
                    'freezer_id' => $candidateId,
                    'prefix' => $prefix,
                    'unit_sequence' => $seq,
                    'freezer_type' => strtoupper(trim($freezerType)),
                    'company_code' => strtoupper(trim($companyCode)),
                    'model_number' => self::sanitizeModel($modelNumber),
                    'location_code' => strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $locationCode))),
                    'pin_last3' => self::extractPinCode($pincode),
                    'pin_code' => self::extractPinCode($pincode),
                    'install_month_year' => self::formatInstallMonthYear($installMonthYear),
                ];
            }
            // Clash with existing data → always new 6-char letter+digit mix
            $seq = self::randomUnitSequence();
            $candidateId = $prefix . $seq;
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
