<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\Freezer;
use App\Models\FreezerLifecycle;

AuthService::requireLogin();

$idParam = $_GET['id'] ?? null;
if (!$idParam) {
    http_response_code(400);
    exit('Missing id');
}

if (is_numeric($idParam)) {
    $freezer = Freezer::findById((int)$idParam);
} else {
    $freezer = Freezer::findByUniqueId($idParam);
}

if (!$freezer) {
    http_response_code(404);
    exit('Freezer not found');
}

FreezerLifecycle::ensureOpenPlacement($freezer);
$sheet = strtolower(trim((string)($_GET['sheet'] ?? 'all')));
$format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'xlsx'], true)) {
    $format = 'csv';
}
FreezerLifecycle::streamHistoryExport($freezer, $sheet, $format);
exit;
