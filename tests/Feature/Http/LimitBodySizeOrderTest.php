<?php

namespace Miraqceed\Foundation\Tests\Feature\Http;

use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Miraqceed\Foundation\Http\LimitBodySize;
use Miraqceed\Foundation\Testing\RouteMiddleware;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 本文の上限は、優先の表で回数の制限（throttle）より前に並ぶ。全体に掛ければ、経路の中間処理より前に断る。
 * JSON の本文は、Laravel が要求を作るとき（Request::createFromBase()）に解くので、どの中間処理でも「解く前」には断れない
 */
class LimitBodySizeOrderTest extends TestCase
{
    public static bool $routeMiddlewareRan = false;

    protected function defineRoutes($router): void
    {
        $router->aliasMiddleware('probe', ProbeMiddleware::class);
        // throttle を先に書いても、本文の上限が前に動く
        $router->post('/throttled', fn () => 'ok')->middleware(['throttle:60,1', 'foundation.body-limit:100']);
        $router->post('/plain', fn () => 'ok')->middleware(['throttle:60,1']);
        $router->post('/probed', fn () => 'ok')->middleware(['probe']);
    }

    public function test_throttle_を先に書いても本文の上限が前に動く(): void
    {
        RouteMiddleware::assertBefore('POST', '/throttled', LimitBodySize::class, ThrottleRequests::class);
        $this->assertSame([LimitBodySize::class.':100', ThrottleRequests::class.':60,1'], RouteMiddleware::for('POST', '/throttled'));
    }

    public function test_掛けない経路の並びは変わらない(): void
    {
        $this->assertSame([ThrottleRequests::class.':60,1'], RouteMiddleware::for('POST', '/plain'));
    }

    public function test_全体に掛けると経路の中間処理より前に断る(): void
    {
        self::$routeMiddlewareRan = false;
        $this->app->make(HttpKernel::class)->prependMiddleware(LimitBodySize::class.':20');

        $this->call('POST', '/probed', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], str_repeat('a', 30))
            ->assertStatus(413);

        $this->assertFalse(self::$routeMiddlewareRan);
    }

    public function test_JSON_の本文は_Laravel_が要求を作るときに読む(): void
    {
        // 説明書の注意の裏付け：名乗った大きさで断っても、JSON の本文は既に読まれている（Web サーバーの上限でしか防げない）
        CountingStream::register();
        $stream = fopen('foundation-counting://body', 'r');

        $this->call('POST', '/throttled', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => '1000'], $stream)
            ->assertStatus(413);

        $this->assertGreaterThan(0, CountingStream::$reads);
        fclose($stream);
    }
}

class ProbeMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        LimitBodySizeOrderTest::$routeMiddlewareRan = true;

        return $next($request);
    }
}
