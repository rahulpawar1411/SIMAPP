<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AuthService;
use App\Config\AppConfig;

AuthService::startSession();

$error = null;
$redirect = $_GET['redirect'] ?? 'index.php';

/** Role home: admin → Dashboard, technician → QR Management, viewer → Assets. */
$resolveHome = static function (string $target): string {
    $path = parse_url($target, PHP_URL_PATH) ?: $target;
    $base = basename($path);
    if (!in_array($base, ['index.php', '', '/'], true)) {
        return $target;
    }
    if (AuthService::hasRole(AppConfig::ROLE_SUPER_ADMIN)) {
        return 'index.php';
    }
    if (AuthService::hasRole(AppConfig::ROLE_TECHNICIAN)) {
        return 'blank_qr.php';
    }
    return 'profiles.php';
};

if (AuthService::isLoggedIn()) {
    header('Location: ' . $resolveHome($redirect));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $res = AuthService::login($email, $password);
    if ($res['success']) {
        header('Location: ' . $resolveHome($redirect));
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
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (@filemtime(__DIR__ . '/assets/css/app.css') ?: time()) ?>-theme-blue">
    <style>
        /* Login — blue theme (not app green) */
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --primary-dark: #1e3a8a;
            --info: #2563eb;
            --accent: #38bdf8;
        }
        /* Login is standalone — ignore app shell zoom / field-nav bottom padding */
        html {
            height: 100%;
            zoom: 1 !important;
        }
        html, body {
            margin: 0;
            padding: 0 !important;
            min-height: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
        }
        body {
            display: block !important;
            background: #0b1f3a;
            padding-bottom: 0 !important;
        }
        .login-wrap {
            min-height: 100vh;
            min-height: 100dvh;
            height: 100%;
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
        .password-field {
            position: relative;
        }
        .password-field .form-control {
            padding-right: 3rem;
        }
        .password-toggle {
            position: absolute;
            right: 0.45rem;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border: none;
            background: transparent;
            color: #64748b;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .password-toggle:hover,
        .password-toggle:focus-visible {
            color: #0f172a;
            background: #f1f5f9;
            outline: none;
        }
        .password-toggle svg {
            width: 20px;
            height: 20px;
            display: block;
        }
        .password-toggle .icon-eye-off { display: none; }
        .password-toggle.is-visible .icon-eye { display: none; }
        .password-toggle.is-visible .icon-eye-off { display: block; }
        .login-card .btn {
            min-height: 50px;
            width: 100%;
            justify-content: center;
            border-radius: 12px;
            font-weight: 700;
        }
        .login-card .btn-primary {
            background-color: #2563eb !important;
            border-color: #2563eb !important;
            color: #fff !important;
        }
        .login-card .btn-primary:hover,
        .login-card .btn-primary:focus {
            background-color: #1d4ed8 !important;
            border-color: #1d4ed8 !important;
        }
        .login-card .form-control:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2) !important;
        }
        .login-card .form-label.required::after,
        .login-card .form-label .required {
            color: #2563eb;
        }
        .login-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .login-brand img {
            height: 64px;
            width: auto;
            max-width: 80px;
            object-fit: contain;
            display: block;
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
            background: #dbeafe;
            color: #1d4ed8;
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            cursor: pointer;
            margin: 0.2rem 0.1rem;
            font-weight: 700;
        }
        .demo-pill:hover {
            background: #bfdbfe;
            color: #1e40af;
        }
        @media (max-width: 900px) {
            .login-wrap {
                grid-template-columns: 1fr;
                grid-template-rows: auto 1fr;
                min-height: 100vh;
                min-height: 100dvh;
            }
            .login-hero {
                min-height: 180px;
                max-height: 32vh;
                padding: 1.25rem 1.25rem 1.1rem;
                justify-content: flex-end;
            }
            .login-hero h1 { font-size: 1.35rem; max-width: none; }
            .login-panel {
                align-items: flex-start;
                padding: 1rem 1rem max(1rem, env(safe-area-inset-bottom));
            }
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
                    <div class="password-field">
                        <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                        <button type="button" class="password-toggle" id="password-toggle" aria-label="Show password" aria-pressed="false" title="Show password">
                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
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

(function () {
    var input = document.getElementById('password');
    var btn = document.getElementById('password-toggle');
    if (!input || !btn) return;
    btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.classList.toggle('is-visible', show);
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.title = show ? 'Hide password' : 'Show password';
    });
})();
</script>
</body>
</html>
