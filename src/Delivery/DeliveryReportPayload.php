<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

/** Privacy-restricted values accepted by the v2 SDK report contract. */
final class DeliveryReportPayload
{
    public static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-'
            .dechex((hexdec($hex[16]) & 3) | 8).substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    public static function timestamp(int $milliseconds): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);
    }

    public static function safePath(string $path, bool $allowSearch = false): bool
    {
        if (strlen($path) > 500 || ! mb_check_encoding($path, 'UTF-8')) {
            return false;
        }

        $decoded = $path;
        for ($depth = 0; $depth < 3; $depth++) {
            if (preg_match('/%(?![a-f0-9]{2})/i', $decoded)) {
                return false;
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        if (! mb_check_encoding($decoded, 'UTF-8')
            || ! str_starts_with($decoded, '/')
            || str_starts_with($decoded, '//')
            || preg_match('/[%?#\\\\\x00-\x20\x7f\'"`<>@&]/', $decoded)
        ) {
            return false;
        }
        if ($decoded === '/search') {
            return $allowSearch && $path === '/search';
        }

        $private = [
            'login', 'logout', 'signin', 'sign-in', 'signup', 'sign-up', 'register',
            'cart', 'checkout', 'purchase', 'gopay', 'my-account', 'account',
            'admin', 'wp-admin', 'wp-login.php', 'oauth', 'auth', 'verify',
            'unsubscribe', 'token', 'search', 'api', 'assets', 'asset', 'static',
            '_next', 'cdn-cgi',
        ];
        foreach (explode('/', strtolower($decoded)) as $segment) {
            if ($segment === '.'
                || $segment === '..'
                || in_array($segment, $private, true)
                || str_contains($segment, 'password')
            ) {
                return false;
            }
        }

        return preg_match('/\.(?:avif|bmp|css|csv|eot|gif|ico|jpe?g|js|json|map|mjs|mp3|mp4|ogg|otf|png|svg|tar|tgz|ttf|webm|webp|woff2?|xml|zip)$/i', $decoded) !== 1
            && preg_match('/(?:sleep\(|benchmark\(|information_schema|dbms_pipe|\/etc\/passwd|\/wp-config)/i', $decoded) !== 1;
    }

    public static function validHit(mixed $hit): bool
    {
        if (! is_array($hit)
            || count($hit) !== 7
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $hit['event_id'] ?? '') !== 1
            || ! self::validTimestamp($hit['occurred_at'] ?? null)
            || ! is_string($hit['path'] ?? null)
            || ! self::safePath($hit['path'], allowSearch: true)
            || ! is_int($hit['status'] ?? null)
            || $hit['status'] < 100
            || $hit['status'] > 599
            || ! array_key_exists('bot', $hit)
            || ! array_key_exists('bot_category', $hit)
        ) {
            return false;
        }

        if (($hit['purpose'] ?? null) === 'ai_referral') {
            return $hit['bot'] === null && $hit['bot_category'] === null;
        }
        if (! is_string($hit['bot'])
            || $hit['bot'] === ''
            || strlen($hit['bot']) > 64
            || ! mb_check_encoding($hit['bot'], 'UTF-8')
        ) {
            return false;
        }

        return ($hit['purpose'] ?? null) === 'training'
            ? $hit['bot_category'] === 'training'
            : (($hit['purpose'] ?? null) === 'realtime_citation'
                && in_array($hit['bot_category'], ['search', 'user_triggered'], true));
    }

    public static function validObservation(mixed $observation): bool
    {
        return is_array($observation)
            && count($observation) === 3
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $observation['observation_id'] ?? '') === 1
            && self::validTimestamp($observation['observed_at'] ?? null)
            && is_string($observation['path'] ?? null)
            && self::safePath($observation['path']);
    }

    private static function validTimestamp(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) === 1
            && strtotime($value) !== false;
    }
}
