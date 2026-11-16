<?php

namespace Miraqceed\Foundation\Tests\Feature\Health;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use LogicException;
use Miraqceed\Foundation\Health\Health;
use Miraqceed\Foundation\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * withRouting() の health: と then: の Health::route() が重なったとき。
 * Testbench の defineRoutes は withRouting() を通らないので、試しの中で本物のアプリと同じ形のアプリを作る。
 * withRouting() は health: の経路を then: より先に登録し、同じメソッドと道の経路は後のものが置き換える。
 */
class HealthRouteOverlapTest extends TestCase
{
    /** @var list<Throwable> */
    private array $reported = [];

    protected function tearDown(): void
    {
        // 作ったアプリが入れ替えた入れ物とファサードを、Testbench のアプリに戻す
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        parent::tearDown();
    }

    private function makeApp(?string $health, Closure $then, string $env): Application
    {
        $app = Application::configure(basePath: default_skeleton_path())
            ->withRouting(health: $health, then: $then)
            ->withMiddleware(function () {})
            ->withExceptions(function (Exceptions $exceptions) {
                $exceptions->report(function (Throwable $e) {
                    $this->reported[] = $e;

                    return false;
                });
            })
            ->create();

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($env) {
            $app['env'] = $env;
            $app['config']->set('app.env', $env);
        });

        // HandleExceptions は飛ばす（PHP のエラーの受け手を差し替え、試しの枠が後始末の漏れとして咎める）。
        // 経路は BootProviders の中で読まれる（then: もそこで動く）
        $app->bootstrapWith([
            LoadEnvironmentVariables::class,
            LoadConfiguration::class,
            RegisterFacades::class,
            RegisterProviders::class,
            BootProviders::class,
        ]);

        return $app;
    }

    private function request(Application $app, string $path): Response
    {
        return $app->make(HttpKernel::class)->handle(Request::create($path));
    }

    /**
     * 稼働の確かめの道（up・healthz）だけを並べる。Testbench の骨組みはほかの経路も足す（12 の storage/{path} など）
     *
     * @return list<string>
     */
    private function uris(Application $app): array
    {
        $uris = array_map(fn ($route) => $route->uri(), $app['router']->getRoutes()->getRoutes());

        return array_values(array_filter($uris, fn (string $uri) => in_array($uri, ['up', 'healthz'], true)));
    }

    public function test_既定と同じ道なら自前が勝つ(): void
    {
        $app = $this->makeApp('/up', fn () => Health::route('/up'), 'testing');

        $response = $this->request($app, '/up');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame(['up'], $this->uris($app));
        // route:cache も通る
        $app['router']->getRoutes()->toSymfonyRouteCollection();
        $this->assertSame([], $this->reported);
    }

    public function test_2回呼んでも同じ道なら1つ(): void
    {
        $app = $this->makeApp(null, function () {
            Health::route('/up');
            Health::route('/up');
        }, 'testing');

        $this->assertSame(['up'], $this->uris($app));
        $this->assertSame('OK', $this->request($app, '/up')->getContent());
    }

    public function test_試しの中では既定が別の道に残っていれば止める(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('withRouting() から health: を消してください');

        $this->makeApp('/up', fn () => Health::route('/healthz'), 'testing');
    }

    public function test_試しの中では別の道で2回呼べば止める(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Health::route() は1つの道にだけ使ってください');

        $this->makeApp(null, function () {
            Health::route('/up');
            Health::route('/healthz');
        }, 'testing');
    }

    public function test_本番では既定が別の道に残っていても止めない(): void
    {
        $app = $this->makeApp('/up', fn () => Health::route('/healthz'), 'production');

        // 既定の /up は残る（説明書の「重ねて登録したとき」）。消し忘れはアプリの試しで見つける
        $default = $this->request($app, '/up');
        $this->assertSame(200, $default->getStatusCode());
        $this->assertStringContainsString('<script', (string) $default->getContent());
        $this->assertSame('OK', $this->request($app, '/healthz')->getContent());
        $this->assertSame([], $this->reported);
    }

    public function test_本番では別の道で2回呼んでも止めず_route_cache_が止める(): void
    {
        $app = $this->makeApp(null, function () {
            Health::route('/up');
            Health::route('/healthz');
        }, 'production');

        $this->assertSame('OK', $this->request($app, '/up')->getContent());
        $this->assertSame('OK', $this->request($app, '/healthz')->getContent());
        $this->assertSame([], $this->reported);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('foundation.health');
        $app['router']->getRoutes()->toSymfonyRouteCollection();
    }
}
