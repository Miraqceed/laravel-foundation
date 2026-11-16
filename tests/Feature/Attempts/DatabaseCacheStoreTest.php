<?php

namespace Miraqceed\Foundation\Tests\Feature\Attempts;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\ConsecutiveLimit;
use Miraqceed\Foundation\Attempts\Counting;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * database のキャッシュで動く（Redis のない共用サーバーで使う）。CI では PostgreSQL・MySQL・SQLite で流れる。
 * 守りは、キャッシュの increment と add が原子的であることに頼る
 */
class DatabaseCacheStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ロックの残りの秒を比べる。時刻を止めないと、ロックを立ててから断るまでに秒の境目をまたいだとき 59 になる
        $this->freezeSecond();

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

    private function attempts(): Attempts
    {
        return $this->app->make(Attempts::class);
    }

    public function test_the_store_is_the_database(): void
    {
        $this->assertSame('database', config('cache.default'));
        RateLimiter::hit('t:probe', 60);
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertGreaterThan(0, DB::table('cache')->count());
    }

    public function test_add_refuses_the_second_writer(): void
    {
        $this->assertTrue(Cache::add('t:once', 1, 60));
        $this->assertFalse(Cache::add('t:once', 2, 60));
        $this->assertSame(1, Cache::get('t:once'));
    }

    public function test_windowed_limits_work(): void
    {
        $own = new Limit('t:own', 2, 60);
        $shared = new Limit('t:shared', 5, 60, Counting::FailuresShared);
        $this->attempts()->attempt([$own, $shared], fn () => false);
        $this->attempts()->attempt([$own, $shared], fn () => false);

        try {
            $this->attempts()->attempt([$own, $shared], function (): bool {
                $this->fail('上限に達しているのに照合へ進んだ');
            });
            $this->fail('止まるはず');
        } catch (TooManyAttempts) {
        }

        $this->travel(61)->seconds();
        $this->assertTrue($this->attempts()->attempt([$own, $shared], fn () => true));
        $this->assertSame(0, RateLimiter::attempts('t:own'));
    }

    public function test_consecutive_limits_work(): void
    {
        $limit = new ConsecutiveLimit('t:code', 2, 60);
        $this->attempts()->attemptConsecutive($limit, fn () => false);
        $this->attempts()->attemptConsecutive($limit, fn () => false);

        try {
            $this->attempts()->attemptConsecutive($limit, function (): bool {
                $this->fail('ロック中に照合へ進んだ');
            });
            $this->fail('止まるはず');
        } catch (TooManyAttempts $e) {
            $this->assertSame(60, $e->retryAfter);
        }

        $this->travel(61)->seconds();
        $this->assertFalse($this->attempts()->attemptConsecutive($limit, fn () => false));
        $this->expectException(TooManyAttempts::class);
        $this->attempts()->attemptConsecutive($limit, fn () => true);
    }
}
