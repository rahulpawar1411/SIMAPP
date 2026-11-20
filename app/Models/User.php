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
        self::ensurePermissionsColumn();
    }

    /** JSON access flags: view / create / update / delete (technician master checkboxes). */
    public static function ensurePermissionsColumn(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $pdo = Database::getConnection();
            $cols = $pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!in_array('permissions', $cols, true)) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `permissions` TEXT NULL AFTER `city`");
            }
        } catch (\Throwable $e) {
            // column already present or DB unavailable
        }
    }

    /** @return list<string> */
    public static function permissionKeys(): array {
        return ['view', 'create', 'update', 'delete'];
    }

    /**
     * Default access by role.
     * Technician: view+create+update (delete off until admin checks it).
     * Viewer: view only. Super admin: all.
     *
     * @return array{view:bool,create:bool,update:bool,delete:bool}
     */
    public static function defaultPermissionsForRole(string $role): array {
        if ($role === AppConfig::ROLE_SUPER_ADMIN) {
            return ['view' => true, 'create' => true, 'update' => true, 'delete' => true];
        }
        if ($role === AppConfig::ROLE_VIEWER) {
            return ['view' => true, 'create' => false, 'update' => false, 'delete' => false];
        }
        // technician
        return ['view' => true, 'create' => true, 'update' => true, 'delete' => false];
    }

    /**
     * Normalize posted / stored permissions.
     * @param array<string,mixed>|string|null $raw
     * @return array{view:bool,create:bool,update:bool,delete:bool}
     */
    public static function normalizePermissions($raw, string $role = AppConfig::ROLE_TECHNICIAN): array {
        $defaults = self::defaultPermissionsForRole($role);
        if ($role === AppConfig::ROLE_SUPER_ADMIN) {
            return $defaults;
        }
        $data = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        } elseif (is_array($raw)) {
            $data = $raw;
        } else {
            return $defaults;
        }
        $out = [];
        foreach (self::permissionKeys() as $key) {
            if (array_key_exists($key, $data)) {
                $v = $data[$key];
                $out[$key] = ($v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'yes');
            } else {
                $out[$key] = (bool)$defaults[$key];
            }
        }
        // At least view so the account can open Assets / Home
        if (empty($out['view']) && empty($out['create']) && empty($out['update']) && empty($out['delete'])) {
            $out['view'] = true;
        }
        return $out;
    }

    /** @param array{view?:bool,create?:bool,update?:bool,delete?:bool} $perms */
    public static function encodePermissions(array $perms): string {
        $norm = self::normalizePermissions($perms, AppConfig::ROLE_TECHNICIAN);
        return json_encode([
            'view' => !empty($norm['view']),
            'create' => !empty($norm['create']),
            'update' => !empty($norm['update']),
            'delete' => !empty($norm['delete']),
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Short label for directory table: V C U D */
    public static function permissionsLabel(array $perms): string {
        $bits = [];
        if (!empty($perms['view'])) {
            $bits[] = 'View';
        }
        if (!empty($perms['create'])) {
            $bits[] = 'Create';
        }
        if (!empty($perms['update'])) {
            $bits[] = 'Update';
        }
        if (!empty($perms['delete'])) {
            $bits[] = 'Delete';
        }
        return $bits !== [] ? implode(' · ', $bits) : 'None';
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
        self::ensurePermissionsColumn();
        return '`id`, `name`, `email`, `role`, `phone`, `zone`, `city`, `permissions`, `status`, `last_login`, `created_at`';
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
        if ($role === AppConfig::ROLE_SUPER_ADMIN) {
            $permsJson = null;
        } elseif (array_key_exists('permissions', $data)) {
            $permsJson = self::encodePermissions(
                self::normalizePermissions($data['permissions'], $role)
            );
        } else {
            $permsJson = self::encodePermissions(
                self::normalizePermissions($existing['permissions'] ?? null, $role)
            );
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

        self::ensurePermissionsColumn();
        $pdo = Database::getConnection();
        if ($password !== '') {
            $stmt = $pdo->prepare('UPDATE `users` SET
                `name` = :name, `email` = :email, `password` = :password,
                `role` = :role, `phone` = :phone, `zone` = :zone, `city` = :city,
                `permissions` = :permissions, `status` = :status
                WHERE `id` = :id');
            $ok = $stmt->execute([
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'password' => \App\Services\PasswordService::hash($password),
                'role' => $role,
                'phone' => $phone,
                'zone' => $area['zone'] !== '' ? $area['zone'] : null,
                'city' => $area['city'] !== '' ? $area['city'] : null,
                'permissions' => $permsJson,
                'status' => $status,
            ]);
        } else {
            $stmt = $pdo->prepare('UPDATE `users` SET
                `name` = :name, `email` = :email,
                `role` = :role, `phone` = :phone, `zone` = :zone, `city` = :city,
                `permissions` = :permissions, `status` = :status
                WHERE `id` = :id');
            $ok = $stmt->execute([
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'phone' => $phone,
                'zone' => $area['zone'] !== '' ? $area['zone'] : null,
                'city' => $area['city'] !== '' ? $area['city'] : null,
                'permissions' => $permsJson,
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
        $perms = self::normalizePermissions($data['permissions'] ?? null, $role);
        $permsJson = ($role === AppConfig::ROLE_TECHNICIAN || $role === AppConfig::ROLE_VIEWER)
            ? self::encodePermissions($perms)
            : null;

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

        self::ensurePermissionsColumn();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO `users` (`name`, `email`, `password`, `role`, `phone`, `zone`, `city`, `permissions`, `status`)
            VALUES (:name, :email, :password, :role, :phone, :zone, :city, :permissions, :status)'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => \App\Services\PasswordService::hash($password),
            'role' => $role,
            'phone' => $phone,
            'zone' => $area['zone'] !== '' ? $area['zone'] : null,
            'city' => $area['city'] !== '' ? $area['city'] : null,
            'permissions' => $permsJson,
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

    public static function delete(int $id): bool {
        if ($id <= 0) {
            return false;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM `users` WHERE `id` = :id');
        return $stmt->execute(['id' => $id]);
    }

    /** Full row including password hash — for undo snapshots. */
    public static function findRawById(int $id): ?array {
        self::ensureRoleColumn();
        if ($id <= 0) {
            return null;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM `users` WHERE `id` = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Re-insert a previously deleted user from undo snapshot (keeps same id). */
    public static function restoreSnapshot(array $row): bool {
        self::ensureRoleColumn();
        $id = (int)($row['id'] ?? 0);
        $email = strtolower(trim((string)($row['email'] ?? '')));
        $name = trim((string)($row['name'] ?? ''));
        $password = (string)($row['password'] ?? '');
        if ($id <= 0 || $email === '' || $name === '' || $password === '') {
            return false;
        }
        if (self::findById($id) || self::findByEmail($email)) {
            return false;
        }

        $role = (string)($row['role'] ?? AppConfig::ROLE_SUPER_ADMIN);
        if (!in_array($role, AppConfig::allowedRoles(), true)) {
            $role = AppConfig::ROLE_SUPER_ADMIN;
        }
        $status = (($row['status'] ?? '') === 'inactive') ? 'inactive' : 'active';
        $area = self::normalizeArea($row['zone'] ?? null, $row['city'] ?? null);
        if ($role !== AppConfig::ROLE_TECHNICIAN) {
            $area = ['zone' => '', 'city' => ''];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO `users`
                (`id`, `name`, `email`, `password`, `role`, `phone`, `zone`, `city`, `status`, `last_login`, `created_at`)
             VALUES
                (:id, :name, :email, :password, :role, :phone, :zone, :city, :status, :last_login, :created_at)'
        );
        return $stmt->execute([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'phone' => ($row['phone'] ?? null) !== '' ? ($row['phone'] ?? null) : null,
            'zone' => $area['zone'] !== '' ? $area['zone'] : null,
            'city' => $area['city'] !== '' ? $area['city'] : null,
            'status' => $status,
            'last_login' => $row['last_login'] ?? null,
            'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /** Restore editable fields from undo snapshot (does not change password unless provided). */
    public static function restoreEditSnapshot(int $id, array $row): bool {
        self::ensureRoleColumn();
        if ($id <= 0 || !self::findById($id)) {
            return false;
        }
        $role = (string)($row['role'] ?? AppConfig::ROLE_SUPER_ADMIN);
        if (!in_array($role, AppConfig::allowedRoles(), true)) {
            $role = AppConfig::ROLE_SUPER_ADMIN;
        }
        $status = (($row['status'] ?? '') === 'inactive') ? 'inactive' : 'active';
        $area = self::normalizeArea($row['zone'] ?? null, $row['city'] ?? null);
        if ($role !== AppConfig::ROLE_TECHNICIAN) {
            $area = ['zone' => '', 'city' => ''];
        }

        $pdo = Database::getConnection();
        $params = [
            'id' => $id,
            'name' => trim((string)($row['name'] ?? '')),
            'email' => strtolower(trim((string)($row['email'] ?? ''))),
            'role' => $role,
            'phone' => ($row['phone'] ?? null) !== '' ? ($row['phone'] ?? null) : null,
            'zone' => $area['zone'] !== '' ? $area['zone'] : null,
            'city' => $area['city'] !== '' ? $area['city'] : null,
            'status' => $status,
        ];
        $sql = 'UPDATE `users` SET
            `name` = :name, `email` = :email, `role` = :role, `phone` = :phone,
            `zone` = :zone, `city` = :city, `status` = :status';
        if (!empty($row['password'])) {
            $sql .= ', `password` = :password';
            $params['password'] = (string)$row['password'];
        }
        $sql .= ' WHERE `id` = :id';
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }
}
