<?php
namespace App\Services;

use App\Config\AppConfig;

/**
 * Minimal HS256 JWT (no Composer dependency).
 * Used for auth cookie + Authorization: Bearer tokens.
 */
class JwtService {

    public static function encode(array $claims, ?int $ttlSeconds = null): string {
        $ttl = $ttlSeconds ?? (int)AppConfig::JWT_TTL_SECONDS;
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + max(60, $ttl),
            'iss' => AppConfig::APP_SHORT_NAME,
        ]);

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            self::b64(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, self::secret(), true);
        $segments[] = self::b64($signature);
        return implode('.', $segments);
    }

    /**
     * @return array<string,mixed>|null claims on success
     */
    public static function decode(string $jwt): ?array {
        $jwt = trim($jwt);
        if ($jwt === '' || substr_count($jwt, '.') !== 2) {
            return null;
        }
        [$h64, $p64, $s64] = explode('.', $jwt, 3);
        $signingInput = $h64 . '.' . $p64;
        $expected = self::b64(hash_hmac('sha256', $signingInput, self::secret(), true));
        if (!hash_equals($expected, $s64)) {
            return null;
        }

        $header = json_decode(self::ub64($h64), true);
        if (!is_array($header) || strtoupper((string)($header['alg'] ?? '')) !== 'HS256') {
            return null;
        }

        $payload = json_decode(self::ub64($p64), true);
        if (!is_array($payload)) {
            return null;
        }
        $exp = (int)($payload['exp'] ?? 0);
        if ($exp > 0 && time() >= $exp) {
            return null;
        }
        return $payload;
    }

    private static function secret(): string {
        $secret = (string)AppConfig::JWT_SECRET;
        if (strlen($secret) < 32) {
            // Dev fallback — change JWT_SECRET in AppConfig for production
            $secret = hash('sha256', AppConfig::APP_NAME . '|reeferon-df-jwt-dev');
        }
        return $secret;
    }

    private static function b64(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function ub64(string $b64): string {
        $remainder = strlen($b64) % 4;
        if ($remainder) {
            $b64 .= str_repeat('=', 4 - $remainder);
        }
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);
        return $raw === false ? '' : $raw;
    }
}
