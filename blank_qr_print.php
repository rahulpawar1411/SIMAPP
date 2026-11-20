<?php
/**
 * Print blank asset tags (logo + QR only).
 * A4 portrait, 4 stickers per page (2×2).
 * Technicians with a zone assignment only print tags for that zone.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\AssetTag;

AuthService::requireLogin();
AuthService::requireRole(['super_admin', 'technician']);
AuthService::requirePermission('create');

$zoneOptions = AssetTag::zoneTagCodes();
$filterZone = strtoupper(trim((string)($_GET['zone'] ?? '')));
if ($filterZone === 'ALL') {
    $filterZone = '';
}

// Force technician print filter to their assigned zone (ignore other zone query params)
$areaScope = AuthService::areaScope();
if (($areaScope['zone'] ?? '') !== '') {
    $filterZone = strtoupper((string)$areaScope['zone']);
}
if ($filterZone !== '' && !isset($zoneOptions[$filterZone])) {
    $filterZone = '';
}

$zoneLabel = $filterZone !== '' ? ucfirst(strtolower($filterZone)) : 'All zones';
$statusRawLabel = strtolower(trim((string)($_GET['status'] ?? 'unused')));
$statusLabel = $statusRawLabel === 'all' ? 'All' : ($statusRawLabel === 'assigned' ? 'Assigned' : 'Unused');

$tags = [];
try {
    if (!empty($_GET['ids'])) {
        $ids = array_map('intval', explode(',', (string)$_GET['ids']));
        $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
        $tags = $ids !== [] ? AssetTag::findByIds($ids) : [];

        // Drop any selected tags outside the allowed zone
        if ($filterZone !== '') {
            $prefix = 'RO-' . AssetTag::zoneToTagCode($filterZone) . '-';
            $tags = array_values(array_filter($tags, static function ($t) use ($prefix) {
                return stripos((string)($t['tag_code'] ?? ''), $prefix) === 0;
            }));
        }
    } else {
        $statusRaw = strtolower(trim((string)($_GET['status'] ?? 'unused')));
        if ($statusRaw === 'all') {
            $status = '';
        } elseif ($statusRaw === 'assigned') {
            $status = 'assigned';
        } else {
            $status = 'unused';
        }
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 20)));
        $tags = AssetTag::listTags($status, $limit, 0, $filterZone);
        $tags = array_reverse($tags);
    }
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'blank_qr_print.load');
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Print error</title></head><body>'
        . '<h1>Could not load tags to print</h1>'
        . '<p>Please go back and try again.</p>'
        . '<p><a href="blank_qr.php">Back to QR Management</a></p></body></html>';
    exit;
}

if (!$tags) {
    http_response_code(404);
    $extra = $filterZone !== '' ? ' for ' . htmlspecialchars($zoneLabel, ENT_QUOTES, 'UTF-8') : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Nothing to print</title></head><body>'
        . '<h1>No blank QR tags to print' . $extra . '</h1>'
        . '<p>Generate a batch in QR Management first, or change the filter.</p>'
        . '<p><a href="blank_qr.php' . ($filterZone !== '' ? ('?zone=' . urlencode($filterZone)) : '') . '">Back to QR Management</a></p>'
        . '</body></html>';
    exit;
}

$pages = array_chunk($tags, 4);
$pageCount = count($pages);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Blank QR · <?= htmlspecialchars($zoneLabel) ?> | ReeferON</title>
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
        }
        .print-actions p {
            margin: 0;
            font-size: 0.85rem;
            color: #64748b;
        }
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

        /* A4 page — 4 stickers (2×2) */
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
        }
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
            page-break-inside: avoid;
            break-inside: avoid;
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
            font-family: ui-monospace, 'JetBrains Mono', monospace;
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
            font-family: ui-monospace, 'JetBrains Mono', monospace;
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
        .blank-tag .qr-missing {
            width: 68mm;
            height: 68mm;
            margin: 0 auto;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3mm;
            color: #94a3b8;
        }

        @page {
            size: A4 portrait;
            margin: 0;
        }
        @media print {
            html, body {
                background: #fff;
                padding: 0;
                margin: 0;
                width: 210mm;
            }
            .print-actions { display: none !important; }
            .a4-page {
                margin: 0;
                box-shadow: none;
                page-break-after: always;
                break-after: page;
            }
            .a4-page:last-child {
                page-break-after: auto;
                break-after: auto;
            }
        }
    </style>
</head>
<body>
    <div class="print-actions">
        <p>
            <strong><?= htmlspecialchars($statusLabel) ?></strong>
            · <strong><?= htmlspecialchars($zoneLabel) ?></strong>
            · <?= count($tags) ?> tag(s) · <?= $pageCount ?> A4 page(s) ·
            <strong>4 stickers / page</strong> (Logo + QR only)
        </p>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <button type="button" class="btn btn-primary" onclick="window.print()">Print A4</button>
            <a href="blank_qr.php<?= $filterZone !== '' ? ('?zone=' . urlencode($filterZone)) : '' ?>" class="btn btn-secondary">Back</a>
        </div>
    </div>

    <?php foreach ($pages as $pageTags): ?>
        <div class="a4-page">
            <?php for ($i = 0; $i < 4; $i++): ?>
                <?php if (!empty($pageTags[$i])): ?>
                    <?php
                    $tag = $pageTags[$i];
                    $qrPath = $tag['qr_code_path'] ?? '';
                    $qrSrc = ($qrPath && file_exists(__DIR__ . '/' . $qrPath)) ? $qrPath : '';
                    ?>
                    <div class="blank-tag">
                        <img class="logo" src="assets/img/logo.png" alt="ReeferON">
                        <?php if ($qrSrc): ?>
                            <img class="qr" src="<?= htmlspecialchars($qrSrc) ?>" alt="QR">
                        <?php else: ?>
                            <div class="qr-missing">QR missing</div>
                        <?php endif; ?>
                        <div class="meta-label" style="margin-top:2.5mm;font-size:2.4mm;font-weight:700;color:#94a3b8;letter-spacing:0.08em;text-transform:uppercase;">Tag code</div>
                        <div class="tag-code"><?= htmlspecialchars($tag['tag_code']) ?></div>
                        <?php if (!empty($tag['unique_freezer_id'])): ?>
                        <div class="freezer-id-label">Unique Freezer ID · Registered</div>
                        <div class="freezer-id"><?= htmlspecialchars($tag['unique_freezer_id']) ?></div>
                        <div class="hint">Scan for details</div>
                        <?php else: ?>
                        <div class="hint">Scan to register</div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="blank-tag empty" aria-hidden="true"></div>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>
