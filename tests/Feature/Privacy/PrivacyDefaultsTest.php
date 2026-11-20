<?php

namespace Miraqceed\Foundation\Tests\Feature\Privacy;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 入れただけでは変わらない：設定が既定のとき、セッションの表・RateLimiter・回数の制限の鍵は Laravel のまま
 */
class PrivacyDefaultsTest extends TestCase
{
    private const VISITOR_IP = '198.51.100.23';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session.driver', 'database');
        $app['config']->set('cache.default', 'database');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/test/visit', function () {
            session(['seen' => true]);

            return 'ok';
        });
        $router->get('/test/plain', fn () => 'ok')->middleware('throttle:5,1');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sessions');
        Schema::create('sessions', function ($table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::dropIfExists('cache');
        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Cache::forgetDriver('database');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('cache');

        parent::tearDown();
    }

    public function test_the_settings_are_off_by_default(): void
    {
        $this->assertFalse(config('foundation.privacy.sessions_without_ip'));
        $this->assertFalse(config('foundation.privacy.keyed_rate_limiter'));
    }

    public function test_the_database_session_handler_is_laravels_and_stores_the_ip(): void
    {
        $this->assertSame(DatabaseSessionHandler::class, Session::driver('database')->getHandler()::class);

        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR_IP])->get('/test/visit')->assertOk();

        $this->assertSame(self::VISITOR_IP, DB::table('sessions')->sole()->ip_address);
    }

    public function test_the_rate_limiter_and_its_keys_are_laravels(): void
    {
        $this->assertSame(CacheRateLimiter::class, app(CacheRateLimiter::class)::class);

        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR_IP])->get('/test/plain')->assertOk();

        // Laravel の鍵（sha1(ドメイン|IP)）のまま
        $keys = DB::table('cache')->pluck('key')->implode("\n");
        $this->assertStringContainsString(sha1('|'.self::VISITOR_IP), $keys);
        $this->assertStringNotContainsString('foundation-limiter:', $keys);
    }
}
