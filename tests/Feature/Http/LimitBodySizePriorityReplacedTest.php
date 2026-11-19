<?php

namespace Miraqceed\Foundation\Tests\Feature\Http;

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Miraqceed\Foundation\Http\LimitBodySize;
use Miraqceed\Foundation\Testing\RouteMiddleware;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * アプリが bootstrap/app.php の withMiddleware() で、優先の表を priority([...]) で丸ごと書き換えても、パッケージの足した本文の上限が残る
 */
class LimitBodySizePriorityReplacedTest extends TestCase
{
    /** アプリの withMiddleware() と同じ時点（Kernel を作るとき）に、表を丸ごと書き換える */
    protected function resolveApplicationHttpMiddlewares($app)
    {
        parent::resolveApplicationHttpMiddlewares($app);

        $app->afterResolving(HttpKernel::class, function ($kernel) {
            $kernel->setMiddlewarePriority([StartSession::class, AuthenticatesRequests::class, ThrottleRequests::class, SubstituteBindings::class]);
        });
    }

    protected function defineRoutes($router): void
    {
        $router->post('/throttled', fn () => 'ok')->middleware(['throttle:60,1', 'foundation.body-limit:100']);
    }

    public function test_表を丸ごと書き換えても本文の上限が_throttle_の前に残る(): void
    {
        $this->assertSame(
            [StartSession::class, AuthenticatesRequests::class, LimitBodySize::class, ThrottleRequests::class, SubstituteBindings::class],
            $this->app->make(HttpKernel::class)->getMiddlewarePriority(),
        );
        RouteMiddleware::assertBefore('POST', '/throttled', LimitBodySize::class, ThrottleRequests::class);
    }
}
