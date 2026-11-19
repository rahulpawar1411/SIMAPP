<?php
namespace App\Services;

use App\Config\AppConfig;
use App\Models\User;

/**
 * Outbound notify hook — logs always; optional webhook for WhatsApp/SMS gateway.
 *
 * Set AppConfig::NOTIFY_WEBHOOK_URL to a HTTPS endpoint that accepts JSON:
 * { "channel": "sms"|"whatsapp"|"push", "to": "...", "event": "...", "message": "...", "meta": {} }
 */
class NotifyService {

    /** Queue / send a message. Never throws to callers. */
    public static function send(string $event, string $message, array $meta = []): bool {
        $toPhone = trim((string)($meta['phone'] ?? ''));
        $toUserId = (int)($meta['user_id'] ?? 0);
        $channel = (string)($meta['channel'] ?? 'log');

        AppLogger::info('notify.' . $event, $message, [
            'channel' => $channel,
            'to_phone' => $toPhone !== '' ? self::maskPhone($toPhone) : '',
            'user_id' => $toUserId > 0 ? $toUserId : null,
            'meta' => self::safeMeta($meta),
        ]);

        $webhook = trim((string)AppConfig::NOTIFY_WEBHOOK_URL);
        if ($webhook === '' || !preg_match('#^https?://#i', $webhook)) {
            return true; // logged only — ready for gateway later
        }

        $payload = [
            'event' => $event,
            'message' => $message,
            'channel' => $channel,
            'to' => $toPhone,
            'user_id' => $toUserId > 0 ? $toUserId : null,
            'app' => AppConfig::APP_SHORT_NAME,
            'meta' => self::safeMeta($meta),
            'sent_at' => date('c'),
        ];

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return false;
            }
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => $json,
                    'timeout' => 4,
                    'ignore_errors' => true,
                ],
            ]);
            $res = @file_get_contents($webhook, false, $ctx);
            AppLogger::info('notify.webhook', $res !== false ? 'Webhook OK' : 'Webhook failed', [
                'event' => $event,
                'url_host' => (string)(parse_url($webhook, PHP_URL_HOST) ?: ''),
            ]);
            return $res !== false;
        } catch (\Throwable $e) {
            AppLogger::warning('notify.webhook_error', $e->getMessage(), ['event' => $event]);
            return false;
        }
    }

    /** Technician edit access approved. */
    public static function editAccessApproved(array $notifRow, ?string $adminNote = null): void {
        $userId = (int)($notifRow['created_by'] ?? 0);
        $phone = self::userPhone($userId);
        $tag = (string)($notifRow['unique_freezer_id'] ?? ('#' . (int)($notifRow['freezer_id'] ?? 0)));
        $msg = 'Edit approved for ' . $tag . '. You can edit once within 24 hours.';
        if ($adminNote !== null && trim($adminNote) !== '') {
            $msg .= ' Note: ' . trim($adminNote);
        }
        self::send('edit_approved', $msg, [
            'user_id' => $userId,
            'phone' => $phone,
            'channel' => $phone !== '' ? 'whatsapp' : 'log',
            'freezer_id' => (int)($notifRow['freezer_id'] ?? 0),
            'unique_freezer_id' => $tag,
        ]);
    }

    /** Technician edit access rejected. */
    public static function editAccessRejected(array $notifRow, ?string $adminNote = null): void {
        $userId = (int)($notifRow['created_by'] ?? 0);
        $phone = self::userPhone($userId);
        $tag = (string)($notifRow['unique_freezer_id'] ?? ('#' . (int)($notifRow['freezer_id'] ?? 0)));
        $msg = 'Edit request rejected for ' . $tag . '.';
        if ($adminNote !== null && trim($adminNote) !== '') {
            $msg .= ' Reason: ' . trim($adminNote);
        } else {
            $msg .= ' Send a new remark if still needed.';
        }
        self::send('edit_rejected', $msg, [
            'user_id' => $userId,
            'phone' => $phone,
            'channel' => $phone !== '' ? 'whatsapp' : 'log',
            'freezer_id' => (int)($notifRow['freezer_id'] ?? 0),
            'unique_freezer_id' => $tag,
        ]);
    }

    /** Optional digest when AMC due count is high (admin/ops webhook). */
    public static function kycDueDigest(int $pendingCount, array $meta = []): void {
        if ($pendingCount <= 0) {
            return;
        }
        $msg = $pendingCount . ' freezer(s) have AMC due.';
        self::send('kyc_due_digest', $msg, array_merge([
            'channel' => 'log',
            'pending_count' => $pendingCount,
        ], $meta));
    }

    private static function userPhone(int $userId): string {
        if ($userId <= 0) {
            return '';
        }
        try {
            $u = User::findById($userId);
            return trim((string)($u['phone'] ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function maskPhone(string $phone): string {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($d) < 4) {
            return '****';
        }
        return str_repeat('*', max(0, strlen($d) - 4)) . substr($d, -4);
    }

    /** @param array<string,mixed> $meta */
    private static function safeMeta(array $meta): array {
        unset($meta['phone']); // already masked separately
        $out = [];
        foreach ($meta as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
