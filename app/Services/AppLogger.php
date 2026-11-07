<?php
namespace App\Services;

/**
 * File logger — app/logs (daily rotation).
 * Tracks info / warning / error / audit / failed actions.
 */
class AppLogger {

    private static bool $booted = false;

    public static function logDir(): string {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
    }

    public static function ensureDir(): string {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Deny web access (Apache)
        $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($idx)) {
            @file_put_contents($idx, '');
        }
        return $dir;
    }

    /** Register PHP error / exception / shutdown handlers once. */
    public static function bootstrap(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::ensureDir();

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Respect @-suppressed errors
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $level = match ($severity) {
                E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
                E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
                default => 'info',
            };
            self::write($level, 'php.' . self::severityName($severity), $message, [
                'file' => $file,
                'line' => $line,
            ]);
            return false; // let PHP handle as usual too
        });

        set_exception_handler(static function (\Throwable $e): void {
            self::exception($e, 'uncaught');
            http_response_code(500);
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<h1>Something went wrong</h1><p>The error has been logged. Please try again or contact support.</p>';
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if (!$err) {
                return;
            }
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if (!in_array((int)$err['type'], $fatal, true)) {
                return;
            }
            self::write('error', 'php.fatal', (string)($err['message'] ?? 'Fatal error'), [
                'file' => $err['file'] ?? '',
                'line' => $err['line'] ?? 0,
                'type' => $err['type'] ?? 0,
            ]);
        });
    }

    public static function info(string $event, string $message, array $context = []): void {
        self::write('info', $event, $message, $context);
    }

    public static function warning(string $event, string $message, array $context = []): void {
        self::write('warning', $event, $message, $context);
    }

    public static function error(string $event, string $message, array $context = []): void {
        self::write('error', $event, $message, $context);
    }

    public static function audit(string $event, string $message, array $context = []): void {
        self::write('audit', $event, $message, $context);
    }

    public static function failed(string $event, string $message, array $context = []): void {
        self::write('failed', $event, $message, $context);
    }

    public static function exception(\Throwable $e, string $event = 'exception', array $context = []): void {
        self::write('error', $event, $e->getMessage(), array_merge($context, [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => self::shortTrace($e),
        ]));
    }

    /** @param array<string, mixed> $context */
    public static function write(string $level, string $event, string $message, array $context = []): void {
        try {
            $dir = self::ensureDir();
            $date = date('Y-m-d');
            $file = $dir . DIRECTORY_SEPARATOR . 'app-' . $date . '.log';
            $errorsFile = $dir . DIRECTORY_SEPARATOR . 'errors-' . $date . '.log';

            $user = null;
            try {
                if (class_exists(AuthService::class) && method_exists(AuthService::class, 'isLoggedIn') && AuthService::isLoggedIn()) {
                    $user = AuthService::currentUser();
                }
            } catch (\Throwable $e) {
                $user = null;
            }

            $row = [
                'ts' => date('Y-m-d H:i:s'),
                'level' => strtoupper($level),
                'event' => $event,
                'message' => $message,
                'user_id' => $user['id'] ?? null,
                'user' => $user['email'] ?? ($user['name'] ?? null),
                'role' => $user['role'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'uri' => $_SERVER['REQUEST_URI'] ?? null,
                'context' => $context ?: new \stdClass(),
            ];

            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

            if (in_array(strtolower($level), ['error', 'failed', 'warning'], true)) {
                @file_put_contents($errorsFile, $line, FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable $e) {
            @error_log('AppLogger failed: ' . $e->getMessage());
        }
    }

    private static function severityName(int $severity): string {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_' . $severity,
        };
    }

    private static function shortTrace(\Throwable $e): string {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $i => $f) {
            $file = isset($f['file']) ? basename((string)$f['file']) : '?';
            $line = $f['line'] ?? '?';
            $fn = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
            $frames[] = "#{$i} {$file}:{$line} {$fn}()";
        }
        return implode(' | ', $frames);
    }

    /** @return list<string> basenames like app-2026-11-05.log */
    public static function listLogFiles(): array {
        $dir = self::ensureDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . '{app,errors}-*.log', GLOB_BRACE) ?: [];
        $names = array_map('basename', $files);
        rsort($names, SORT_STRING);
        return array_values($names);
    }

    /** Safe resolve: only allow app-*.log / errors-*.log inside logs dir. */
    public static function resolveLogFile(string $basename): ?string {
        $basename = basename($basename);
        if (!preg_match('/^(app|errors)-\d{4}-\d{2}-\d{2}\.log$/', $basename)) {
            return null;
        }
        $path = self::ensureDir() . DIRECTORY_SEPARATOR . $basename;
        return is_file($path) ? $path : null;
    }

    /**
     * Read log lines (newest first), optional level / q filter.
     * @return list<array<string, mixed>>
     */
    public static function readEntries(string $basename, int $limit = 200, string $level = '', string $q = ''): array {
        $path = self::resolveLogFile($basename);
        if ($path === null) {
            return [];
        }
        $limit = max(1, min(1000, $limit));
        $level = strtoupper(trim($level));
        $q = trim($q);

        // Tail-read only — never load the whole log into memory.
        $chunk = self::tailFileLines($path, max($limit * 8, 800));
        if ($chunk === []) {
            return [];
        }

        $out = [];
        for ($i = count($chunk) - 1; $i >= 0; $i--) {
            $line = trim((string)$chunk[$i]);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                $decoded = [
                    'ts' => '',
                    'level' => 'INFO',
                    'event' => 'raw',
                    'message' => $line,
                ];
            }
            if ($level !== '' && strtoupper((string)($decoded['level'] ?? '')) !== $level) {
                continue;
            }
            if ($q !== '') {
                $hay = strtolower(json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: '');
                if (!str_contains($hay, strtolower($q))) {
                    continue;
                }
            }
            $out[] = $decoded;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Read last ~$maxLines from a text file without loading the entire file. */
    private static function tailFileLines(string $path, int $maxLines = 800): array {
        $maxLines = max(50, min(5000, $maxLines));
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return [];
        }

        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return [];
        }

        $readBytes = (int)min($size, max(65536, $maxLines * 512));
        if ($size > $readBytes) {
            fseek($fp, -$readBytes, SEEK_END);
            // Discard partial first line
            fgets($fp);
        } else {
            rewind($fp);
        }

        $buf = stream_get_contents($fp);
        fclose($fp);
        if ($buf === false || $buf === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $buf) ?: [];
        $lines = array_values(array_filter($lines, static fn($l) => trim((string)$l) !== ''));
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }
        return $lines;
    }
}
