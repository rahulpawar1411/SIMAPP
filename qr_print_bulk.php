<?php
/**
 * Bulk hub stickers — same style as blank QR / profile reprint:
 * Logo + QR + tag code only, 4 per A4.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\Database;
use App\Services\QRCodeService;
use App\Models\AssetTag;

AuthService::requireRole('super_admin');
AssetTag::ensureTable();

$city = trim((string)($_GET['city'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$limit = min(500, max(1, (int)($_GET['limit'] ?? 500)));

$pdo = Database::getConnection();
$where = ['1=1'];
$params = [];

if ($city !== '') {
    $where[] = 's.city = :city';
    $params['city'] = $city;
}
if ($status !== '') {
    $where[] = 'f.status = :status';
    $params['status'] = $status;
}
if ($q !== '') {
    $where[] = '(f.unique_freezer_id LIKE :q OR s.store_name LIKE :q OR s.outlet_id LIKE :q OR t.tag_code LIKE :q2)';
    $params['q'] = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $where);
$sql = "SELECT f.id, f.unique_freezer_id, f.qr_code_path,
               t.tag_code, t.batch_label, t.qr_code_path AS tag_qr_path
        FROM `freezers` f
        INNER JOIN `stores` s ON f.store_id = s.id
        LEFT JOIN `asset_tags` t ON t.freezer_id = f.id
        WHERE {$whereSql}
        ORDER BY f.unit_sequence ASC, f.unique_freezer_id ASC, f.id ASC
        LIMIT {$limit}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$freezers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user = AuthService::currentUser();
foreach ($freezers as &$row) {
    $tag = AssetTag::ensureForFreezer((int)$row['id'], isset($user['id']) ? (int)$user['id'] : null);
    $row['tag_code'] = $tag['tag_code'];
    $qrPath = $tag['qr_code_path'] ?: ($row['qr_code_path'] ?? '');
    if (!$qrPath || !file_exists(__DIR__ . '/' . $qrPath)) {
        $gen = QRCodeService::generateAndSaveTag($tag['tag_code']);
        $qrPath = $gen['file_path'] ?? '';
    }
    $row['qr_src'] = $qrPath;
}
unset($row);

$total = count($freezers);
$pages = array_chunk($freezers, 4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hub Stickers · <?= (int)$total ?> tags</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@600;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #e8eef4;
            padding: 1.25rem;
            font-family: 'DM Sans', system-ui, sans-serif;
            color: #0f172a;
        }
        .print-actions {
            width: 210mm;
            max-width: 100%;
            margin: 0 auto 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 0.85rem 1rem;
        }
        .print-actions p { margin: 0; font-size: 0.85rem; color: #64748b; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: none;
            border-radius: 8px;
            padding: 0.55rem 1rem;
            font-weight: 700;
            font-size: 0.875rem;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .a4-page {
            width: 210mm;
            height: 297mm;
            margin: 0 auto 1.25rem;
            padding: 8mm;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 6mm;
            page-break-after: always;
        }
        .a4-page:last-child { page-break-after: auto; }
        .blank-tag {
            border: 2.5px solid #0f172a;
            border-radius: 4px;
            padding: 6mm 4mm 4mm;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 0;
            background: #fff;
        }
        .blank-tag.empty {
            border-style: dashed;
            border-color: #cbd5e1;
            background: #f8fafc;
        }
        .blank-tag img.logo {
            height: 22mm;
            width: auto;
            max-width: 85%;
            object-fit: contain;
            margin: 0 auto 5mm;
            display: block;
        }
        .blank-tag img.qr {
            width: 68mm;
            height: 68mm;
            object-fit: contain;
            display: block;
            margin: 0 auto;
            background: #fff;
        }
        .blank-tag .tag-code {
            margin-top: 3.5mm;
            font-family: ui-monospace, monospace;
            font-size: 4.2mm;
            font-weight: 800;
            letter-spacing: 0.06em;
            color: #0f172a;
        }
        .blank-tag .freezer-id-label {
            margin-top: 2.5mm;
            font-size: 2.4mm;
            font-weight: 700;
            color: #94a3b8;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .blank-tag .freezer-id {
            margin-top: 1mm;
            font-family: ui-monospace, monospace;
            font-size: 3.2mm;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #0284c7;
            word-break: break-all;
            line-height: 1.25;
            max-width: 90%;
        }
        .blank-tag .batch-line {
            margin-top: 2mm;
            font-size: 2.5mm;
            font-weight: 700;
            color: #475569;
            letter-spacing: 0.02em;
            max-width: 92%;
            word-break: break-all;
            line-height: 1.3;
        }
        .blank-tag .batch-line strong {
            color: #0f172a;
            font-weight: 800;
        }
        .blank-tag .hint {
            margin-top: 2mm;
            font-size: 2.4mm;
            font-weight: 700;
            color: #94a3b8;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .empty-msg {
            max-width: 520px;
            margin: 2rem auto;
            text-align: center;
            color: #64748b;
        }
        @page { size: A4 portrait; margin: 0; }
        @media print {
            body { background: #fff; padding: 0; }
            .print-actions { display: none !important; }
            .a4-page { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="print-actions">
        <p>
            <strong><?= (int)$total ?></strong> sticker(s) ·
            <?= count($pages) ?> A4 page(s) ·
            Logo + QR + code only (same as blank hub)
        </p>
        <div style="display:flex; gap:0.5rem;">
            <a href="import_export.php" class="btn btn-secondary">Back</a>
            <?php if ($total > 0): ?>
            <button type="button" class="btn btn-primary" onclick="window.print()">Print A4</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($total === 0): ?>
        <div class="empty-msg">
            <h2 style="color:#0f172a;">No freezers found</h2>
            <p>Register via Scan QR first, or clear filters.</p>
        </div>
    <?php else: ?>
        <?php foreach ($pages as $pageRows): ?>
            <div class="a4-page">
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <?php if (!empty($pageRows[$i])): ?>
                        <?php $row = $pageRows[$i]; ?>
                        <div class="blank-tag">
                            <img class="logo" src="assets/img/logo.png" alt="ReeferON">
                            <?php if (!empty($row['qr_src'])): ?>
                                <img class="qr" src="<?= htmlspecialchars($row['qr_src']) ?>" alt="QR">
                            <?php endif; ?>
                            <div class="meta-label" style="margin-top:2.5mm;font-size:2.4mm;font-weight:700;color:#94a3b8;letter-spacing:0.08em;text-transform:uppercase;">Tag code</div>
                            <div class="tag-code"><?= htmlspecialchars($row['tag_code']) ?></div>
                            <div class="freezer-id-label">Unique Freezer ID · Registered</div>
                            <div class="freezer-id"><?= htmlspecialchars($row['unique_freezer_id']) ?></div>
                            <div class="hint">Scan for details</div>
                        </div>
                    <?php else: ?>
                        <div class="blank-tag empty" aria-hidden="true"></div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
