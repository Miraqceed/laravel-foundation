<?php

namespace Miraqceed\Foundation\Tests\Feature\Privacy;

use Miraqceed\Foundation\Tests\Feature\Login\LoginLockTest;

/**
 * LoginLockTest を、回数の制限の鍵を HMAC にした RateLimiter（foundation.privacy.keyed_rate_limiter）で流す。
 * 足す側（AttemptCounter）と読む側（RateLimiter）の鍵がずれれば、数え・ロックの試しが落ちる
 */
class KeyedLoginLockTest extends LoginLockTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.privacy.keyed_rate_limiter', true);
    }
}
