<?php
namespace App\Models;

use App\Config\Database;
use App\Services\QRCodeService;
use PDO;
use Exception;

class AssetTag {

    private static bool $tableReady = false;

    public static function ensureTable(): void {
        if (self::$tableReady) {
            return;
        }
        $pdo = Database::getConnection();
        // DDL can implicitly commit MySQL transactions — never call inside beginTransaction()
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS `asset_tags` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tag_code` VARCHAR(32) NOT NULL UNIQUE,
            `status` ENUM('unused','assigned') NOT NULL DEFAULT 'unused',
            `freezer_id` INT NULL,
            `qr_code_path` VARCHAR(255) NULL,
            `qr_code_data` TEXT NULL,
            `batch_label` VARCHAR(100) NULL,
            `created_by` INT NULL,
            `assigned_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_asset_tags_status` (`status`),
            INDEX `idx_asset_tags_freezer` (`freezer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$tableReady = true;
    }

    public static function generateTagCode(): string {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $code = 'RO-' . $suffix;
        } while (self::findByCode($code));
        return $code;
    }

    public static function findByCode(string $code): ?array {
        self::ensureTable();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `asset_tags` WHERE `tag_code` = :c LIMIT 1");
        $stmt->execute(['c' => strtoupper(trim($code))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findById(int $id): ?array {
        self::ensureTable();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `asset_tags` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByFreezerId(int $freezerId): ?array {
        self::ensureTable();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `asset_tags` WHERE `freezer_id` = :fid LIMIT 1");
        $stmt->execute(['fid' => $freezerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return list<array>
     */
    public static function createBatch(int $count, ?int $createdBy = null, ?string $batchLabel = null): array {
        self::ensureTable();
        if ($count < 1 || $count > 200) {
            throw new Exception('Generate between 1 and 200 blank QR tags at a time.');
        }

        $batchLabel = $batchLabel !== null && trim($batchLabel) !== ''
            ? trim($batchLabel)
            : ('BATCH-' . date('Ymd-His'));

        $created = [];
        $pdo = Database::getConnection();
        // Avoid wrapping in a DB transaction: QR file I/O + MySQL DDL side-effects
        // can end the transaction and cause "There is no active transaction" on rollBack.
        for ($i = 0; $i < $count; $i++) {
            $code = self::generateTagCode();
            $qr = QRCodeService::generateAndSaveTag($code);
            if (empty($qr['success'])) {
                throw new Exception($qr['error'] ?? 'Failed to generate QR for ' . $code);
            }
            $stmt = $pdo->prepare("
                INSERT INTO `asset_tags`
                    (`tag_code`, `status`, `qr_code_path`, `qr_code_data`, `batch_label`, `created_by`)
                VALUES
                    (:code, 'unused', :path, :data, :batch, :uid)
            ");
            $stmt->execute([
                'code' => $code,
                'path' => $qr['file_path'],
                'data' => $qr['payload'],
                'batch' => $batchLabel,
                'uid' => $createdBy,
            ]);
            $id = (int)$pdo->lastInsertId();
            $row = self::findById($id);
            if ($row) {
                $created[] = $row;
            }
        }
        return $created;
    }

    /**
     * Ensure a freezer has a linked asset tag (for profile QR sticker Tag code).
     * Creates + assigns a new RO- tag when none exists yet.
     */
    public static function ensureForFreezer(int $freezerId, ?int $createdBy = null): array {
        $existing = self::findByFreezerId($freezerId);
        if ($existing) {
            return $existing;
        }

        $created = self::createBatch(1, $createdBy, 'AUTO-PROFILE-' . date('Ymd'));
        $tag = $created[0] ?? null;
        if (!$tag) {
            throw new Exception('Could not create tag code for this freezer.');
        }
        self::assignToFreezer($tag['tag_code'], $freezerId);

        // Point freezer QR at the blank-tag URL so scan stays consistent
        $pdo = Database::getConnection();
        $fresh = self::findByCode($tag['tag_code']);
        if ($fresh) {
            $upd = $pdo->prepare("
                UPDATE `freezers`
                SET `qr_code_path` = :path, `qr_code_data` = :data
                WHERE `id` = :id
            ");
            $upd->execute([
                'path' => $fresh['qr_code_path'],
                'data' => $fresh['qr_code_data'],
                'id' => $freezerId,
            ]);
        }

        $linked = self::findByFreezerId($freezerId);
        if (!$linked) {
            throw new Exception('Tag code was created but could not be linked to freezer.');
        }
        return $linked;
    }

    public static function assignToFreezer(string $tagCode, int $freezerId): void {
        self::ensureTable();
        $tag = self::findByCode($tagCode);
        if (!$tag) {
            throw new Exception("Blank QR tag '{$tagCode}' not found.");
        }
        if ($tag['status'] === 'assigned' && (int)($tag['freezer_id'] ?? 0) !== $freezerId) {
            throw new Exception("QR tag '{$tagCode}' is already assigned to another freezer.");
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            UPDATE `asset_tags`
            SET `status` = 'assigned', `freezer_id` = :fid, `assigned_at` = NOW()
            WHERE `id` = :id AND (`status` = 'unused' OR `freezer_id` = :fid2)
        ");
        $stmt->execute([
            'fid' => $freezerId,
            'fid2' => $freezerId,
            'id' => (int)$tag['id'],
        ]);
        if ($stmt->rowCount() < 1 && $tag['status'] !== 'assigned') {
            throw new Exception("Could not assign QR tag '{$tagCode}'.");
        }
    }

    /**
     * Tags marked assigned but freezer missing / soft-deleted → back to unused.
     */
    public static function releaseOrphanAssignedTags(): int {
        self::ensureTable();
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            "UPDATE `asset_tags` t
             LEFT JOIN `freezers` f ON f.id = t.freezer_id AND f.deleted_at IS NULL
             SET t.`status` = 'unused',
                 t.`freezer_id` = NULL,
                 t.`assigned_at` = NULL
             WHERE t.`status` = 'assigned'
               AND f.id IS NULL"
        );
        return $stmt ? $stmt->rowCount() : 0;
    }

    /**
     * @return array{unused:int,assigned:int,total:int}
     */
    public static function getStats(): array {
        self::ensureTable();
        // Orphan cleanup at most once per hour — not on every page load.
        self::maybeReleaseOrphans();
        $pdo = Database::getConnection();
        // Assigned = only tags linked to a live freezer
        $assigned = (int)$pdo->query(
            "SELECT COUNT(*) FROM `asset_tags` t
             INNER JOIN `freezers` f ON f.id = t.freezer_id AND f.deleted_at IS NULL
             WHERE t.`status` = 'assigned'"
        )->fetchColumn();
        $unused = (int)$pdo->query("SELECT COUNT(*) FROM `asset_tags` WHERE `status` = 'unused'")->fetchColumn();
        return [
            'unused' => $unused,
            'assigned' => $assigned,
            'total' => $unused + $assigned,
        ];
    }

    private static function maybeReleaseOrphans(): void {
        $marker = dirname(__DIR__, 2) . '/logs/.asset_tag_orphan_cleanup';
        $now = time();
        $last = is_file($marker) ? (int)@filemtime($marker) : 0;
        if ($last > 0 && ($now - $last) < 3600) {
            return;
        }
        try {
            self::releaseOrphanAssignedTags();
            @touch($marker);
        } catch (\Throwable $e) {
            // ignore — stats still return counts
        }
    }

    public static function countTags(string $status = ''): int {
        self::ensureTable();
        $pdo = Database::getConnection();
        if ($status === 'unused' || $status === 'assigned') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `asset_tags` WHERE `status` = :st");
            $stmt->execute(['st' => $status]);
            return (int)$stmt->fetchColumn();
        }
        return (int)$pdo->query("SELECT COUNT(*) FROM `asset_tags`")->fetchColumn();
    }

    /**
     * @return list<array>
     */
    public static function listTags(string $status = '', int $limit = 100, int $offset = 0): array {
        self::ensureTable();
        $pdo = Database::getConnection();
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = "SELECT t.*, f.unique_freezer_id
                FROM `asset_tags` t
                LEFT JOIN `freezers` f ON f.id = t.freezer_id";
        $params = [];
        if ($status === 'unused' || $status === 'assigned') {
            $sql .= " WHERE t.status = :st";
            $params['st'] = $status;
        }
        $sql .= " ORDER BY t.id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Typeahead search for scan fallback — unused + assigned tags matching code.
     *
     * @return list<array{code:string,status:string,unique_freezer_id:?string,freezer_type:?string,store_name:?string,label:string}>
     */
    public static function searchForSuggest(string $query, int $limit = 12): array {
        self::ensureTable();
        $q = strtoupper(trim($query));
        if ($q === '' || strlen($q) < 1) {
            return [];
        }
        $pdo = Database::getConnection();
        Freezer::ensureSimColumns();
        $limit = max(1, min(25, $limit));
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        // Prefer prefix matches; unused first then assigned (with freezer type)
        $sql = "SELECT t.tag_code, t.status, f.unique_freezer_id, f.freezer_type, s.store_name
                FROM `asset_tags` t
                LEFT JOIN `freezers` f ON f.id = t.freezer_id
                LEFT JOIN `stores` s ON s.id = f.store_id
                WHERE t.tag_code LIKE :q
                ORDER BY
                    CASE WHEN t.tag_code LIKE :prefix THEN 0 ELSE 1 END,
                    CASE t.status WHEN 'unused' THEN 0 ELSE 1 END,
                    t.tag_code ASC
                LIMIT {$limit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'q' => $like,
            'prefix' => $q . '%',
        ]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = (string)($row['tag_code'] ?? '');
            $status = (string)($row['status'] ?? 'unused');
            $fid = $row['unique_freezer_id'] ?? null;
            $ftype = $row['freezer_type'] ?? null;
            $store = $row['store_name'] ?? null;
            if ($status === 'assigned') {
                $bits = array_filter([$ftype, $fid, $store]);
                $label = $code . ' · Assigned' . ($bits ? ' · ' . implode(' · ', $bits) : '');
            } else {
                $label = $code . ' · Unused blank tag';
            }
            $out[] = [
                'code' => $code,
                'status' => $status,
                'unique_freezer_id' => $fid,
                'freezer_type' => $ftype,
                'store_name' => $store,
                'label' => $label,
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return list<array>
     */
    public static function findByIds(array $ids): array {
        self::ensureTable();
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $pdo = Database::getConnection();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT t.*, f.unique_freezer_id
            FROM `asset_tags` t
            LEFT JOIN `freezers` f ON f.id = t.freezer_id
            WHERE t.`id` IN ({$in})
            ORDER BY t.`id` ASC
        ");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
