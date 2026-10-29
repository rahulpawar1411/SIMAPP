<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Models\Freezer;

AuthService::requireRole('super_admin');

$id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    Freezer::delete($id);
}

header("Location: profiles.php?deleted=1");
exit;
