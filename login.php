<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;

AuthService::startSession();

$error = null;
$redirect = $_GET['redirect'] ?? 'index.php';

if (AuthService::isLoggedIn()) {
    header("Location: {$redirect}");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $res = AuthService::login($email, $password);
    if ($res['success']) {
        header("Location: {$redirect}");
        exit;
    } else {
        $error = $res['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | ReeferON SIM Asset System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: radial-gradient(circle at 10% 20%, #0f172a 0%, #020617 90%);
        }
        .login-card {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border-radius: var(--radius-xl);
            padding: 2.25rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #0284c7, #06b6d4);
            border-radius: var(--radius-md);
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 10px 20px rgba(6, 182, 212, 0.3);
        }
        .demo-roles-box {
            margin-top: 1.5rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: var(--radius-md);
            border: 1px dashed var(--border-color);
            font-size: 0.8rem;
        }
        .demo-pill {
            display: inline-block;
            background: #e2e8f0;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            margin: 0.2rem 0.1rem;
            font-weight: 600;
            color: #0f172a;
        }
        .demo-pill:hover {
            background: #0284c7;
            color: #ffffff;
        }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-header">
            <img src="assets/img/logo.png" alt="ReeferON Logo" style="height: 68px; width: auto; object-fit: contain; margin-bottom: 0.75rem; filter: drop-shadow(0 4px 12px rgba(2, 132, 199, 0.3)); display: block; margin-left: auto; margin-right: auto;">
            <h2 style="font-size: 1.4rem; font-weight: 800; color: #0f172a;">ReeferON SIM Hub</h2>
            <p style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">Freezer & Store Profile Management System</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 1.25rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group" style="margin-bottom: 1.1rem;">
                <label class="form-label" for="email">Work Email Address</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="name@reeferon.com" required autofocus>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem;">
                Sign In to SIM Hub
            </button>
        </form>

        <div class="demo-roles-box">
            <div style="font-weight: 700; color: #334155; margin-bottom: 0.4rem;">Quick Demo Login (Click to autofill):</div>
            <div>
                <span class="demo-pill" onclick="fillCreds('admin@reeferon.com', 'Admin@123')">Super Admin</span>
            </div>
            <div style="color: #94a3b8; font-size: 0.725rem; margin-top: 0.4rem;">Password: <code style="font-weight: bold; color: #0284c7;">Admin@123</code></div>
        </div>
    </div>
</div>

<script>
function fillCreds(email, pass) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = pass;
}
</script>
</body>
</html>
