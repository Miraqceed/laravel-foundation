<?php

namespace Miraqceed\Foundation\Tests\Feature\Health;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Miraqceed\Foundation\Health\Health;
use Miraqceed\Foundation\Health\HealthCheck;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

class HealthCheckTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        // アプリの bootstrap/app.php の then: と同じく、グループを付けずに登録する
        Health::route();

        $router->get('/page', fn () => 'page');
        $router->middleware('web')->get('/web-page', fn () => 'web');
    }

    protected function tearDown(): void
    {
        $this->app->maintenanceMode()->deactivate();

        parent::tearDown();
    }

    public function test_本文は文字だけで外のものを読まない(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringNotContainsString('<script', $response->getContent());
        $this->assertStringNotContainsString('http', $response->getContent());
    }

    public function test_異常なら500で原因を出さない(): void
    {
        Exceptions::fake();
        Event::listen(DiagnosingHealth::class, fn () => throw new RuntimeException('db:5432 に届かない'));

        $response = $this->get('/up');

        $response->assertStatus(500);
        $this->assertSame('NG', $response->getContent());
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'db:5432 に届かない');
    }

    public function test_デバッグ中でも原因を出さない(): void
    {
        // Laravel の既定の /up は、デバッグ中は投げ直し、エラーの画面に原因が出る
        config(['app.debug' => true]);
        Exceptions::fake();
        Event::listen(DiagnosingHealth::class, fn () => throw new RuntimeException('db:5432 に届かない'));

        $response = $this->get('/up');

        $response->assertStatus(500);
        $this->assertSame('NG', $response->getContent());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_メンテナンス中も応答する(): void
    {
        $this->app->maintenanceMode()->activate([]);

        $response = $this->get('/up');

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->get('/page')->assertServiceUnavailable();
    }

    public function test_セッションを作らない(): void
    {
        // 比べるもの：web のグループの経路はセッションの Cookie を返す
        $this->get('/web-page')->assertCookie(config('session.cookie'));

        $response = $this->get('/up');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_キャッシュされない(): void
    {
        $response = $this->get('/up');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_HEAD_でも応答する(): void
    {
        $this->call('HEAD', '/up')->assertOk();
    }

    public function test_経路のキャッシュの後も応答する(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->getName() === 'foundation.health');

        // クロージャでなくクラスで登録する（route:cache はクラスの名前で写す）
        $this->assertNotNull($route);
        $this->assertSame('up', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertIsString($route->getAction('uses'));
        $this->assertSame(HealthCheck::class, $route->getControllerClass());
    }
}
