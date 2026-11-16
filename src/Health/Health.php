<?php

namespace Miraqceed\Foundation\Health;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use ReflectionFunction;

/**
 * 稼働の確かめの口（HealthCheck）を登録する助け。
 *
 * bootstrap/app.php の withRouting() で health: を消し、then: に書く：
 *     then: fn () => Health::route('/up'),
 * グループを付けずに登録するので、web のグループ（セッション・CSRF）を通らない。
 * パッケージは自分では登録しない（入れただけではアプリの経路は変わらない）。
 */
final class Health
{
    public const ROUTE_NAME = 'foundation.health';

    // Laravel 13 の既定の /up のコントローラー（12 にはないので、クラスの名前の文字で比べる）
    private const LARAVEL_HEALTH_CHECK = 'Illuminate\Foundation\Http\HealthCheck';

    public static function route(string $path = '/up'): RoutingRoute
    {
        $route = Route::get($path, HealthCheck::class)->name(self::ROUTE_NAME);

        // Laravel の health: と同じく、メンテナンス中も応答する
        PreventRequestsDuringMaintenance::except($path);

        // 重なりは試しの中（runningUnitTests()）と本番でない環境でだけ止める（testing は本番でない環境に含まれる）。
        // 経路をキャッシュしないアプリでは then: が要求のたびに動くので、本番で止めるとサイトの全部が 500 になる。
        // 本番では一覧も見ず、report() もしない（要求のたびにログが出るため）。名前の重なりは route:cache が止める
        if (! app()->isProduction()) {
            self::assertNoOverlap($route);
        }

        return $route;
    }

    /**
     * 同じ道の経路は後の登録が置き換えるので害がない（ここでは見えない）。
     * 別の道に残ったものだけを止める。
     */
    private static function assertNoOverlap(RoutingRoute $registered): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route === $registered || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if ($route->getName() === self::ROUTE_NAME) {
                throw new LogicException(sprintf(
                    'Health::route() は1つの道にだけ使ってください（/%s と /%s の両方に登録しています）。',
                    $route->uri() === '/' ? '' : $route->uri(),
                    $registered->uri() === '/' ? '' : $registered->uri(),
                ));
            }

            if (self::isLaravelDefault($route)) {
                throw new LogicException(sprintf(
                    'Laravel の既定の稼働の確かめが /%s に残っています。bootstrap/app.php の withRouting() から health: を消してください（Health::route() は /%s に登録しています）。',
                    $route->uri() === '/' ? '' : $route->uri(),
                    $registered->uri() === '/' ? '' : $registered->uri(),
                ));
            }
        }
    }

    private static function isLaravelDefault(RoutingRoute $route): bool
    {
        $uses = $route->getAction('uses');

        // 13：invokable のコントローラー
        if (is_string($uses)) {
            return Str::before($uses, '@') === self::LARAVEL_HEALTH_CHECK;
        }

        // 12：ApplicationBuilder の中で作ったクロージャ。Laravel がファイルを動かすと見分けられず、止めないだけになる
        if ($uses instanceof Closure) {
            $file = (new ReflectionFunction($uses))->getFileName();

            return $file !== false
                && str_ends_with(str_replace('\\', '/', $file), 'Foundation/Configuration/ApplicationBuilder.php');
        }

        return false;
    }
}
