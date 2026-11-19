<?php

namespace Miraqceed\Foundation\Tests\Feature\Testing;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Miraqceed\Foundation\Testing\RouteMiddleware;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 経路の実際の中間処理の並び。優先の表にある中間処理（Laravel の認証の印を持つもの）は前へ割り込み、表にないものは動かない
 */
class RouteMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->aliasMiddleware('auth.marked', MarkedAuth::class);
        $router->aliasMiddleware('auth.unmarked', UnmarkedAuth::class);

        // どちらも throttle の後に書く
        $router->get('/marked', fn () => 'ok')->middleware(['throttle:60,1', 'auth.marked']);
        $router->get('/unmarked', fn () => 'ok')->middleware(['throttle:60,1', 'auth.unmarked']);
    }

    public function test_認証の印を持つ自前の認証は_throttle_より前に並ぶ(): void
    {
        $this->assertSame([MarkedAuth::class, ThrottleRequests::class.':60,1'], RouteMiddleware::for('GET', '/marked'));

        // 印（interface）でも、クラスでも当たる
        RouteMiddleware::assertBefore('GET', '/marked', AuthenticatesRequests::class, ThrottleRequests::class);
        RouteMiddleware::assertBefore('GET', '/marked', MarkedAuth::class, ThrottleRequests::class);
    }

    public function test_表にない自前の認証を_throttle_の後に書くと後のまま動く(): void
    {
        $this->assertSame([ThrottleRequests::class.':60,1', UnmarkedAuth::class], RouteMiddleware::for('GET', '/unmarked'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('より先に動きません');

        RouteMiddleware::assertBefore('GET', '/unmarked', UnmarkedAuth::class, ThrottleRequests::class);
    }

    public function test_あるか_ないかを確かめる(): void
    {
        RouteMiddleware::assertHas('GET', '/marked', ThrottleRequests::class);
        RouteMiddleware::assertMissing('GET', '/marked', UnmarkedAuth::class);

        $this->expectException(AssertionFailedError::class);
        RouteMiddleware::assertHas('GET', '/marked', UnmarkedAuth::class);
    }

    public function test_当たる経路がなければ例外(): void
    {
        $this->expectException(NotFoundHttpException::class);

        RouteMiddleware::for('GET', '/missing');
    }
}

/** Laravel の認証の印を持つ自前の認証 */
class MarkedAuth implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}

/** 印を持たない自前の認証 */
class UnmarkedAuth
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
