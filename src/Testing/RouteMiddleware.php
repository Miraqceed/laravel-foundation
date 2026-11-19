<?php

namespace Miraqceed\Foundation\Testing;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Assert;

/**
 * 試しの助け：経路の中間処理が、実際にどの順で動くかを確かめる。
 *
 * 経路の中間処理は、Laravel の優先の表（middlewarePriority）で並べ替えられる。表にある中間処理は、先に出た表の中間処理より表で前なら、
 * その前へ割り込む。表にない中間処理は動かない。自前の認証を表に入れずに回数の制限（throttle）の後に書くと、後のまま動き、
 * $request->user() が null のまま数える。経路の定義を読んでも分からないので、試しで実際の並びを固定する。
 *
 * 比べる相手は、クラス・interface・親のクラスで当てる（AuthenticatesRequests を渡せば、印を持つ自前の認証も当たる）。
 * 全体の中間処理は含めない（いつも経路の中間処理より外で動く）。本番のコードからは呼ばない。
 */
final class RouteMiddleware
{
    /**
     * その要求が当たる経路の、実際に動く順の中間処理（クラス名と :引数。Router::gatherRouteMiddleware() の結果）
     *
     * @return list<string>
     */
    public static function for(string $method, string $uri): array
    {
        // HTTP の Kernel を作ってから読む。Kernel が中間処理の別名（throttle など）と優先の表をルーターに写すので、
        // 作る前（試しでまだ要求を送っていないとき）は、別名が解かれず、並べ替えも効かない
        app(HttpKernel::class);

        /** @var Router $router */
        $router = app('router');
        $route = $router->getRoutes()->match(Request::create($uri, strtoupper($method)));

        return array_values(array_filter($router->gatherRouteMiddleware($route), 'is_string'));
    }

    /** $first に当たる中間処理が、$second に当たるものより先に動く */
    public static function assertBefore(string $method, string $uri, string $first, string $second): void
    {
        $middleware = self::for($method, $uri);
        $a = self::position($middleware, $first);
        $b = self::position($middleware, $second);

        Assert::assertNotNull($a, "{$method} {$uri} に {$first} がありません：".implode(', ', $middleware));
        Assert::assertNotNull($b, "{$method} {$uri} に {$second} がありません：".implode(', ', $middleware));
        Assert::assertLessThan($b, $a, "{$method} {$uri} で {$first} が {$second} より先に動きません：".implode(', ', $middleware));
    }

    public static function assertHas(string $method, string $uri, string $middleware): void
    {
        $all = self::for($method, $uri);

        Assert::assertNotNull(self::position($all, $middleware), "{$method} {$uri} に {$middleware} がありません：".implode(', ', $all));
    }

    public static function assertMissing(string $method, string $uri, string $middleware): void
    {
        $all = self::for($method, $uri);

        Assert::assertNull(self::position($all, $middleware), "{$method} {$uri} に {$middleware} があります：".implode(', ', $all));
    }

    /** @param  list<string>  $middleware */
    private static function position(array $middleware, string $class): ?int
    {
        foreach ($middleware as $index => $entry) {
            $name = explode(':', $entry, 2)[0];
            if ($name === $class || is_a($name, $class, true)) {
                return $index;
            }
        }

        return null;
    }
}
