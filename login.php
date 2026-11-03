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
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>Sign In | ReeferON DF</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?: time() ?>">
    <style>
        .login-wrap {
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            background: #0b1f3a;
        }
        .login-hero {
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 2.5rem;
            background:
                linear-gradient(160deg, rgba(11, 31, 58, 0.55) 0%, rgba(37, 99, 235, 0.55) 55%, rgba(11, 31, 58, 0.85) 100%),
                url('https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=1400&q=80') center/cover no-repeat;
            color: #fff;
        }
        .login-hero h1 {
            font-size: clamp(1.6rem, 3vw, 2.35rem);
            font-weight: 800;
            letter-spacing: -0.03em;
            max-width: 16ch;
            line-height: 1.15;
            margin-bottom: 0.65rem;
        }
        .login-hero p {
            font-size: 0.95rem;
            color: rgba(255,255,255,0.85);
            max-width: 34ch;
            line-height: 1.5;
        }
        .login-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(1.25rem, env(safe-area-inset-top)) 1.25rem max(1.25rem, env(safe-area-inset-bottom));
            background: #f1f5f9;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 20px;
            padding: 1.75rem 1.5rem;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.1);
            border: 1px solid #e2e8f0;
        }
        .login-card .form-control {
            font-size: 16px;
            min-height: 48px;
            border-radius: 12px;
        }
        .login-card .btn {
            min-height: 50px;
            width: 100%;
            justify-content: center;
            border-radius: 12px;
            font-weight: 700;
        }
        .login-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .login-brand img {
            height: 44px;
            width: auto;
        }
        .demo-roles-box {
            margin-top: 1.25rem;
            padding: 0.85rem 1rem;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px dashed #cbd5e1;
            font-size: 0.8rem;
        }
        .demo-pill {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            cursor: pointer;
            margin: 0.2rem 0.1rem;
            font-weight: 700;
        }
        @media (max-width: 900px) {
            .login-wrap { grid-template-columns: 1fr; }
            .login-hero {
                min-height: 220px;
                padding: 1.5rem;
                justify-content: flex-end;
            }
            .login-hero h1 { font-size: 1.45rem; max-width: none; }
        }
    </style>
</head>
<body>
<div class="login-wrap">
    <section class="login-hero" aria-hidden="true">
        <div>
            <div style="font-size:0.75rem; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; opacity:0.85; margin-bottom:0.75rem;">ReeferON DF</div>
            <h1>Deep Freezer Asset Management</h1>
            <p>Scan QR tags, register freezers, and complete KYC — built for cold-chain field teams.</p>
        </div>
    </section>

    <section class="login-panel">
        <div class="login-card">
            <div class="login-brand">
                <img src="assets/img/logo.png" alt="ReeferON">
                <div>
                    <div style="font-weight:800; font-size:1.15rem; color:#0f172a;">Sign in</div>
                    <div style="font-size:0.8rem; color:#64748b;">Technician / Admin access</div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom: 1.25rem;">
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group" style="margin-bottom: 1.1rem;">
                    <label class="form-label" for="email">Employee ID / Email</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="name@reeferon.com" required autofocus>
                </div>

                <div class="form-group" style="margin-bottom: 1.35rem;">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn btn-primary">Login</button>
            </form>

            <div class="demo-roles-box">
                <div style="font-weight: 700; color: #334155; margin-bottom: 0.35rem;">Demo login</div>
                <span class="demo-pill" onclick="fillCreds('admin@reeferon.com', 'Admin@123')">Super Admin</span>
            </div>
        </div>
    </section>
</div>

<script>
function fillCreds(email, pass) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = pass;
}
</script>
</body>
</html>
