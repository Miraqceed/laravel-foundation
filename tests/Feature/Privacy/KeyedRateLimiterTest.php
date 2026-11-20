<?php

namespace Miraqceed\Foundation\Tests\Feature\Privacy;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\LoginRateLimiter;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\Limit as AttemptLimit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Privacy\KeyedRateLimiter;
use Miraqceed\Foundation\Testing\RawIp;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * foundation.privacy.keyed_rate_limiter：回数の制限の鍵が、キャッシュの表にすべて APP_KEY の HMAC で入る。数えは効き続ける
 */
class KeyedRateLimiterTest extends TestCase
{
    private const VISITOR_IP = '203.0.113.7';

    private const EMAIL = 'alice@example.test';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.privacy.keyed_rate_limiter', true);
        $app['config']->set('cache.default', 'database');
    }

    protected function defineRoutes($router): void
    {
        RateLimiter::for('contact', fn (Request $request) => Limit::perMinute(2)->by($request->ip()));

        $router->get('/test/plain', fn () => 'ok')->middleware('throttle:2,1');
        $router->get('/test/named', fn () => 'ok')->middleware('throttle:contact');
    }

    protected function setUp(): void
    {
        parent::setUp();

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
        Schema::dropIfExists('cache');

        parent::tearDown();
    }

    /** @return list<string> */
    private function cacheKeys(): array
    {
        return DB::table('cache')->pluck('key')->map(fn ($key) => (string) $key)->all();
    }

    /**
     * キャッシュの表の鍵が、どれも HMAC の形で、IP も、秘密のない sha1・md5 の値も入っていない
     *
     * @param  list<string>  $forbidden
     */
    private function assertOnlyKeyedKeys(array $forbidden): void
    {
        $keys = $this->cacheKeys();
        $this->assertNotSame([], $keys, '数えが表に入っていない');

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/foundation-limiter:[0-9a-f]{64}(:timer)?\z/', $key);
            foreach ($forbidden as $value) {
                $this->assertStringNotContainsString($value, $key);
            }
        }

        RawIp::assertNotStored(self::VISITOR_IP);
    }

    public function test_the_container_rate_limiter_is_replaced(): void
    {
        $this->assertInstanceOf(KeyedRateLimiter::class, app(CacheRateLimiter::class));

        // 作り直しても差し替わる（extend は遅れて読む CacheServiceProvider の後でも効く）
        $this->app->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);
        $this->assertInstanceOf(KeyedRateLimiter::class, app(CacheRateLimiter::class));
    }

    public function test_the_plain_throttle_for_guests_still_limits_and_leaves_no_ip_in_the_keys(): void
    {
        $visit = fn () => $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR_IP])->get('/test/plain');

        $visit()->assertOk();
        $visit()->assertOk();
        $visit()->assertStatus(429);

        // Laravel の鍵は sha1(ドメイン|IP)。ドメインのない経路では '|'.IP
        $this->assertOnlyKeyedKeys([self::VISITOR_IP, sha1('|'.self::VISITOR_IP)]);
    }

    public function test_a_named_limit_by_ip_still_limits_and_leaves_no_ip_in_the_keys(): void
    {
        $visit = fn () => $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR_IP])->get('/test/named');

        $visit()->assertOk();
        $visit()->assertOk();
        $visit()->assertStatus(429);

        // 名前の付いた制限の鍵は md5(名前.鍵)
        $this->assertOnlyKeyedKeys([self::VISITOR_IP, md5('contact'.self::VISITOR_IP)]);
    }

    public function test_fortify_login_rate_limiter_still_limits_and_leaves_no_ip_or_email_in_the_keys(): void
    {
        $limiter = new LoginRateLimiter(app(CacheRateLimiter::class));
        $request = Request::create('/login', 'POST', ['email' => self::EMAIL], server: ['REMOTE_ADDR' => self::VISITOR_IP]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($limiter->tooManyAttempts($request));
            $limiter->increment($request);
        }
        $this->assertTrue($limiter->tooManyAttempts($request));
        $this->assertGreaterThan(0, $limiter->availableIn($request));

        // Fortify の鍵は「メールアドレス|IP」をそのまま使う
        $this->assertOnlyKeyedKeys([self::VISITOR_IP, self::EMAIL]);

        $limiter->clear($request);
        $this->assertFalse($limiter->tooManyAttempts($request));
    }

    public function test_attempts_counted_by_the_package_are_read_by_the_rate_limiter(): void
    {
        $limit = new AttemptLimit('t:keyed', 2, 60);
        $attempts = app(Attempts::class);

        $attempts->attempt([$limit], fn () => false);
        $attempts->attempt([$limit], fn () => false);

        // 足す側（AttemptCounter）と読む側（RateLimiter の facade）の鍵が同じ
        $this->assertSame(2, (int) RateLimiter::attempts('t:keyed'));
        $this->assertTrue(RateLimiter::tooManyAttempts('t:keyed', 2));

        try {
            $attempts->attempt([$limit], function (): bool {
                $this->fail('上限に達しているのに照合へ進んだ');
            });
            $this->fail('TooManyAttempts が出なかった');
        } catch (TooManyAttempts) {
            // 期待どおり
        }

        $this->assertOnlyKeyedKeys(['t:keyed']);
    }

    public function test_methods_that_clean_the_key_twice_still_find_the_count(): void
    {
        // remaining() と clear() は、RateLimiter の中で鍵を2回通す
        RateLimiter::hit('t:twice', 60);
        RateLimiter::hit('t:twice', 60);

        $this->assertSame(3, RateLimiter::remaining('t:twice', 5));

        RateLimiter::clear('t:twice');

        $this->assertSame(0, (int) RateLimiter::attempts('t:twice'));
        $this->assertSame(0, RateLimiter::availableIn('t:twice'));
        $this->assertSame([], $this->cacheKeys());
    }

    public function test_named_limiters_registered_before_the_replacement_are_kept(): void
    {
        $base = new CacheRateLimiter(Cache::store('array'));
        $base->for('kept', fn () => Limit::perMinute(1));

        $keyed = KeyedRateLimiter::from($base);

        $this->assertNotNull($keyed->limiter('kept'));
        $keyed->hit('t:store', 60);
        $this->assertSame(1, (int) $base->attempts($keyed->cleanRateLimiterKey('t:store')));
    }

    public function test_an_empty_app_key_stops_instead_of_falling_back_to_raw_keys(): void
    {
        config(['app.key' => '']);

        try {
            RateLimiter::hit('t:no-key', 60);
            $this->fail('APP_KEY が空なのに数えた');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('APP_KEY', $e->getMessage());
        }

        $this->assertSame([], $this->cacheKeys());
    }

    public function test_the_key_changes_with_the_app_key(): void
    {
        $before = app(CacheRateLimiter::class)->cleanRateLimiterKey('t:rotate');

        config(['app.key' => 'base64:'.base64_encode(str_repeat('u', 32))]);

        $this->assertNotSame($before, app(CacheRateLimiter::class)->cleanRateLimiterKey('t:rotate'));
    }
}
