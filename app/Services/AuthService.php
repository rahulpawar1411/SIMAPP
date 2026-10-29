<?php
namespace App\Services;

use App\Config\Database;
use App\Config\AppConfig;
use PDO;

class AuthService {

    public static function startSession(): void {
        if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }

    public static function login(string $email, string $password): array {
        self::startSession();
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `email` = :email AND `status` = 'active' LIMIT 1");
        $stmt->execute(['email' => trim($email)]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Invalid email address or password.'];
        }

        // Update last login
        $upd = $pdo->prepare("UPDATE `users` SET `last_login` = NOW() WHERE `id` = :id");
        $upd->execute(['id' => $user['id']]);

        // Regenerate session ID for security
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];

        return ['success' => true, 'user' => $user];
    }

    public static function logout(): void {
        self::startSession();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    public static function currentUser(): ?array {
        self::startSession();
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? 'User',
            'email' => $_SESSION['user_email'] ?? '',
            'role' => $_SESSION['user_role'] ?? AppConfig::ROLE_VIEWER,
        ];
    }

    public static function isLoggedIn(): bool {
        self::startSession();
        return !empty($_SESSION['user_id']);
    }

    public static function hasRole(array|string $roles): bool {
        $user = self::currentUser();
        if (!$user) {
            return false;
        }
        $allowed = is_array($roles) ? $roles : [$roles];
        // Super admin has permission for all role checks
        if ($user['role'] === AppConfig::ROLE_SUPER_ADMIN) {
            return true;
        }
        return in_array($user['role'], $allowed, true);
    }

    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            $currentUrl = $_SERVER['REQUEST_URI'] ?? 'index.php';
            header('Location: login.php?redirect=' . urlencode($currentUrl));
            exit;
        }
    }

    public static function requireRole(array|string $roles): void {
        self::requireLogin();
        if (!self::hasRole($roles)) {
            http_response_code(403);
            die("<h1>403 Forbidden</h1><p>You do not have permission to access this resource.</p><p><a href='index.php'>Return to Dashboard</a></p>");
        }
    }

    public static function generateCsrfToken(): string {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrfToken(?string $token): bool {
        self::startSession();
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
