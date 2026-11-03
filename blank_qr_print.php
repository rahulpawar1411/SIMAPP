<?php
/**
 * Print sheet for blank asset tags — Logo + QR only.
 * Layout: A4 portrait, exactly 4 stickers per page (2×2).
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\AssetTag;

AuthService::requireLogin();

$tags = [];
if (!empty($_GET['ids'])) {
    $ids = array_map('intval', explode(',', (string)$_GET['ids']));
    $tags = AssetTag::findByIds($ids);
} else {
    $status = ($_GET['status'] ?? 'unused') === 'assigned' ? 'assigned' : 'unused';
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 20)));
    $tags = AssetTag::listTags($status, $limit, 0);
    $tags = array_reverse($tags);
}

if (!$tags) {
    die('No blank QR tags to print.');
}

$pages = array_chunk($tags, 4);
$pageCount = count($pages);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Blank QR Tags | ReeferON</title>
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
            <?= count($tags) ?> tag(s) · <?= $pageCount ?> A4 page(s) ·
            <strong>4 stickers / page</strong> (Logo + QR only)
        </p>
        <div style="display:flex; gap:0.5rem;">
            <button type="button" class="btn btn-primary" onclick="window.print()">Print A4</button>
            <a href="blank_qr.php" class="btn btn-secondary">Back</a>
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
