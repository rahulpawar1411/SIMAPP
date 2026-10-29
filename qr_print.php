<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;
use App\Models\Freezer;
use App\Services\QRCodeService;

AuthService::requireLogin();

$idParam = $_GET['id'] ?? null;
if (!$idParam) {
    die("Error: Missing freezer identifier.");
}

if (is_numeric($idParam)) {
    $freezer = Freezer::findById((int)$idParam);
} else {
    $freezer = Freezer::findByUniqueId($idParam);
}

if (!$freezer) {
    die("Error: Freezer profile not found.");
}

$qrPath = $freezer['qr_code_path'];
if (!$qrPath || !file_exists(__DIR__ . '/' . $qrPath)) {
    $gen = QRCodeService::generateAndSave($freezer['unique_freezer_id']);
    $qrPath = $gen['file_path'];
}

$installDdm = !empty($freezer['installation_date'])
    ? date('dm', strtotime($freezer['installation_date']))
    : ($freezer['install_month_year'] ?? '—');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Asset Tag | <?= htmlspecialchars($freezer['unique_freezer_id']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@500;700;800&family=JetBrains+Mono:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
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
            padding: 2rem 1rem;
            font-family: 'DM Sans', system-ui, sans-serif;
            color: var(--tag-ink);
        }
        .print-actions {
            max-width: 620px;
            margin: 0 auto 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
        }
        .industrial-tag {
            max-width: 620px;
            margin: 0 auto;
            background: #fff;
            border: 2.5px solid var(--tag-ink);
            border-radius: 4px;
            padding: 1.35rem 1.5rem 1.1rem;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
        }

        .tag-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--tag-ink);
            margin-bottom: 1rem;
        }
        .tag-header img {
            height: 68px;
            width: auto;
            object-fit: contain;
            flex-shrink: 0;
        }
        .tag-brand {
            flex: 1;
            min-width: 0;
        }
        .tag-brand h1 {
            margin: 0;
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--tag-blue);
            letter-spacing: -0.03em;
            line-height: 1.1;
            white-space: nowrap;
        }
        .tag-brand p {
            margin: 0.2rem 0 0;
            font-size: 0.68rem;
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
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            padding: 0.4rem 0.65rem;
            border-radius: 3px;
            white-space: nowrap;
        }

        .tag-id-block {
            margin-bottom: 1.15rem;
            padding-bottom: 0.95rem;
            border-bottom: 1px solid var(--tag-line);
        }
        .tag-id-label {
            display: block;
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--tag-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.25rem;
        }
        .tag-id-value {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 1.08rem;
            font-weight: 800;
            color: var(--tag-ink);
            white-space: nowrap;
            letter-spacing: -0.03em;
            line-height: 1.25;
        }

        .tag-body {
            display: flex;
            gap: 1.35rem;
            align-items: stretch;
        }
        .tag-qr {
            width: 168px;
            height: 168px;
            flex-shrink: 0;
            border: 2px solid var(--tag-ink);
            border-radius: 3px;
            padding: 6px;
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
            gap: 0.55rem;
        }
        .meta-row {
            display: flex;
            align-items: baseline;
            gap: 0.55rem;
            white-space: nowrap;
            overflow: hidden;
            min-width: 0;
        }
        .meta-label {
            flex-shrink: 0;
            width: 5.4rem;
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--tag-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .meta-value {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--tag-ink);
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
        }
        .meta-value.mono {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.82rem;
            letter-spacing: -0.02em;
        }

        .tag-footer {
            margin-top: 1.1rem;
            padding-top: 0.7rem;
            border-top: 1px dashed var(--tag-line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            font-size: 0.68rem;
            color: var(--tag-muted);
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

        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .print-actions { display: none !important; }
            .industrial-tag {
                box-shadow: none;
                max-width: 100%;
                width: 100%;
                border-color: #000;
            }
        }
    </style>
</head>
<body>

<div class="print-actions">
    <a href="profile_view.php?id=<?= urlencode($freezer['unique_freezer_id']) ?>" class="btn btn-secondary">&larr; Return to Profile</a>
    <button type="button" onclick="window.print()" class="btn btn-primary">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 6 2 18 2 18 9"/>
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
            <rect x="6" y="14" width="12" height="8"/>
        </svg>
        <span>Print QR Image</span>
    </button>
</div>

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
            <img src="<?= htmlspecialchars($qrPath) ?>" alt="QR Code">
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
                <span class="meta-value mono"><?= htmlspecialchars($installDdm) ?></span>
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

</body>
</html>
