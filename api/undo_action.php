<?php
/**
 * Undo a recent action from the toast Undo button.
 * Expects JSON or form POST with token + csrf_token.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AuthService;
use App\Services\AppLogger;
use App\Services\UndoService;

try {
    AuthService::requireLogin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if ($raw !== false && $raw !== '' && !is_array($json) && empty($_POST)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request body']);
        exit;
    }
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
        AppLogger::failed('undo.apply', (string)($result['error'] ?? 'Undo failed'));
    }
    echo json_encode($result);
} catch (Throwable $e) {
    AppLogger::exception($e, 'api.undo_action');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => AppLogger::friendlyMessage($e, 'Undo failed. Please try again.'),
    ]);
}
