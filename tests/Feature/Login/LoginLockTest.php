<?php

namespace Miraqceed\Foundation\Tests\Feature\Login;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Login\IpBucket;
use Miraqceed\Foundation\Login\LoginLock;
use Miraqceed\Foundation\Login\LoginLockedOut;
use Miraqceed\Foundation\Login\NormalizedEmailKey;
use Miraqceed\Foundation\Tests\TestCase;

class LoginLockTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // 既定は array。database ストアは専用の試しで確かめる
        $app['config']->set('cache.default', 'array');
    }

    private function request(string $ip = '203.0.113.5'): Request
    {
        return Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
    }

    private function lock(string $scope = 'user'): LoginLock
    {
        return new LoginLock(new NormalizedEmailKey, $scope);
    }

    private function accountKey(string $email, string $ip = '203.0.113.5', string $scope = 'user'): string
    {
        return "foundation-login:{$scope}:account:".sha1((new NormalizedEmailKey)->key($email).'|'.IpBucket::forIp($ip));
    }

    private function clientKey(string $ip = '203.0.113.5', string $scope = 'user'): string
    {
        return "foundation-login:{$scope}:client:".IpBucket::forIp($ip);
    }

    private function failing(): \Closure
    {
        return fn () => false;
    }

    private function refuse(): \Closure
    {
        return function () {
            $this->fail('ロック中に照合へ進んではならない');
        };
    }

    public function test_counts_only_failures_and_clears_account_on_success(): void
    {
        $lock = $this->lock();
        $request = $this->request();

        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($lock->attempt($request, 'a@example.test', $this->failing()));
        }
        $this->assertSame(4, RateLimiter::attempts($this->accountKey('a@example.test')));

        $this->assertTrue($lock->attempt($request, 'a@example.test', fn () => true));

        $this->assertSame(0, RateLimiter::attempts($this->accountKey('a@example.test')));
        // 接続元の枠は今回の1回分だけ戻す（成功では空にしない）。失敗の4回は残る
        $this->assertSame(4, RateLimiter::attempts($this->clientKey()));
    }

    public function test_locks_after_account_max_failures_per_email_and_ip(): void
    {
        $lock = $this->lock();
        $request = $this->request();

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($lock->attempt($request, 'a@example.test', $this->failing()));
        }

        try {
            $lock->attempt($request, 'a@example.test', $this->refuse());
            $this->fail('LoginLockedOut が投げられるはず');
        } catch (LoginLockedOut $e) {
            $this->assertGreaterThanOrEqual(1, $e->retryAfter);
            $this->assertLessThanOrEqual(60, $e->retryAfter);
        }

        // 止めた要求は失敗として数え足さない（数えが膨らまない）
        $this->assertSame(5, RateLimiter::attempts($this->accountKey('a@example.test')));

        // 正しいパスワードでもロック中は通さない
        $this->expectException(LoginLockedOut::class);
        $lock->attempt($request, 'a@example.test', fn () => true);
    }

    public function test_other_ip_is_not_locked_out_by_attackers(): void
    {
        $lock = $this->lock();

        for ($i = 0; $i < 5; $i++) {
            $lock->attempt($this->request('203.0.113.5'), 'victim@example.test', $this->failing());
        }

        $this->assertTrue($lock->attempt($this->request('198.51.100.7'), 'victim@example.test', fn () => true));
    }

    public function test_client_bucket_stops_spray_across_emails(): void
    {
        $lock = $this->lock();
        $request = $this->request();

        for ($i = 1; $i <= 20; $i++) {
            $this->assertFalse($lock->attempt($request, "user{$i}@example.test", $this->failing()));
        }

        $this->expectException(LoginLockedOut::class);
        $lock->attempt($request, 'user21@example.test', $this->refuse());
    }

    public function test_concurrent_attempts_cannot_exceed_limit(): void
    {
        $lock = $this->lock();
        $request = $this->request();
        $calls = 0;

        // 照合の途中で、同じメールと接続元の要求が割り込んで並ぶ形（照合の中で次の試行を起こす）
        $verify = null;
        $verify = function () use (&$verify, &$calls, $lock, $request) {
            $calls++;
            try {
                $lock->attempt($request, 'a@example.test', $verify);
            } catch (LoginLockedOut) {
                // 割り込んだ要求は止められる
            }

            return false;
        };

        $lock->attempt($request, 'a@example.test', $verify);

        $max = config('foundation.login.account_max');
        $this->assertSame($max, $calls);
        $this->assertLessThanOrEqual($max, RateLimiter::attempts($this->accountKey('a@example.test')));
    }

    public function test_scopes_count_separately(): void
    {
        $request = $this->request();

        for ($i = 0; $i < 5; $i++) {
            $this->lock('user')->attempt($request, 'a@example.test', $this->failing());
        }

        $this->assertTrue($this->lock('operator')->attempt($request, 'a@example.test', fn () => true));
        $this->assertSame(0, RateLimiter::attempts($this->accountKey('a@example.test', scope: 'operator')));
        $this->assertSame(5, RateLimiter::attempts($this->accountKey('a@example.test', scope: 'user')));
    }

    public function test_password_confirmation_failures_lock_confirmation_only(): void
    {
        $lock = $this->lock();
        $request = $this->request();
        $user = new class implements Authenticatable
        {
            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 42;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        };

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($lock->attemptPasswordConfirmation($request, $user, $this->failing()));
        }

        try {
            $lock->attemptPasswordConfirmation($request, $user, $this->refuse());
            $this->fail('確かめは止まるはず');
        } catch (LoginLockedOut) {
            // 期待どおり
        }

        $passwordKey = 'foundation-login:user:password:'.sha1('42|'.IpBucket::forIp('203.0.113.5'));
        $this->assertSame(5, RateLimiter::attempts($passwordKey));

        // 同じ利用者のログインは通る（ログインの数えとは別）
        $this->assertTrue($lock->attempt($request, 'a@example.test', fn () => true));

        // 確かめの成功で数えが戻る
        RateLimiter::clear($passwordKey);
        $this->assertTrue($lock->attemptPasswordConfirmation($request, $user, fn () => true));
        $this->assertSame(0, RateLimiter::attempts($passwordKey));
    }

    public function test_email_only_mode_uses_30_minutes_and_10(): void
    {
        config(['foundation.login.email_only' => true]);
        $lock = $this->lock();

        // 接続元が変わっても同じメールは同じ枠で数える。接続元ごとの枠は使わない
        for ($i = 1; $i <= 10; $i++) {
            $this->assertFalse($lock->attempt($this->request("198.51.100.{$i}"), 'a@example.test', $this->failing()));
        }

        try {
            $lock->attempt($this->request('198.51.100.99'), 'a@example.test', $this->refuse());
            $this->fail('11回目は止まるはず');
        } catch (LoginLockedOut $e) {
            $this->assertGreaterThan(60, $e->retryAfter);
            $this->assertLessThanOrEqual(1800, $e->retryAfter);
        }

        $this->assertSame(0, RateLimiter::attempts($this->clientKey('198.51.100.1')));
    }

    public function test_works_with_database_cache_store(): void
    {
        config(['cache.default' => 'database']);
        $this->app->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);

        Schema::dropIfExists('cache');
        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        try {
            $lock = $this->lock();
            $request = $this->request();

            for ($i = 0; $i < 5; $i++) {
                $this->assertFalse($lock->attempt($request, 'a@example.test', $this->failing()));
            }

            try {
                $lock->attempt($request, 'a@example.test', $this->refuse());
                $this->fail('database ストアでも止まるはず');
            } catch (LoginLockedOut) {
                // 期待どおり
            }

            $this->assertTrue($lock->attempt($this->request('198.51.100.7'), 'a@example.test', fn () => true));
        } finally {
            Schema::dropIfExists('cache');
            $this->app->forgetInstance(CacheRateLimiter::class);
            RateLimiter::clearResolvedInstance(CacheRateLimiter::class);
        }
    }
}
