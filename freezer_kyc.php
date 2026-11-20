<?php
/**
 * Legacy AMC entry — redirects to the same store-registration form in AMC mode.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Freezer;
use App\Services\AuthService;
use App\Services\KycScanGate;

AuthService::requireRole(['super_admin', 'technician']);
AuthService::requirePermission('update');

$id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$from = (!empty($_GET['from']) && $_GET['from'] === 'profile') ? '&from=profile' : '';
$freezer = $id > 0 ? Freezer::findById($id) : null;

if (!$freezer) {
    http_response_code(404);
    echo '<h1>Freezer not found</h1><p><a href="profiles.php">Back to Assets</a></p>';
    exit;
}

if ($from !== '') {
    KycScanGate::markFromProfile((int)$freezer['id']);
}

header('Location: profile_add.php?amc=1&id=' . (int)$freezer['id'] . $from);
exit;
