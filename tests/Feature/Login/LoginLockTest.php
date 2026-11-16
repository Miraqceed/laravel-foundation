<?php

namespace Miraqceed\Foundation\Tests\Feature\Login;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
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
        return AttemptKey::for("login:{$scope}:account", (new NormalizedEmailKey)->key($email), IpBucket::forIp($ip));
    }

    private function clientKey(string $ip = '203.0.113.5', string $scope = 'user'): string
    {
        return AttemptKey::for("login:{$scope}:client", IpBucket::forIp($ip));
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

    public function test_failure_is_kept_when_verify_throws(): void
    {
        // 照合が投げたら足した分を返さない（例外を誘発して数えさせない抜け道を作らない。fail-closed）
        $lock = $this->lock();

        try {
            $lock->attempt($this->request(), 'a@example.test', fn () => throw new \RuntimeException('DB が落ちた'));
            $this->fail('照合の例外はそのまま投げる');
        } catch (\RuntimeException $e) {
            $this->assertSame('DB が落ちた', $e->getMessage());
        }

        $this->assertSame(1, RateLimiter::attempts($this->accountKey('a@example.test')));
        $this->assertSame(1, RateLimiter::attempts($this->clientKey()));
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

        $passwordKey = AttemptKey::for('login:user:password', '42', IpBucket::forIp('203.0.113.5'));
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

        // 接続元が変わっても同じメールは同じ枠で数える
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
    }

    public function test_email_only_mode_keeps_client_bucket(): void
    {
        config(['foundation.login.email_only' => true]);
        $lock = $this->lock();
        $request = $this->request();

        for ($i = 1; $i <= 20; $i++) {
            $this->assertFalse($lock->attempt($request, "user{$i}@example.test", $this->failing()));
        }

        $this->expectException(LoginLockedOut::class);
        $lock->attempt($request, 'user21@example.test', $this->refuse());
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

    public function test_email_only_limit_is_cleared_on_success(): void
    {
        config(['foundation.login.email_only' => true]);
        $lock = $this->lock();
        $lock->attempt($this->request(), 'a@example.test', $this->failing());
        $lock->attempt($this->request(), 'a@example.test', $this->failing());
        $emailKey = AttemptKey::for('login:user:email', (new NormalizedEmailKey)->key('a@example.test'));
        $this->assertSame(2, RateLimiter::attempts($emailKey));

        $this->assertTrue($lock->attempt($this->request(), 'a@example.test', fn () => true));

        $this->assertSame(0, RateLimiter::attempts($emailKey));
    }

    public function test_password_confirmation_limit_is_cleared_on_success(): void
    {
        $lock = $this->lock();
        $user = new class implements Authenticatable
        {
            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return 42;
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getRememberToken()
            {
                return null;
            }

            public function setRememberToken($value) {}

            public function getRememberTokenName()
            {
                return 'remember_token';
            }
        };
        $passwordKey = AttemptKey::for('login:user:password', '42', IpBucket::forIp('203.0.113.5'));
        $lock->attemptPasswordConfirmation($this->request(), $user, $this->failing());
        $lock->attemptPasswordConfirmation($this->request(), $user, $this->failing());
        $this->assertSame(2, RateLimiter::attempts($passwordKey));

        $this->assertTrue($lock->attemptPasswordConfirmation($this->request(), $user, fn () => true));

        $this->assertSame(0, RateLimiter::attempts($passwordKey));
    }

    /**
     * 照合の関数が自分で投げた「試みが多すぎる」（別の数え）を、ログインのロックの例外に書き替えない
     */
    public function test_too_many_attempts_thrown_by_verify_is_not_rewritten(): void
    {
        $caught = null;
        try {
            $this->lock()->attempt($this->request(), 'a@example.test', fn () => throw new TooManyAttempts(99, '別の数え'));
        } catch (TooManyAttempts $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertNotInstanceOf(LoginLockedOut::class, $caught);
        $this->assertSame(99, $caught->retryAfter);
        $this->assertSame('別の数え', $caught->getMessage());
    }
}
