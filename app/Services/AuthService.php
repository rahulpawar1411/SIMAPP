<?php
namespace App\Services;

use App\Config\Database;
use App\Config\AppConfig;
use App\Models\User;
use PDO;

/**
 * Login, session, JWT cookie, and role checks.
 * Sensitive actions (edit/delete user) re-check the admin password here.
 */
class AuthService {

    /** Start PHP session if the browser request has not started one yet. */
    public static function startSession(): void {
        if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }

    public static function login(string $email, string $password): array {
        self::startSession();
        User::ensureAreaColumns();
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `email` = :email AND `status` = 'active' LIMIT 1");
        $stmt->execute(['email' => trim($email)]);
        $user = $stmt->fetch();

        $hash = (string)($user['password'] ?? '');
        if (!$user || !PasswordService::verify($password, $hash)) {
            AppLogger::failed('auth.login', 'Login failed — invalid credentials', [
                'email' => trim($email),
            ]);
            return ['success' => false, 'error' => 'Invalid email address or password.'];
        }

        $role = (string)($user['role'] ?? '');
        if (!in_array($role, AppConfig::allowedRoles(), true)) {
            AppLogger::failed('auth.login', 'Login denied — role not allowed', [
                'email' => trim($email),
                'role' => $role,
            ]);
            return ['success' => false, 'error' => 'Access denied. This account role is not allowed to sign in.'];
        }

        // Transparent bcrypt rehash if cost/algo outdated
        if (PasswordService::needsRehash($hash)) {
            try {
                $newHash = PasswordService::hash($password);
                $re = $pdo->prepare('UPDATE `users` SET `password` = :p WHERE `id` = :id');
                $re->execute(['p' => $newHash, 'id' => (int)$user['id']]);
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        // Update last login
        $upd = $pdo->prepare("UPDATE `users` SET `last_login` = NOW() WHERE `id` = :id");
        $upd->execute(['id' => $user['id']]);

        // Regenerate session ID for CSRF / undo bag
        session_regenerate_id(true);

        $area = User::normalizeArea($user['zone'] ?? null, $user['city'] ?? null);
        $token = self::issueJwtForUser($user, $area);
        self::hydrateSessionFromUser($user, $area, $token);
        self::setJwtCookie($token);

        AppLogger::info('auth.login', 'Login success (JWT + bcrypt)', [
            'user_id' => (int)$user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'zone' => $area['zone'],
            'city' => $area['city'],
        ]);

        return ['success' => true, 'user' => $user, 'token' => $token];
    }

    public static function logout(): void {
        self::startSession();
        $uid = $_SESSION['user_id'] ?? null;
        $email = $_SESSION['user_email'] ?? null;
        AppLogger::info('auth.logout', 'User logged out', [
            'user_id' => $uid,
            'email' => $email,
        ]);
        $_SESSION = [];
        self::clearJwtCookie();
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
        // Prefer JWT (cookie or Bearer); keep session in sync for CSRF/undo
        $claims = self::readJwtClaims();
        if ($claims) {
            $user = [
                'id' => (int)($claims['uid'] ?? 0),
                'name' => (string)($claims['name'] ?? 'User'),
                'email' => (string)($claims['email'] ?? ''),
                'role' => (string)($claims['role'] ?? AppConfig::ROLE_SUPER_ADMIN),
                'zone' => (string)($claims['zone'] ?? ''),
                'city' => (string)($claims['city'] ?? ''),
            ];
            if ($user['id'] > 0) {
                if (empty($_SESSION['user_id']) || (int)$_SESSION['user_id'] !== $user['id']) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_zone'] = $user['zone'];
                    $_SESSION['user_city'] = $user['city'];
                }
                return $user;
            }
        }

        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? 'User',
            'email' => $_SESSION['user_email'] ?? '',
            'role' => $_SESSION['user_role'] ?? AppConfig::ROLE_SUPER_ADMIN,
            'zone' => (string)($_SESSION['user_zone'] ?? ''),
            'city' => (string)($_SESSION['user_city'] ?? ''),
        ];
    }

    /** Issue HS256 JWT for a user row. */
    public static function issueJwtForUser(array $user, ?array $area = null): string {
        $area = $area ?? User::normalizeArea($user['zone'] ?? null, $user['city'] ?? null);
        return JwtService::encode([
            'uid' => (int)($user['id'] ?? 0),
            'name' => (string)($user['name'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
            'zone' => (string)($area['zone'] ?? ''),
            'city' => (string)($area['city'] ?? ''),
        ]);
    }

    private static function hydrateSessionFromUser(array $user, array $area, string $token): void {
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_zone'] = $area['zone'];
        $_SESSION['user_city'] = $area['city'];
        $_SESSION['jwt'] = $token;
    }

    /** @return array<string,mixed>|null */
    private static function readJwtClaims(): ?array {
        $token = self::extractBearerToken();
        if ($token === '') {
            $token = trim((string)($_COOKIE[AppConfig::JWT_COOKIE] ?? ''));
        }
        if ($token === '' && !empty($_SESSION['jwt'])) {
            $token = trim((string)$_SESSION['jwt']);
        }
        if ($token === '') {
            return null;
        }
        return JwtService::decode($token);
    }

    private static function extractBearerToken(): string {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    if (strcasecmp((string)$k, 'Authorization') === 0) {
                        $header = (string)$v;
                        break;
                    }
                }
            }
        }
        if (preg_match('/^\s*Bearer\s+(\S+)/i', $header, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private static function setJwtCookie(string $token): void {
        if (php_sapi_name() === 'cli' || headers_sent()) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(AppConfig::JWT_COOKIE, $token, [
            'expires' => time() + (int)AppConfig::JWT_TTL_SECONDS,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[AppConfig::JWT_COOKIE] = $token;
    }

    private static function clearJwtCookie(): void {
        if (php_sapi_name() === 'cli' || headers_sent()) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(AppConfig::JWT_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[AppConfig::JWT_COOKIE]);
    }

    /**
     * Assigned zone/city for Technicians only. Empty = no restriction.
     * Viewer & Super Admin always see All areas.
     *
     * @return array{zone:string,city:string}
     */
    public static function areaScope(): array {
        $user = self::currentUser();
        if (!$user || ($user['role'] ?? '') !== AppConfig::ROLE_TECHNICIAN) {
            return ['zone' => '', 'city' => ''];
        }
        static $cached = null;
        static $cachedId = 0;
        $uid = (int)($user['id'] ?? 0);
        if ($cached !== null && $cachedId === $uid) {
            return $cached;
        }
        $row = User::findById($uid);
        $area = User::normalizeArea($row['zone'] ?? null, $row['city'] ?? null);
        $_SESSION['user_zone'] = $area['zone'];
        $_SESSION['user_city'] = $area['city'];
        $cached = $area;
        $cachedId = $uid;
        return $area;
    }

    public static function hasAreaScope(): bool {
        $scope = self::areaScope();
        return $scope['zone'] !== '' || $scope['city'] !== '';
    }

    /**
     * Merge area assignment into Freezer list filters.
     * Zone is a string; city becomes a single-item list when scoped.
     */
    public static function applyAreaToFilters(array $filters): array {
        $scope = self::areaScope();
        if ($scope['zone'] !== '') {
            $filters['zone'] = $scope['zone'];
        }
        if ($scope['city'] !== '') {
            $filters['city'] = [$scope['city']];
        }
        return $filters;
    }

    /** Force store create/edit zone+city to the user's assignment. */
    public static function applyAreaToStoreData(array $data): array {
        $scope = self::areaScope();
        if ($scope['zone'] !== '') {
            $data['zone'] = $scope['zone'];
        }
        if ($scope['city'] !== '') {
            $data['city'] = $scope['city'];
        }
        return $data;
    }

    /** Whether current user may view/edit a store in this zone/city. */
    public static function canAccessArea(?string $zone, ?string $city): bool {
        $scope = self::areaScope();
        if ($scope['zone'] === '' && $scope['city'] === '') {
            return true;
        }
        if ($scope['zone'] !== '' && strtoupper(trim((string)$zone)) !== $scope['zone']) {
            return false;
        }
        if ($scope['city'] !== '' && strcasecmp(trim((string)$city), $scope['city']) !== 0) {
            return false;
        }
        return true;
    }

    public static function isLoggedIn(): bool {
        return self::currentUser() !== null;
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

    /** Verify password for the currently logged-in user (required for sensitive actions). */
    public static function verifyCurrentUserPassword(string $password): bool {
        $user = self::currentUser();
        $password = trim($password);
        if (!$user || $password === '') {
            return false;
        }

        $pdo = Database::getConnection();
        $uid = (int)($user['id'] ?? 0);
        $email = strtolower(trim((string)($user['email'] ?? '')));

        // Resolve live row by id first; fall back to email (stale JWT uid after DB reset).
        $row = null;
        if ($uid > 0) {
            $stmt = $pdo->prepare(
                "SELECT `id`, `password`, `email`, `name`, `role`, `zone`, `city`
                 FROM `users` WHERE `id` = :id AND `status` = 'active' LIMIT 1"
            );
            $stmt->execute(['id' => $uid]);
            $row = $stmt->fetch() ?: null;
        }
        if (!$row && $email !== '') {
            $stmt = $pdo->prepare(
                "SELECT `id`, `password`, `email`, `name`, `role`, `zone`, `city`
                 FROM `users` WHERE LOWER(`email`) = :email AND `status` = 'active' LIMIT 1"
            );
            $stmt->execute(['email' => $email]);
            $row = $stmt->fetch() ?: null;
        }
        if (!$row || empty($row['password'])) {
            return false;
        }

        $hash = (string)$row['password'];
        if (!PasswordService::verify($password, $hash)) {
            return false;
        }

        $liveId = (int)$row['id'];
        if (PasswordService::needsRehash($hash)) {
            try {
                $newHash = PasswordService::hash($password);
                $re = $pdo->prepare('UPDATE `users` SET `password` = :p WHERE `id` = :id');
                $re->execute(['p' => $newHash, 'id' => $liveId]);
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        // Keep session + JWT aligned with the live DB user id.
        if ($liveId > 0 && $liveId !== $uid) {
            try {
                $area = User::normalizeArea($row['zone'] ?? null, $row['city'] ?? null);
                $token = self::issueJwtForUser($row, $area);
                self::hydrateSessionFromUser($row, $area, $token);
                self::setJwtCookie($token);
            } catch (\Throwable $e) {
                $_SESSION['user_id'] = $liveId;
                $_SESSION['user_email'] = (string)($row['email'] ?? '');
                $_SESSION['user_name'] = (string)($row['name'] ?? 'User');
                $_SESSION['user_role'] = (string)($row['role'] ?? AppConfig::ROLE_SUPER_ADMIN);
            }
        }

        return true;
    }

    /** Home URL for the current role (avoid sending technicians to admin Dashboard). */
    public static function homeUrl(): string {
        $user = self::currentUser();
        $role = (string)($user['role'] ?? '');
        if ($role === AppConfig::ROLE_TECHNICIAN) {
            return 'field_home.php';
        }
        if ($role === AppConfig::ROLE_SUPER_ADMIN) {
            return 'index.php';
        }
        return 'profiles.php';
    }

    public static function requireRole(array|string $roles): void {
        self::requireLogin();
        if (!self::hasRole($roles)) {
            $u = self::currentUser();
            AppLogger::failed('auth.forbidden', '403 — role not permitted', [
                'user_id' => $u['id'] ?? null,
                'role' => $u['role'] ?? null,
                'required' => is_array($roles) ? $roles : [$roles],
            ]);
            // Soft-land on role home instead of a dead-end 403 loop via index.php
            $home = self::homeUrl();
            header('Location: ' . $home);
            exit;
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
