<?php
/**
 * Blank / assigned asset-tag landing page.
 * Printed QR always points here: tag.php?t=RO-XXXXXX
 * - unused  → open Create Profile bound to this tag
 * - assigned → open freezer profile details
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\AssetTag;
use App\Models\Freezer;

$tagCode = strtoupper(trim((string)($_GET['t'] ?? '')));
if ($tagCode === '') {
    http_response_code(400);
    die('Missing tag code.');
}

$tag = AssetTag::findByCode($tagCode);
if (!$tag) {
    http_response_code(404);
    die('Unknown QR tag: ' . htmlspecialchars($tagCode));
}

if ($tag['status'] === 'assigned' && !empty($tag['freezer_id'])) {
    $freezer = Freezer::findById((int)$tag['freezer_id']);
    if ($freezer) {
        header('Location: profile_view.php?id=' . urlencode($freezer['unique_freezer_id']));
        exit;
    }
}

// Unused (or assigned but freezer missing) → fill data after login
AuthService::requireLogin();
if ($tag['status'] === 'assigned') {
    // Orphaned assignment — still allow view attempt via message on add
    header('Location: profile_add.php?tag=' . urlencode($tagCode) . '&err=assigned');
    exit;
}

header('Location: profile_add.php?tag=' . urlencode($tagCode));
exit;
