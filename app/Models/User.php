<?php
namespace App\Models;

use App\Config\Database;
use App\Config\AppConfig;
use PDO;

class User {

    public static function all(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT `id`, `name`, `email`, `role`, `phone`, `status`, `last_login`, `created_at`
            FROM `users` ORDER BY `id` ASC");
        return $stmt->fetchAll();
    }

    public static function findByEmail(string $email): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `email` = :email LIMIT 1");
        $stmt->execute(['email' => trim($email)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT `id`, `name`, `email`, `role`, `phone`, `status`, `last_login`, `created_at`
            FROM `users` WHERE `id` = :id LIMIT 1");
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

        $name = trim($data['name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        $role = AppConfig::ROLE_SUPER_ADMIN;
        $status = trim($data['status'] ?? $existing['status']);

        $allowedRoles = [
            AppConfig::ROLE_SUPER_ADMIN,
        ];

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
            $stmt = $pdo->prepare("UPDATE `users` SET
                `name` = :name, `email` = :email, `password` = :password,
                `role` = :role, `phone` = :phone, `status` = :status
                WHERE `id` = :id");
            $ok = $stmt->execute([
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'phone' => $phone,
                'status' => $status,
            ]);
        } else {
            $stmt = $pdo->prepare("UPDATE `users` SET
                `name` = :name, `email` = :email,
                `role` = :role, `phone` = :phone, `status` = :status
                WHERE `id` = :id");
            $ok = $stmt->execute([
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'phone' => $phone,
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
        $name = trim($data['name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        $role = AppConfig::ROLE_SUPER_ADMIN;
        $status = trim($data['status'] ?? 'active');

        $allowedRoles = [
            AppConfig::ROLE_SUPER_ADMIN,
        ];

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
        $stmt = $pdo->prepare("INSERT INTO `users` (`name`, `email`, `password`, `role`, `phone`, `status`)
            VALUES (:name, :email, :password, :role, :phone, :status)");
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'phone' => $phone,
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
        $stmt = $pdo->prepare("UPDATE `users` SET `status` = :status WHERE `id` = :id");
        return $stmt->execute(['status' => $status, 'id' => $id]);
    }
}
