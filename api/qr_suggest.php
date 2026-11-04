<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AuthService;
use App\Models\AssetTag;
use App\Models\Freezer;
use App\Config\Database;

AuthService::requireLogin();

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || strlen($q) < 1) {
    echo json_encode(['success' => true, 'items' => []]);
    exit;
}

$limit = 12;
$items = AssetTag::searchForSuggest($q, $limit);

// Also suggest Unique Freezer IDs (when typing DF… / non-RO codes)
$needMore = $limit - count($items);
if ($needMore > 0 && !preg_match('/^RO-/i', $q)) {
    try {
        Freezer::ensureSimColumns();
        $pdo = Database::getConnection();
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], strtoupper($q)) . '%';
        $stmt = $pdo->prepare(
            "SELECT f.unique_freezer_id, f.freezer_type, s.store_name
             FROM `freezers` f
             INNER JOIN `stores` s ON s.id = f.store_id
             WHERE f.deleted_at IS NULL
               AND UPPER(f.unique_freezer_id) LIKE :q
             ORDER BY
               CASE WHEN UPPER(f.unique_freezer_id) LIKE :prefix THEN 0 ELSE 1 END,
               f.unique_freezer_id ASC
             LIMIT {$needMore}"
        );
        $stmt->execute([
            'q' => $like,
            'prefix' => strtoupper($q) . '%',
        ]);
        $seen = [];
        foreach ($items as $it) {
            if (!empty($it['unique_freezer_id'])) {
                $seen[strtoupper((string)$it['unique_freezer_id'])] = true;
            }
            $seen[strtoupper((string)$it['code'])] = true;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $uid = (string)($row['unique_freezer_id'] ?? '');
            if ($uid === '' || isset($seen[strtoupper($uid)])) {
                continue;
            }
            $ftype = $row['freezer_type'] ?? null;
            $store = $row['store_name'] ?? null;
            $bits = array_filter([$ftype, $store]);
            $items[] = [
                'code' => $uid,
                'status' => 'assigned',
                'unique_freezer_id' => $uid,
                'freezer_type' => $ftype,
                'store_name' => $store,
                'label' => $uid . ' · Freezer ID' . ($bits ? ' · ' . implode(' · ', $bits) : ''),
                'kind' => 'freezer',
            ];
        }
    } catch (\Throwable $e) {
        // ignore freezer suggest failures
    }
}

echo json_encode(['success' => true, 'items' => $items]);
