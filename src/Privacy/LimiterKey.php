<?php

namespace Miraqceed\Foundation\Privacy;

/**
 * KeyedRateLimiter の鍵の作り。APP_KEY から用途の名前で導いた鍵の HMAC-SHA256。
 *
 * @internal
 */
final class LimiterKey
{
    private const PREFIX = 'foundation-limiter:';

    /** 用途の名前（AttemptKey・IpHash の鍵と分ける） */
    private const PURPOSE = 'miraqceed/laravel-foundation rate limiter key';

    /** @throws \RuntimeException app.key が空のとき */
    public static function for(string $key): string
    {
        $secret = config('app.key');
        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('回数の制限の鍵を作れません。APP_KEY（app.key）を設定してください');
        }

        return self::PREFIX.hash_hmac('sha256', $key, hash_hkdf('sha256', $secret, 32, self::PURPOSE));
    }

    public static function isKeyed(string $key): bool
    {
        return preg_match('/\A'.preg_quote(self::PREFIX, '/').'[0-9a-f]{64}\z/', $key) === 1;
    }
}
