<?php
/**
 * Print one freezer sticker (logo + QR + tag code).
 * Technicians may print only freezers inside their assigned zone/city.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\Freezer;
use App\Models\AssetTag;
use App\Services\QRCodeService;

AuthService::requireLogin();

$idParam = $_GET['id'] ?? null;
if ($idParam === null || $idParam === '') {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Missing ID</title></head><body>'
        . '<h1>Missing freezer ID</h1>'
        . '<p>Open a freezer profile first, then use Print QR.</p>'
        . '<p><a href="profiles.php">Back to Assets</a></p></body></html>';
    exit;
}

// Accept numeric DB id or Unique Freezer ID string
try {
    if (is_numeric($idParam)) {
        $freezer = Freezer::findById((int)$idParam);
    } else {
        $freezer = Freezer::findByUniqueId((string)$idParam);
    }
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'qr_print.load');
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title></head><body>'
        . '<h1>Could not load freezer</h1>'
        . '<p>Please try again. If this keeps happening, contact admin.</p>'
        . '<p><a href="profiles.php">Back to Assets</a></p></body></html>';
    exit;
}

if (!$freezer) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Not found</title></head><body>'
        . '<h1>Freezer not found</h1>'
        . '<p>No profile matches this ID.</p>'
        . '<p><a href="profiles.php">Back to Assets</a></p></body></html>';
    exit;
}

// Block out-of-area print for scoped technicians
AuthService::requireAreaAccess(
    $freezer['store_zone'] ?? $freezer['zone'] ?? null,
    $freezer['store_city'] ?? null,
    'profiles.php'
);

// Sticker shows asset tag (RO-…) — create one if the profile has none yet
$user = AuthService::currentUser();
try {
    $assetTag = AssetTag::ensureForFreezer((int)$freezer['id'], isset($user['id']) ? (int)$user['id'] : null);
    $tagCode = (string)($assetTag['tag_code'] ?? '');
    $qrPath = $assetTag['qr_code_path'] ?? ($freezer['qr_code_path'] ?? null);
    if (!$qrPath || !is_file(__DIR__ . '/' . $qrPath)) {
        $gen = QRCodeService::generateAndSaveTag($tagCode);
        $qrPath = $gen['file_path'] ?? null;
    }
} catch (Throwable $e) {
    \App\Services\AppLogger::exception($e, 'qr_print.tag');
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>QR error</title></head><body>'
        . '<h1>Could not prepare QR sticker</h1>'
        . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="profile_view.php?id=' . (int)$freezer['id'] . '">Back to profile</a></p></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print QR | <?= htmlspecialchars($tagCode ?: $freezer['unique_freezer_id']) ?></title>
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

        /* Same cell size as blank A4 2×2 sticker */
        .a4-page {
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
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
        .blank-tag .meta-label {
            margin-top: 2.5mm;
            font-size: 2.4mm;
            font-weight: 700;
            color: #94a3b8;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .blank-tag .tag-code {
            margin-top: 1mm;
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

        @page { size: A4 portrait; margin: 0; }
        @media print {
            html, body { background: #fff; padding: 0; margin: 0; width: 210mm; }
            .print-actions { display: none !important; }
            .a4-page { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="print-actions">
        <p>
            Same hub sticker style · Logo + QR + code only
            <?php if ($assetTag): ?>
                · <code><?= htmlspecialchars($assetTag['tag_code']) ?></code>
            <?php endif; ?>
        </p>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <a href="profile_view.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-secondary">&larr; Profile</a>
            <button type="button" class="btn btn-primary" onclick="window.print()">Print A4</button>
        </div>
    </div>

    <div class="a4-page">
        <div class="blank-tag">
            <img class="logo" src="assets/img/logo.png" alt="ReeferON">
            <?php if ($qrPath): ?>
                <img class="qr" src="<?= htmlspecialchars($qrPath) ?>" alt="QR">
            <?php else: ?>
                <div style="width:68mm;height:68mm;background:#f1f5f9;"></div>
            <?php endif; ?>
            <div class="meta-label">Tag code</div>
            <div class="tag-code"><?= htmlspecialchars($tagCode) ?></div>
            <div class="freezer-id-label">Unique Freezer ID · Registered</div>
            <div class="freezer-id"><?= htmlspecialchars($freezer['unique_freezer_id']) ?></div>
            <div class="hint">Scan for details</div>
        </div>
        <div class="blank-tag empty" aria-hidden="true"></div>
        <div class="blank-tag empty" aria-hidden="true"></div>
        <div class="blank-tag empty" aria-hidden="true"></div>
    </div>
</body>
</html>
