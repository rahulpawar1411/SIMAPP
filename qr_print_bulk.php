<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Config\Database;
use App\Services\QRCodeService;

AuthService::requireRole(['super_admin', 'sub_admin', 'technician']);

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
    $where[] = '(f.unique_freezer_id LIKE :q OR s.store_name LIKE :q OR s.outlet_id LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $where);
$sql = "SELECT f.*,
               s.store_name, s.outlet_id, s.city as store_city,
               iot.device_serial
        FROM `freezers` f
        INNER JOIN `stores` s ON f.store_id = s.id
        LEFT JOIN `iot_devices` iot ON f.id = iot.freezer_id
        WHERE {$whereSql}
        ORDER BY f.unit_sequence ASC, f.unique_freezer_id ASC, f.id ASC
        LIMIT {$limit}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$freezers = $stmt->fetchAll();

foreach ($freezers as &$row) {
    $qrPath = $row['qr_code_path'] ?? '';
    if (!$qrPath || !file_exists(__DIR__ . '/' . $qrPath)) {
        $gen = QRCodeService::generateAndSave($row['unique_freezer_id']);
        $qrPath = $gen['file_path'] ?? '';
        $row['qr_code_path'] = $qrPath;
    }
    $row['install_ddm'] = !empty($row['installation_date'])
        ? date('dm', strtotime($row['installation_date']))
        : ($row['install_month_year'] ?? '—');
}
unset($row);

$total = count($freezers);
$pages = array_chunk($freezers, 6);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hub Stickers PDF · <?= (int)$total ?> tags</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@500;700;800&family=JetBrains+Mono:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        /* Profile sticker (qr_print.php) design — scaled for 6 / A4 */
        :root {
            --tag-ink: #0f172a;
            --tag-blue: #0284c7;
            --tag-muted: #64748b;
            --tag-line: #cbd5e1;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #e8eef4;
            padding: 1.25rem;
            font-family: 'DM Sans', system-ui, sans-serif;
            color: var(--tag-ink);
        }
        .print-actions {
            max-width: 210mm;
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
        .print-actions .meta {
            font-size: 0.82rem;
            color: #64748b;
            margin-top: 0.35rem;
        }
        .print-actions .meta strong { color: #0f172a; }
        .hint {
            max-width: 210mm;
            margin: 0 auto 1rem;
            font-size: 0.8rem;
            color: #475569;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
        }
        .empty {
            max-width: 620px;
            margin: 2rem auto;
            text-align: center;
            color: #64748b;
            background: #fff;
            padding: 2rem;
            border-radius: 10px;
        }

        .a4-page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 1.25rem;
            background: #fff;
            padding: 7mm;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
        }
        .a4-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: repeat(3, 1fr);
            gap: 4.5mm;
            height: calc(297mm - 14mm);
        }

        /* ===== Same card anatomy as profile qr_print.php ===== */
        .industrial-tag {
            height: 100%;
            background: #fff;
            border: 2.5px solid var(--tag-ink);
            border-radius: 4px;
            padding: 3.2mm 3.6mm 2.8mm;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .tag-header {
            display: flex;
            align-items: center;
            gap: 2.8mm;
            padding-bottom: 2.4mm;
            border-bottom: 2px solid var(--tag-ink);
            margin-bottom: 2.4mm;
            flex-shrink: 0;
        }
        .tag-header img {
            height: 12.5mm;
            width: auto;
            object-fit: contain;
            flex-shrink: 0;
        }
        .tag-brand { flex: 1; min-width: 0; }
        .tag-brand h1 {
            margin: 0;
            font-size: 4mm;
            font-weight: 800;
            color: var(--tag-blue);
            letter-spacing: -0.03em;
            line-height: 1.1;
            white-space: nowrap;
        }
        .tag-brand p {
            margin: 0.5mm 0 0;
            font-size: 1.7mm;
            font-weight: 700;
            color: var(--tag-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            white-space: nowrap;
        }
        .tag-type {
            flex-shrink: 0;
            background: var(--tag-ink);
            color: #fff;
            font-size: 2mm;
            font-weight: 800;
            letter-spacing: 0.06em;
            padding: 1.1mm 1.8mm;
            border-radius: 2px;
            white-space: nowrap;
        }

        .tag-id-block {
            margin-bottom: 2.4mm;
            padding-bottom: 2.2mm;
            border-bottom: 1px solid var(--tag-line);
            flex-shrink: 0;
        }
        .tag-id-label {
            display: block;
            font-size: 1.65mm;
            font-weight: 700;
            color: var(--tag-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.7mm;
        }
        .tag-id-value {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 2.7mm;
            font-weight: 800;
            color: var(--tag-ink);
            letter-spacing: -0.03em;
            line-height: 1.2;
            word-break: break-all;
        }

        .tag-body {
            display: flex;
            gap: 3.2mm;
            align-items: center;
            flex: 1;
            min-height: 0;
        }
        .tag-qr {
            width: 32mm;
            height: 32mm;
            flex: 0 0 32mm;
            border: 2px solid var(--tag-ink);
            border-radius: 3px;
            padding: 1.4mm;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .tag-qr img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .tag-meta {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 1.6mm;
        }
        .meta-row {
            display: flex;
            align-items: flex-start;
            gap: 1.6mm;
            min-width: 0;
        }
        .meta-label {
            flex-shrink: 0;
            width: 15mm;
            font-size: 1.7mm;
            font-weight: 700;
            color: var(--tag-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding-top: 0.3mm;
        }
        .meta-value {
            flex: 1;
            min-width: 0;
            font-size: 2.55mm;
            font-weight: 700;
            color: var(--tag-ink);
            line-height: 1.25;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .meta-value.mono {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 2.35mm;
            letter-spacing: -0.02em;
            word-break: break-all;
        }

        .tag-footer {
            margin-top: auto;
            padding-top: 2mm;
            border-top: 1px dashed var(--tag-line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 2mm;
            font-size: 1.6mm;
            color: var(--tag-muted);
            flex-shrink: 0;
        }
        .tag-footer .scan {
            font-weight: 800;
            color: var(--tag-ink);
            letter-spacing: 0.08em;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .tag-footer .note {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .slot-empty {
            border: 1px dashed #e2e8f0;
            border-radius: 4px;
            background: #f8fafc;
        }

        @page {
            size: A4 portrait;
            margin: 7mm;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .print-actions, .hint { display: none !important; }
            .a4-page {
                width: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
                box-shadow: none;
                page-break-after: always;
                break-after: page;
            }
            .a4-page:last-child {
                page-break-after: auto;
                break-after: auto;
            }
            .a4-grid {
                height: 283mm;
                gap: 4mm;
            }
            .industrial-tag {
                border-color: #000;
                box-shadow: none;
            }
            .slot-empty {
                border-color: transparent;
                background: transparent;
            }
        }
    </style>
</head>
<body>

<div class="print-actions">
    <div>
        <a href="import_export.php" class="btn btn-secondary">&larr; Import / Export</a>
        <div class="meta">
            <strong><?= (int)$total ?></strong> stickers ·
            <strong><?= count($pages) ?></strong> A4 ·
            profile-style card · 6 / page
        </div>
    </div>
    <?php if ($total > 0): ?>
    <button type="button" onclick="window.print()" class="btn btn-primary">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 6 2 18 2 18 9"/>
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
            <rect x="6" y="14" width="12" height="8"/>
        </svg>
        <span>Print / Save PDF</span>
    </button>
    <?php endif; ?>
</div>

<?php if ($total > 0): ?>
<div class="hint">
    Same layout as profile → <strong>Print QR Image</strong> (logo, ID, QR, Store / Outlet / Model / Installed / Capacity). 6 cards per A4.
</div>
<?php endif; ?>

<?php if ($total === 0): ?>
    <div class="empty">
        <h2 style="margin:0 0 0.5rem; color:#0f172a;">No freezers found</h2>
        <p style="margin:0;">Import hub data first, or clear filters.</p>
    </div>
<?php else: ?>
    <?php foreach ($pages as $pageRows): ?>
        <?php
        while (count($pageRows) < 6) {
            $pageRows[] = null;
        }
        ?>
        <div class="a4-page">
            <div class="a4-grid">
                <?php foreach ($pageRows as $freezer): ?>
                    <?php if ($freezer === null): ?>
                        <div class="slot-empty" aria-hidden="true"></div>
                    <?php else: ?>
                        <div class="industrial-tag">
                            <header class="tag-header">
                                <img src="assets/img/logo.png" alt="ReeferON Logo">
                                <div class="tag-brand">
                                    <h1>ReeferON RaaS</h1>
                                    <p>Asset identification tag</p>
                                </div>
                                <span class="tag-type"><?= htmlspecialchars($freezer['freezer_type']) ?></span>
                            </header>

                            <section class="tag-id-block">
                                <span class="tag-id-label">Unique Freezer ID</span>
                                <div class="tag-id-value"><?= htmlspecialchars($freezer['unique_freezer_id']) ?></div>
                            </section>

                            <div class="tag-body">
                                <div class="tag-qr">
                                    <?php if (!empty($freezer['qr_code_path'])): ?>
                                        <img src="<?= htmlspecialchars($freezer['qr_code_path']) ?>" alt="QR Code">
                                    <?php endif; ?>
                                </div>

                                <div class="tag-meta">
                                    <div class="meta-row">
                                        <span class="meta-label">Store</span>
                                        <span class="meta-value"><?= htmlspecialchars($freezer['store_name']) ?></span>
                                    </div>
                                    <div class="meta-row">
                                        <span class="meta-label">Outlet</span>
                                        <span class="meta-value mono"><?= htmlspecialchars($freezer['outlet_id']) ?> · <?= htmlspecialchars($freezer['store_city']) ?></span>
                                    </div>
                                    <div class="meta-row">
                                        <span class="meta-label">Model</span>
                                        <span class="meta-value mono"><?= htmlspecialchars($freezer['model_number']) ?></span>
                                    </div>
                                    <div class="meta-row">
                                        <span class="meta-label">Installed</span>
                                        <span class="meta-value mono"><?= htmlspecialchars($freezer['install_ddm']) ?></span>
                                    </div>
                                    <?php if (AppConfig::IOT_ENABLED): ?>
                                    <div class="meta-row">
                                        <span class="meta-label">IoT Serial</span>
                                        <span class="meta-value mono"><?= htmlspecialchars($freezer['device_serial'] ?? '—') ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="meta-row">
                                        <span class="meta-label">Capacity</span>
                                        <span class="meta-value"><?= (int)($freezer['capacity_litres'] ?? 550) ?> L</span>
                                    </div>
                                </div>
                            </div>

                            <footer class="tag-footer">
                                <span class="note">Property of ReeferON RaaS · Tampering prohibited</span>
                                <span class="scan">SCAN FOR SERVICE</span>
                            </footer>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
