<?php
namespace App\Services;

/**
 * bcrypt password hashing (PASSWORD_BCRYPT) + timing-safe verify.
 */
class PasswordService {

    /** bcrypt cost (10–12 typical; 12 = stronger / slightly slower). */
    public const BCRYPT_COST = 12;

    public static function hash(string $plain): string {
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        if ($hash === false) {
            throw new \RuntimeException('Could not hash password.');
        }
        return $hash;
    }

    public static function verify(string $plain, string $hash): bool {
        if ($plain === '' || $hash === '') {
            return false;
        }
        return password_verify($plain, $hash);
    }

    /** True if hash should be re-saved with current bcrypt cost. */
    public static function needsRehash(string $hash): bool {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }
}
