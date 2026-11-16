<?php

namespace Miraqceed\Foundation\Tests\Feature\Security;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Miraqceed\Foundation\Security\SecurityHeaders;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * グローバルの先頭に置いたとき（説明書の入れ方）。web のグループだけだと、ルートが決まる前に返る応答
 * （ルートに合わない 404・メンテナンス中の 503・大きすぎる POST の 413）にヘッダーが付かない。
 */
class GlobalSecurityHeadersTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // web のグループの Cookie の暗号に要る
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['foundation.security.enabled' => true]);
        $this->app[Kernel::class]->prependMiddleware(SecurityHeaders::class);
    }

    protected function tearDown(): void
    {
        $this->app->maintenanceMode()->deactivate();

        parent::tearDown();
    }

    private function assertProtected($response, string $area = 'web'): void
    {
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $expected = $area === 'api' ? "default-src 'none'" : "default-src 'self'";
        $this->assertStringContainsString($expected, $csp);
    }

    public function test_ルートに合わない_404_に付く(): void
    {
        $response = $this->get('/nowhere');

        $response->assertNotFound();
        $this->assertProtected($response);
    }

    public function test_ルートがなくても区分はパスで決まる(): void
    {
        $response = $this->get('/api/nowhere');

        $response->assertNotFound();
        $this->assertProtected($response, 'api');
    }

    public function test_メンテナンス中の_503_に付く(): void
    {
        Route::get('/page', fn () => 'ok');
        $this->app->maintenanceMode()->activate(['retry' => 60]);

        $response = $this->get('/page');

        $response->assertServiceUnavailable();
        $this->assertProtected($response);
    }

    public function test_大きすぎる_POST_の_413_に付く(): void
    {
        Route::post('/page', fn () => 'ok');

        $response = $this->call('POST', '/page', server: ['CONTENT_LENGTH' => 1024 * 1024 * 1024]);

        $response->assertStatus(413);
        $this->assertProtected($response);
    }

    public function test_グローバルだけでも画面の_nonce_と_CSP_の_nonce_が一致する(): void
    {
        Route::get('/page', fn () => Vite::cspNonce());

        $response = $this->get('/page');
        $nonce = $response->getContent();

        $this->assertNotSame('', $nonce);
        $this->assertStringContainsString("'nonce-{$nonce}'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_web_のグループにも残っていると見出しは1つで_nonce_も一致する(): void
    {
        $this->app[Kernel::class]->appendMiddlewareToGroup('web', 'foundation.security-headers');
        Route::middleware('web')->get('/page', fn () => Vite::cspNonce());

        $response = $this->get('/page');
        $nonce = $response->getContent();

        $this->assertCount(1, $response->headers->all('content-security-policy'));
        $this->assertCount(1, $response->headers->all('x-frame-options'));
        $this->assertStringContainsString("'nonce-{$nonce}'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_無効のときはグローバルに置いても何も付けない(): void
    {
        config(['foundation.security.enabled' => false]);

        $response = $this->get('/nowhere');

        $response->assertNotFound();
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('X-Frame-Options'));
    }
}
