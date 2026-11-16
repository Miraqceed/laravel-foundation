<?php

namespace Miraqceed\Foundation\Tests\Feature\TwoFactor;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Miraqceed\Foundation\Tests\TestCase;
use Miraqceed\Foundation\TwoFactor\FortifyTwoFactorProvider;
use Miraqceed\Foundation\TwoFactor\Totp;

/**
 * database のキャッシュで、Fortify の差し替えの使った刻みの印（Cache::add）と続けた失敗が効く。CI では PostgreSQL・MySQL・SQLite で流れる
 */
class TwoFactorDatabaseCacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('foundation.attempts.consecutive', ['max' => 3, 'lock_seconds' => 60]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! interface_exists(\Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class)) {
            $this->markTestSkipped('Fortify（dev の依存）がない');
        }
        Schema::dropIfExists('cache');
        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        config(['cache.default' => 'database']);
        Cache::forgetDriver('database');
        $this->app->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cache');
        $this->app->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);

        parent::tearDown();
    }

    public function test_the_store_is_the_database(): void
    {
        Cache::add('t:probe', true, 60);

        $this->assertSame('database', config('cache.default'));
        $this->assertGreaterThan(0, DB::table('cache')->count());
    }

    public function test_used_step_and_lock_work_with_the_database_cache(): void
    {
        $provider = $this->app->make(FortifyTwoFactorProvider::class);
        $secret = Totp::generateSecret();
        $code = Totp::codeAt($secret, now()->getTimestamp());

        $this->assertTrue($provider->verify($secret, $code));
        $this->assertFalse($provider->verify($secret, $code));
        $this->assertFalse($provider->verify($secret, '000000'));
        $this->assertFalse($provider->verify($secret, '000001'));

        $this->expectException(ValidationException::class);
        $provider->verify($secret, '000002');
    }
}
