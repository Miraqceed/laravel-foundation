<?php

namespace Miraqceed\Foundation\Tests\Feature\Security;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * route:cache のときは経路をパッケージが登録しない（キャッシュに入っている）が、
 * 全体の TrimStrings・ConvertEmptyStringsToNull を飛ばす設定はキャッシュされないので、いつも登録する。
 */
class CspReportCachedRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('foundation.security.report_route', '/csp-report');
        // route:cache の後と同じ扱いにする
        $app->instance('routes.cached', true);
    }

    /** @return list<\Closure> */
    private static function skipCallbacks(string $class): array
    {
        $property = new \ReflectionProperty($class, 'skipCallbacks');

        return $property->getValue();
    }

    public function test_skip_when_is_registered_even_when_routes_are_cached(): void
    {
        $this->assertTrue($this->app->routesAreCached());
        // 経路はキャッシュから読まれるので、パッケージは登録しない
        $this->assertNull(Route::getRoutes()->getByName('foundation.csp-report'));

        $report = Request::create('/csp-report', 'POST');
        $other = Request::create('/login', 'POST');
        foreach ([TrimStrings::class, ConvertEmptyStringsToNull::class] as $class) {
            $callbacks = self::skipCallbacks($class);
            $this->assertTrue(collect($callbacks)->contains(fn ($cb) => $cb($report)), $class);
            $this->assertFalse(collect($callbacks)->contains(fn ($cb) => $cb($other)), $class);
        }
    }
}
