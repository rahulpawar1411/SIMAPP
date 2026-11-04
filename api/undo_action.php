<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AuthService;
use App\Services\UndoService;

AuthService::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$raw = file_get_contents('php://input');
$json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$data = is_array($json) ? $json : $_POST;

if (!AuthService::validateCsrfToken($data['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$token = trim((string)($data['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Undo token required']);
    exit;
}

$result = UndoService::apply($token);
if (empty($result['success'])) {
    http_response_code(400);
}
echo json_encode($result);
