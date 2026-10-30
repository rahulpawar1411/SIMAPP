<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class IoTDevice {

    public static function registerDevice(int $freezerId, array $data): int {
        $pdo = Database::getConnection();

        $sql = "INSERT INTO `iot_devices` (
            `freezer_id`, `device_serial`, `imei_or_mac`, `sim_number`, `sim_company`, `device_type`,
            `device_owner`, `oem_model`, `battery_percentage`, `power_status`, `last_ping_at`
        ) VALUES (
            :freezer_id, :device_serial, :imei_or_mac, :sim_number, :sim_company, :device_type,
            :device_owner, :oem_model, :battery_percentage, :power_status, NOW()
        ) ON DUPLICATE KEY UPDATE
            `device_serial` = VALUES(`device_serial`),
            `imei_or_mac` = VALUES(`imei_or_mac`),
            `sim_number` = VALUES(`sim_number`),
            `sim_company` = VALUES(`sim_company`),
            `device_type` = VALUES(`device_type`),
            `device_owner` = VALUES(`device_owner`),
            `oem_model` = VALUES(`oem_model`),
            `last_ping_at` = NOW()";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'freezer_id' => $freezerId,
            'device_serial' => trim($data['device_serial']),
            'imei_or_mac' => !empty($data['imei_or_mac']) ? trim($data['imei_or_mac']) : null,
            'sim_number' => !empty($data['sim_number']) ? trim($data['sim_number']) : null,
            'sim_company' => !empty($data['sim_company']) ? trim($data['sim_company']) : null,
            'device_type' => $data['device_type'] ?? 'Temperature & GPS',
            'device_owner' => $data['device_owner'] ?? 'ReeferON Own Device',
            'oem_model' => $data['oem_model'] ?? 'RF-T100',
            'battery_percentage' => $data['battery_percentage'] ?? 98,
            'power_status' => $data['power_status'] ?? 'ON',
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function registerOrUpdateDevice(int $freezerId, array $data): bool {
        return (bool)self::registerDevice($freezerId, $data);
    }

    public static function findByFreezerId(int $freezerId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `iot_devices` WHERE `freezer_id` = :fid LIMIT 1");
        $stmt->execute(['fid' => $freezerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function recordTelemetry(
        int $freezerId,
        float $temperature,
        bool $compressorRunning = true,
        bool $doorOpen = false,
        string $powerStatus = 'ON',
        int $batteryLevel = 98,
        ?string $recordedAt = null
    ): int {
        $pdo = Database::getConnection();

        $targetMin = -24.00;
        $targetMax = -18.00;
        $isAlert = ($temperature > $targetMax || $temperature < -30.00) ? 1 : 0;
        $timestamp = $recordedAt ?: date('Y-m-d H:i:s');

        $sql = "INSERT INTO `iot_telemetry` (
            `freezer_id`, `temperature`, `target_min_temp`, `target_max_temp`,
            `is_alert`, `compressor_running`, `door_open`, `power_status`,
            `battery_level`, `recorded_at`
        ) VALUES (
            :freezer_id, :temperature, :target_min, :target_max,
            :is_alert, :compressor, :door, :power_status,
            :battery_level, :recorded_at
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'freezer_id' => $freezerId,
            'temperature' => $temperature,
            'target_min' => $targetMin,
            'target_max' => $targetMax,
            'is_alert' => $isAlert,
            'compressor' => $compressorRunning ? 1 : 0,
            'door' => $doorOpen ? 1 : 0,
            'power_status' => $powerStatus,
            'battery_level' => $batteryLevel,
            'recorded_at' => $timestamp,
        ]);

        // Update last ping in iot_devices
        $upd = $pdo->prepare("UPDATE `iot_devices` SET `last_ping_at` = :ts, `power_status` = :pwr WHERE `freezer_id` = :fid");
        $upd->execute(['ts' => $timestamp, 'pwr' => $powerStatus, 'fid' => $freezerId]);

        return (int)$pdo->lastInsertId();
    }

    public static function getRecentTelemetry(int $freezerId, int $limit = 20): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `iot_telemetry` WHERE `freezer_id` = :fid ORDER BY `recorded_at` DESC LIMIT :limit");
        $stmt->bindValue(':fid', $freezerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return array_reverse($rows); // chronological order
    }

    public static function getLatestTelemetry(int $freezerId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `iot_telemetry` WHERE `freezer_id` = :fid ORDER BY `recorded_at` DESC LIMIT 1");
        $stmt->execute(['fid' => $freezerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Seeds realistic 12-hour temperature telemetry for demo & testing.
     */
    public static function seedSampleTelemetry(int $freezerId): void {
        $pdo = Database::getConnection();
        $check = $pdo->prepare("SELECT COUNT(*) FROM `iot_telemetry` WHERE `freezer_id` = :fid");
        $check->execute(['fid' => $freezerId]);
        if ($check->fetchColumn() > 0) {
            return; // already has data
        }

        $now = time();
        for ($i = 12; $i >= 0; $i--) {
            $time = date('Y-m-d H:i:s', $now - ($i * 3600));
            // Realistic temperature range between -22.5 and -18.2 °C
            $temp = round(-21.5 + (sin($i) * 2.2) + (rand(-5, 5) / 10), 1);
            $compressor = ($temp > -20.0) ? 1 : (rand(0, 10) > 3 ? 1 : 0);
            $door = ($i === 2 || $i === 6) ? 1 : 0;
            self::recordTelemetry($freezerId, $temp, (bool)$compressor, (bool)$door, 'ON', 98, $time);
        }
    }
}
