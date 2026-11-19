<?php

namespace Miraqceed\Foundation\Turnstile;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * foundation.turnstile.routes の名前の経路だけで、書き込みの要求に Turnstile の確かめを求めるミドルウェア。
 * 経路に付けられない口（Fortify のログイン・登録・再設定の申請）のため、web のグループに入れる：
 *
 *   // bootstrap/app.php
 *   $middleware->web(append: \Miraqceed\Foundation\Turnstile\VerifyTurnstileOnNamedRoutes::class);
 *
 *   // config/foundation.php
 *   'turnstile' => ['routes' => ['login.store', 'register.store', 'password.email']],
 *
 * 名前に当たらない経路では何もしない（routes の既定は空）。経路に直に付けるなら VerifyTurnstile（いつも確かめる）を使う
 */
final class VerifyTurnstileOnNamedRoutes extends VerifyTurnstile
{
    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        $routes = config('foundation.turnstile.routes', []);
        if (! is_array($routes) || array_filter($routes, fn ($name) => ! is_string($name) || $name === '') !== []) {
            // 書き違いを黙って「どこも守らない」に寄せない
            throw new InvalidArgumentException('foundation.turnstile.routes は、経路の名前の文字列の配列にしてください');
        }

        if ($routes === [] || ! $request->routeIs(...$routes)) {
            return $next($request);
        }

        return parent::handle($request, $next, $action);
    }
}
