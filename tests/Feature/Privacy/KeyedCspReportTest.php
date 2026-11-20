<?php

namespace Miraqceed\Foundation\Tests\Feature\Privacy;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Tests\Feature\Security\CspReportTest;

/**
 * CspReportTest を、回数の制限の鍵を HMAC にした RateLimiter（foundation.privacy.keyed_rate_limiter）で流す。
 * 足す側（AttemptCounter）と読む側（RateLimiter）の鍵がずれれば、数え・ロックの試しが落ちる
 */
class KeyedCspReportTest extends CspReportTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.privacy.keyed_rate_limiter', true);
    }

    /**
     * 元の試しは、表の鍵が AttemptKey の形（foundation-attempts:csp-report:）であることを見る。
     * 差し替えた RateLimiter では、その鍵をもう一度 HMAC にしたもの（foundation-limiter:）が入る。IP が残らないことは同じ
     */
    public function test_throttle_key_in_cache_table_has_no_ip(): void
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

        $body = (string) json_encode(['csp-report' => [
            'effective-directive' => 'script-src',
            'blocked-uri' => 'https://evil.example/x.js',
            'document-uri' => 'https://app.example/page',
        ]]);

        try {
            Log::shouldReceive('warning');
            $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => '203.0.113.77'], $body)
                ->assertNoContent();
            $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => '2001:db8:1:2::5'], $body)
                ->assertNoContent();

            $keys = DB::table('cache')->pluck('key')->all();
            $this->assertNotEmpty($keys);
            foreach ($keys as $key) {
                $this->assertStringNotContainsString('203.0.113.77', $key);
                $this->assertStringNotContainsString('2001:db8', $key);
                $this->assertStringNotContainsString('csp-report', $key);
                $this->assertMatchesRegularExpression('/foundation-limiter:[0-9a-f]{64}(:timer)?\z/', $key);
            }
        } finally {
            Schema::dropIfExists('cache');
            $this->app->forgetInstance(CacheRateLimiter::class);
            RateLimiter::clearResolvedInstance(CacheRateLimiter::class);
        }
    }
}
