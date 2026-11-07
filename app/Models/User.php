<?php
namespace App\Models;

use App\Config\Database;
use App\Config\AppConfig;
use PDO;

class User {

    /** Ensure users.role ENUM includes technician / viewer. */
    public static function ensureRoleColumn(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $pdo = Database::getConnection();
            $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('super_admin','technician','viewer') NOT NULL DEFAULT 'super_admin'");
        } catch (\Throwable $e) {
            // column already updated or DB unavailable
        }
        self::ensureAreaColumns();
    }

    /** Ensure users.zone / users.city for area-scoped field users. */
    public static function ensureAreaColumns(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $pdo = Database::getConnection();
            $cols = $pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!in_array('zone', $cols, true)) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `zone` VARCHAR(20) NULL AFTER `phone`");
            }
            if (!in_array('city', $cols, true)) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `city` VARCHAR(100) NULL AFTER `zone`");
            }
        } catch (\Throwable $e) {
            // columns already present or DB unavailable
        }
    }

    /** Normalize assigned zone/city. Empty = no restriction. */
    public static function normalizeArea(?string $zone, ?string $city): array {
        $zone = strtoupper(trim((string)$zone));
        $city = trim((string)$city);
        if ($zone === '' || $zone === 'ALL' || !in_array($zone, AppConfig::zones(), true)) {
            $zone = '';
        }
        if ($city === '' || strcasecmp($city, 'All') === 0) {
            $city = '';
        }
        return ['zone' => $zone, 'city' => $city];
    }

    private static function selectCols(): string {
        return '`id`, `name`, `email`, `role`, `phone`, `zone`, `city`, `status`, `last_login`, `created_at`';
    }

    public static function all(): array {
        self::ensureRoleColumn();
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT ' . self::selectCols() . ' FROM `users` ORDER BY `id` ASC');
        return $stmt->fetchAll();
    }

    public static function countAll(): int {
        self::ensureRoleColumn();
        $pdo = Database::getConnection();
        return (int)$pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn();
    }

    /** @return list<array> */
    public static function listPaged(int $limit = 20, int $offset = 0): array {
        self::ensureRoleColumn();
        $pdo = Database::getConnection();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = $pdo->prepare(
            'SELECT ' . self::selectCols() . '
             FROM `users`
             ORDER BY `id` ASC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function findByEmail(string $email): ?array {
        self::ensureAreaColumns();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM `users` WHERE `email` = :email LIMIT 1');
        $stmt->execute(['email' => trim($email)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array {
        self::ensureRoleColumn();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::selectCols() . ' FROM `users` WHERE `id` = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Update user profile (Super Admin). Password optional — blank keeps existing.
     */
    public static function update(int $id, array $data): array {
        $existing = self::findById($id);
        if (!$existing) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        self::ensureRoleColumn();
        $name = trim($data['name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        $role = trim((string)($data['role'] ?? $existing['role'] ?? AppConfig::ROLE_SUPER_ADMIN));
        $status = trim($data['status'] ?? $existing['status']);
        $allowedRoles = AppConfig::allowedRoles();
        // Zone/city only for Technician at create/edit — Viewer & Super Admin always All areas
        $area = ['zone' => '', 'city' => ''];
        if ($role === AppConfig::ROLE_TECHNICIAN) {
            $area = self::normalizeArea($data['zone'] ?? null, $data['city'] ?? null);
        }

        if ($name === '' || $email === '') {
            return ['success' => false, 'error' => 'Name and email are required.'];
        }

        if (!preg_match("/^[A-Za-z][A-Za-z .'-]{1,98}$/", $name)) {
            return ['success' => false, 'error' => 'Name must contain only letters (text). Numbers and special symbols are not allowed.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email)) {
            return ['success' => false, 'error' => 'Please enter a valid email format (e.g. name@company.com).'];
        }

        if ($password !== '' && strlen($password) < 6) {
            return ['success' => false, 'error' => 'Password must be at least 6 characters (or leave blank to keep current).'];
        }

        if (!in_array($role, $allowedRoles, true)) {
            return ['success' => false, 'error' => 'Invalid role selected.'];
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = $existing['status'];
        }

        $phoneResult = self::normalizeIndianPhone($data['phone'] ?? '');
        if (!$phoneResult['success']) {
            return ['success' => false, 'error' => $phoneResult['error']];
        }
        $phone = $phoneResult['phone'];

        $other = self::findByEmail($email);
        if ($other && (int)$other['id'] !== $id) {
            return ['success' => false, 'error' => 'A user with this email already exists.'];
        }

        $pdo = Database::getConnection();
        if ($password !== '') {
            $stmt = $pdo->prepare('UPDATE `users` SET
                `name` = :name, `email` = :email, `password` = :password,
                `role` = :role, `phone` = :phone, `zone` = :zone, `city` = :city, `status` = :status
                WHERE `id` = :id');
            $ok = $stmt->execute([
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'phone' => $phone,
                'zone' => $area['zone'] !== '' ? $area['zone'] : null,
                'city' => $area['city'] !== '' ? $area['city'] : null,
                'status' => $status,
            ]);
        } else {
            $stmt = $pdo->prepare('UPDATE `users` SET
                `name` = :name, `email` = :email,
                `role` = :role, `phone` = :phone, `zone` = :zone, `city` = :city, `status` = :status
                WHERE `id` = :id');
            $ok = $stmt->execute([
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'phone' => $phone,
                'zone' => $area['zone'] !== '' ? $area['zone'] : null,
                'city' => $area['city'] !== '' ? $area['city'] : null,
                'status' => $status,
            ]);
        }

        if (!$ok) {
            return ['success' => false, 'error' => 'Could not update user.'];
        }

        return ['success' => true, 'id' => $id];
    }

    /**
     * Normalize Indian mobile to +91XXXXXXXXXX. Empty input returns null.
     * Accepts 10 digits, or values with +91 / 91 / 0 prefix.
     */
    public static function normalizeIndianPhone(string $phone): array {
        $raw = trim($phone);
        if ($raw === '') {
            return ['success' => true, 'phone' => null];
        }

        $digits = preg_replace('/\D/', '', $raw);

        // Strip country / trunk prefixes: +91..., 91..., 0...
        if (strlen($digits) >= 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        } elseif (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
            return [
                'success' => false,
                'error' => 'Phone must be a valid 10-digit Indian mobile starting with 6, 7, 8 or 9.',
                'phone' => null,
            ];
        }

        return ['success' => true, 'phone' => '+91' . $digits];
    }

    public static function create(array $data): array {
        self::ensureRoleColumn();
        $name = trim($data['name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        $role = trim((string)($data['role'] ?? AppConfig::ROLE_SUPER_ADMIN));
        $status = trim($data['status'] ?? 'active');
        $allowedRoles = AppConfig::allowedRoles();
        // Zone/city only for Technician — Viewer & Super Admin always All areas
        $area = ['zone' => '', 'city' => ''];
        if ($role === AppConfig::ROLE_TECHNICIAN) {
            $area = self::normalizeArea($data['zone'] ?? null, $data['city'] ?? null);
        }

        if ($name === '' || $email === '' || $password === '') {
            return ['success' => false, 'error' => 'Name, email and password are required.'];
        }

        // Letters, spaces, dot, apostrophe, hyphen only (person name)
        if (!preg_match("/^[A-Za-z][A-Za-z .'-]{1,98}$/", $name)) {
            return ['success' => false, 'error' => 'Name must contain only letters (text). Numbers and special symbols are not allowed.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email)) {
            return ['success' => false, 'error' => 'Please enter a valid email format (e.g. name@company.com).'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Password must be at least 6 characters.'];
        }

        if (!in_array($role, $allowedRoles, true)) {
            return ['success' => false, 'error' => 'Invalid role selected.'];
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $phoneResult = self::normalizeIndianPhone($data['phone'] ?? '');
        if (!$phoneResult['success']) {
            return ['success' => false, 'error' => $phoneResult['error']];
        }
        $phone = $phoneResult['phone'];

        if (self::findByEmail($email)) {
            return ['success' => false, 'error' => 'A user with this email already exists.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO `users` (`name`, `email`, `password`, `role`, `phone`, `zone`, `city`, `status`)
            VALUES (:name, :email, :password, :role, :phone, :zone, :city, :status)'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'phone' => $phone,
            'zone' => $area['zone'] !== '' ? $area['zone'] : null,
            'city' => $area['city'] !== '' ? $area['city'] : null,
            'status' => $status,
        ]);

        return [
            'success' => true,
            'id' => (int)$pdo->lastInsertId(),
        ];
    }

    public static function setStatus(int $id, string $status): bool {
        if (!in_array($status, ['active', 'inactive'], true)) {
            return false;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE `users` SET `status` = :status WHERE `id` = :id');
        return $stmt->execute(['status' => $status, 'id' => $id]);
    }
}
