<?php

namespace Miraqceed\Foundation\Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Miraqceed\Foundation\Security\Nonce;
use Miraqceed\Foundation\Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    private string $hot;

    protected function setUp(): void
    {
        parent::setUp();

        config(['foundation.security.enabled' => true]);
        $this->hot = sys_get_temp_dir().'/foundation-hot-'.bin2hex(random_bytes(6));
        Vite::useHotFile($this->hot);

        $mw = 'foundation.security-headers';
        Route::get('/page', fn () => Vite::withEntryPoints(['resources/js/app.js'])->toHtml())->middleware($mw);
        Route::get('/plain', fn () => 'ok')->middleware($mw);
        Route::get('/admin', fn () => 'ok')->middleware($mw);
        Route::get('/admin/x', fn () => 'ok')->middleware($mw);
        Route::get('/api/x', fn () => response()->json(['a' => 1]))->middleware($mw);
        Route::get('/boom', fn () => throw new \RuntimeException('boom'))->middleware($mw);
        Route::get('/preset', fn () => response('ok')->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "default-src 'none'"))->middleware($mw);
    }

    protected function tearDown(): void
    {
        @unlink($this->hot);
        parent::tearDown();
    }

    private function directive(string $csp, string $name): string
    {
        foreach (explode('; ', $csp) as $d) {
            if (str_starts_with($d, $name.' ')) {
                return $d;
            }
        }

        return '';
    }

    public function test_enforces_csp_with_nonce_for_web_area(): void
    {
        file_put_contents($this->hot, '');
        @unlink($this->hot);
        $res = $this->get('/plain');
        $csp = $res->headers->get('Content-Security-Policy');
        $nonce = Nonce::get();

        $this->assertStringContainsString("'nonce-{$nonce}'", $this->directive($csp, 'script-src'));
        $this->assertStringContainsString("'nonce-{$nonce}'", $this->directive($csp, 'style-src'));
        $this->assertSame("style-src-attr 'unsafe-inline'", $this->directive($csp, 'style-src-attr'));
        $this->assertSame("frame-ancestors 'none'", $this->directive($csp, 'frame-ancestors'));
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $this->assertFalse($res->headers->has('Strict-Transport-Security'));

        // Vite のタグにも同じ nonce が付く（開発サーバーの形で出す）
        file_put_contents($this->hot, 'http://localhost:5173');
        $page = $this->get('/page');
        $this->assertStringContainsString('nonce="'.Nonce::get().'"', $page->getContent());
    }

    public function test_nonce_differs_per_request(): void
    {
        $this->get('/plain');
        $first = Nonce::get();
        $this->get('/plain');

        $this->assertNotSame($first, Nonce::get());
        $this->assertSame(16, strlen(base64_decode(Nonce::get(), true)));
    }

    public function test_admin_and_api_areas_use_their_own_policies(): void
    {
        $admin = $this->get('/admin/x')->headers->get('Content-Security-Policy');
        $this->assertSame("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $this->directive($admin, 'script-src'));
        $this->assertSame("style-src 'self' 'unsafe-inline'", $this->directive($admin, 'style-src'));
        $this->assertStringContainsString("'unsafe-inline'", $this->get('/admin')->headers->get('Content-Security-Policy'));

        $api = $this->get('/api/x')->headers->get('Content-Security-Policy');
        $this->assertSame("default-src 'none'; frame-ancestors 'none'", $api);
    }

    public function test_admin_area_has_no_nonce(): void
    {
        $admin = $this->get('/admin')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('nonce-', $this->directive($admin, 'script-src'));
        $this->assertStringNotContainsString('nonce-', $this->directive($admin, 'style-src'));
    }

    public function test_report_only_mode_keeps_frame_protection(): void
    {
        config(['foundation.security.report_only' => true]);
        $res = $this->get('/plain');

        $this->assertFalse($res->headers->has('Content-Security-Policy'));
        $this->assertTrue($res->headers->has('Content-Security-Policy-Report-Only'));
        $res->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_allows_vite_dev_server_only_when_hot_file_exists(): void
    {
        $without = $this->get('/plain')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('5173', $without);

        file_put_contents($this->hot, "http://localhost:5173\n");
        $csp = $this->get('/plain')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('http://localhost:5173', $this->directive($csp, 'script-src'));
        $this->assertStringContainsString('http://localhost:5173', $this->directive($csp, 'style-src'));
        $connect = $this->directive($csp, 'connect-src');
        $this->assertStringContainsString('http://localhost:5173', $connect);
        $this->assertStringContainsString('ws://localhost:5173', $connect);
    }

    public function test_dev_server_relaxes_style_src_without_nonce(): void
    {
        $prod = $this->get('/plain')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('nonce-', $this->directive($prod, 'style-src'));
        $this->assertStringNotContainsString("'unsafe-inline'", $this->directive($prod, 'style-src'));

        file_put_contents($this->hot, 'http://localhost:5173');
        $dev = $this->get('/plain')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('nonce-', $this->directive($dev, 'style-src'));
        $this->assertStringContainsString("'unsafe-inline'", $this->directive($dev, 'style-src'));
        // script-src の nonce は残る
        $this->assertStringContainsString('nonce-', $this->directive($dev, 'script-src'));
    }

    public function test_skips_csp_on_debug_exception_page(): void
    {
        config(['app.debug' => true]);
        $res = $this->get('/boom');

        $res->assertStatus(500);
        $this->assertFalse($res->headers->has('Content-Security-Policy'));
        $res->assertHeader('X-Frame-Options', 'DENY');

        config(['app.debug' => false]);
        $this->assertTrue($this->get('/boom')->headers->has('Content-Security-Policy'));
    }

    public function test_does_not_override_headers_already_set(): void
    {
        $res = $this->get('/preset');

        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $res->assertHeader('Content-Security-Policy', "default-src 'none'");
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_only_over_https_when_enabled(): void
    {
        $this->assertFalse($this->get('/plain')->headers->has('Strict-Transport-Security'));

        config(['foundation.security.hsts' => true]);
        $this->assertFalse($this->get('/plain')->headers->has('Strict-Transport-Security'));
        $this->get('https://localhost/plain')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_disabled_middleware_passes_through(): void
    {
        config(['foundation.security.enabled' => false]);
        $res = $this->get('/plain');

        $res->assertOk();
        foreach (['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy'] as $h) {
            $this->assertFalse($res->headers->has($h), $h);
        }
    }

    public function test_report_route_adds_report_uri(): void
    {
        config(['foundation.security.report_route' => 'csp-report']);

        foreach (['/plain', '/admin', '/api/x'] as $path) {
            $csp = $this->get($path)->headers->get('Content-Security-Policy');
            $this->assertStringContainsString('report-uri /csp-report', $csp, $path);
        }
    }
}
