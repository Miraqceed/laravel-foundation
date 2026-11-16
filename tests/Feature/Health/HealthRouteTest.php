<?php

namespace Miraqceed\Foundation\Tests\Feature\Health;

use Illuminate\Support\Facades\Route;
use Miraqceed\Foundation\Health\Health;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * Health::route() を呼ばないアプリ（入れただけ）と、道を変えたとき。
 */
class HealthRouteTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/page', fn () => 'page');
    }

    protected function tearDown(): void
    {
        $this->app->maintenanceMode()->deactivate();

        parent::tearDown();
    }

    public function test_入れただけでは経路が増えない(): void
    {
        $this->assertFalse(Route::has('foundation.health'));
        $this->get('/up')->assertNotFound();
    }

    public function test_道を変えられる(): void
    {
        $route = Health::route('/healthz');

        $this->assertSame('healthz', $route->uri());
        $this->assertSame('foundation.health', $route->getName());
        $this->get('/healthz')->assertOk()->assertContent('OK');
        $this->get('/up')->assertNotFound();

        // メンテナンス中も、変えた道が応答する
        $this->app->maintenanceMode()->activate([]);
        $this->get('/healthz')->assertOk()->assertContent('OK');
        $this->get('/page')->assertServiceUnavailable();
    }
}
