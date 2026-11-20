<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Cache\RateLimiter;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\Privacy\KeyedRateLimiter;

/**
 * 回数の制限の鍵が、キャッシュに APP_KEY の HMAC で入るか（foundation.privacy.keyed_rate_limiter）。
 *
 * - 差し替えていれば ok
 * - 差し替えていなければ info（Laravel の throttle・Fortify の鍵は、生か秘密のない sha1・md5 で入る）
 * - 設定が真なのに APP_KEY が空・差し替わっていなければ fail（回数の制限のある経路がすべて 500 になる・効いていない）
 */
final class LimiterKeys implements Check
{
    public function id(): string
    {
        return 'limiter_keys';
    }

    public function label(): string
    {
        return '回数の制限の鍵を APP_KEY の HMAC にしてキャッシュに入れる（決めたときだけ）';
    }

    public function run(): Result
    {
        $keyed = app(RateLimiter::class) instanceof KeyedRateLimiter;

        if (! config('foundation.privacy.keyed_rate_limiter')) {
            return $keyed
                ? Result::ok()
                : Result::info('回数の制限の鍵が、生か秘密のない sha1・md5 でキャッシュに入る（HMAC にするなら foundation.privacy.keyed_rate_limiter）');
        }

        if (! $keyed) {
            return Result::fail('foundation.privacy.keyed_rate_limiter が真なのに、RateLimiter が差し替わっていない');
        }

        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            return Result::fail('APP_KEY が空（回数の制限のある経路が、鍵を作れずに止まる）');
        }

        return Result::ok();
    }
}
