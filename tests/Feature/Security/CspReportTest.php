<?php

namespace Miraqceed\Foundation\Tests\Feature\Security;

use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
        RateLimiter::clear('foundation-csp-report:127.0.0.1');
        Log::shouldReceive('warning');

        for ($i = 0; $i < 30; $i++) {
            $this->report($this->legacy())->assertNoContent();
        }
        $this->report($this->legacy())->assertStatus(429);
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
