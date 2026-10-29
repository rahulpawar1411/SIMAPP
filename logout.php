<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;

AuthService::logout();
header('Location: login.php');
exit;
