<?php

namespace Miraqceed\Foundation\Tests\Feature\Security;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\Login\IpBucket;
use Miraqceed\Foundation\Tests\Support\JsonProbe;
use Miraqceed\Foundation\Tests\TestCase;

class CspReportTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('foundation.security.report_route', '/csp-report');
        $app['config']->set('cache.default', 'array');
    }

    private function report(string $body, string $type = 'application/csp-report')
    {
        return $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => $type], $body);
    }

    private function legacy(): string
    {
        return json_encode(['csp-report' => [
            'effective-directive' => 'script-src',
            'blocked-uri' => 'https://evil.example/x.js?token=1',
            'document-uri' => 'https://app.example/page?secret=1#frag',
        ]]);
    }

    public function test_csp_report_accepts_csp_report_content_type_without_csrf(): void
    {
        Log::shouldReceive('warning')->once();

        foreach (['application/csp-report'] as $type) {
            $this->report($this->legacy(), $type)->assertNoContent();
        }
    }

    public function test_csp_report_accepts_other_json_content_types(): void
    {
        Log::shouldReceive('warning')->twice();
        $reporting = json_encode([['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'https://x.example/a.png', 'documentURL' => 'https://app.example/']]]);

        $this->report($reporting, 'application/reports+json')->assertNoContent();
        $this->report($this->legacy(), 'application/json')->assertNoContent();
    }

    public function test_csp_report_rejects_over_8kb_even_without_content_length(): void
    {
        $body = json_encode(['csp-report' => ['blocked-uri' => str_repeat('a', 9000)]]);

        $this->report($body)->assertStatus(413);
        $this->report(str_repeat('x', 8192))->assertNoContent();
    }

    public function test_csp_report_logs_violation_without_query(): void
    {
        Log::shouldReceive('warning')->once()->with('csp.violation', \Mockery::on(function (array $ctx) {
            return $ctx['directive'] === 'script-src'
                && $ctx['blocked'] === 'https://evil.example/x.js'
                && $ctx['page'] === 'https://app.example/page';
        }));

        $this->report($this->legacy())->assertNoContent();
    }

    public function test_csp_report_throttles_after_30_per_minute(): void
    {
        RateLimiter::clear(AttemptKey::for('csp-report', IpBucket::forIp('127.0.0.1')));
        Log::shouldReceive('warning');

        for ($i = 0; $i < 30; $i++) {
            $this->report($this->legacy())->assertNoContent();
        }
        $this->report($this->legacy())->assertStatus(429);
    }

    public function test_csp_report_throttles_ipv6_by_64_bucket(): void
    {
        // ログインと同じ接続元の単位（IpBucket）で数える。/64 の中でアドレスを変えても避けられない
        Log::shouldReceive('warning');

        for ($i = 0; $i < 30; $i++) {
            $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => '2001:db8:1:2::'.dechex($i + 1)], $this->legacy())
                ->assertNoContent();
        }
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => '2001:db8:1:2::ffff'], $this->legacy())
            ->assertStatus(429);
        // 別の /64 は数えが別
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => '2001:db8:1:3::1'], $this->legacy())
            ->assertNoContent();
    }

    /**
     * 数えの鍵は APP_KEY の HMAC。database のキャッシュの表の鍵に接続元の IP が残らない
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

        try {
            Log::shouldReceive('warning');
            $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => '203.0.113.77'], $this->legacy())
                ->assertNoContent();
            $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => '2001:db8:1:2::5'], $this->legacy())
                ->assertNoContent();

            $keys = DB::table('cache')->pluck('key')->all();
            $this->assertNotEmpty($keys);
            foreach ($keys as $key) {
                $this->assertStringNotContainsString('203.0.113.77', $key);
                $this->assertStringNotContainsString('2001:db8', $key);
                $this->assertStringContainsString('foundation-attempts:csp-report:', $key);
            }
        } finally {
            Schema::dropIfExists('cache');
            $this->app->forgetInstance(CacheRateLimiter::class);
            RateLimiter::clearResolvedInstance(CacheRateLimiter::class);
        }
    }

    public function test_report_route_is_outside_web_group_and_sets_no_cookie(): void
    {
        $route = Route::getRoutes()->getByName('foundation.csp-report');
        $this->assertNotNull($route);

        $middleware = implode(' ', array_map(fn ($m) => is_string($m) ? $m : get_debug_type($m), $route->gatherMiddleware()));
        foreach (['web', 'Session', 'VerifyCsrfToken', 'PreventRequestForgery', 'EncryptCookies', 'AddQueuedCookies'] as $needle) {
            $this->assertStringNotContainsString($needle, $middleware, $needle);
        }

        Log::shouldReceive('warning')->once();
        $res = $this->report($this->legacy());
        $res->assertNoContent();
        $this->assertCount(0, $res->headers->getCookies());
        $this->assertFalse($res->headers->has('Set-Cookie'));
    }

    public function test_global_transforms_are_skipped_and_large_json_still_gets_413(): void
    {
        // ふつうの Laravel アプリのグローバルのミドルウェア（Testbench は空なので足す）
        $kernel = $this->app[Kernel::class];
        $kernel->pushMiddleware(\Illuminate\Foundation\Http\Middleware\TrimStrings::class);
        $kernel->pushMiddleware(\Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class);
        $kernel->pushMiddleware(JsonProbe::class);

        // TrimStrings・ConvertEmptyStringsToNull は JSON の本文の全体を作り替える。この経路では飛ばす
        Log::shouldReceive('warning')->once();
        $this->report(json_encode(['csp-report' => ['blocked-uri' => '  x  ', 'document-uri' => '']]), 'application/json')
            ->assertNoContent();
        $this->assertSame(['blocked-uri' => '  x  ', 'document-uri' => ''], JsonProbe::$json['csp-report']);

        $body = json_encode(['csp-report' => ['blocked-uri' => str_repeat('a', 200000)]]);
        foreach (['application/json', 'application/reports+json'] as $type) {
            $this->report($body, $type)->assertStatus(413);
        }
    }
}
