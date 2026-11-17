<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Miraqceed\Foundation\DeployCheck\Checks\TrustedHosts;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\Support\DeployCheck\AppTrustHosts;
use Miraqceed\Foundation\Tests\Support\DeployCheck\AppTrustProxies;
use Miraqceed\Foundation\Tests\Support\SkeletonApp;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * trusted_hosts：TrustHosts があれば ok。なければ、'*'・'**' で Host を運ぶ見出しを信じる設定は fail、ほかは later
 */
class TrustedHostsTest extends TestCase
{
    private ?SkeletonApp $skeleton = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.url', 'https://app.example.jp');
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        $this->skeleton?->remove();
        parent::tearDown();
    }

    /** @return array{Status, ?string} */
    private function check(): array
    {
        $result = (new TrustedHosts)->run();

        // detail に Host・アドレスの値を出さない
        foreach (['app.example.jp', '10.0.0.0'] as $value) {
            $this->assertStringNotContainsString($value, (string) $result->detail);
        }

        return [$result->status, $result->detail];
    }

    public function test_TrustHosts_がなく前段も信頼しなければ_later(): void
    {
        [$status, $detail] = $this->check();

        $this->assertSame(Status::Later, $status);
        $this->assertStringContainsString('TrustHosts がない', (string) $detail);
    }

    public function test_TrustHosts_がなく_星で_X_Forwarded_Host_を信じれば_fail(): void
    {
        foreach (['*', '**', ' * '] as $at) {
            TrustProxies::flushState();
            TrustProxies::at($at);

            [$status, $detail] = $this->check();
            $this->assertSame(Status::Fail, $status, $at);
            $this->assertStringContainsString('X-Forwarded-Host', (string) $detail);
        }

        // Forwarded（RFC 7239）の host= も Host を運ぶ
        TrustProxies::withHeaders(Request::HEADER_FORWARDED);
        $this->assertSame(Status::Fail, $this->check()[0]);
    }

    public function test_Host_を運ぶ見出しを外せば_later(): void
    {
        TrustProxies::at('*');
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);

        $this->assertSame(Status::Later, $this->check()[0]);
    }

    public function test_範囲を挙げていれば_later(): void
    {
        // 前段が訪問者の X-Forwarded-Host を消さずに渡すと偽れるが、前段の作りはパッケージからは見えない
        TrustProxies::at(['10.0.0.0/8']);

        $this->assertSame(Status::Later, $this->check()[0]);
    }

    public function test_TrustHosts_があれば_ok(): void
    {
        TrustProxies::at('*');
        $this->app[HttpKernel::class]->pushMiddleware(TrustHosts::class);

        $this->assertSame([Status::Ok, null], $this->check());
    }

    public function test_アプリの_TrustHosts_の子も_ok(): void
    {
        $this->app[HttpKernel::class]->pushMiddleware(AppTrustHosts::class);

        $this->assertSame(Status::Ok, $this->check()[0]);
    }

    public function test_アプリの_TrustProxies_の子は読めず_later(): void
    {
        $this->app[HttpKernel::class]->pushMiddleware(AppTrustProxies::class);

        [$status, $detail] = $this->check();
        $this->assertSame(Status::Later, $status);
        $this->assertStringContainsString('読めない', (string) $detail);
    }

    /**
     * artisan の中では、bootstrap/app.php の trustHosts()・trustProxies() は HTTP の Kernel を作るまで入らない
     */
    public function test_artisan_の中でも_bootstrap_app_の_trustHosts_を読む(): void
    {
        $bootstrap = fn (string $call) => <<<PHP
            <?php
            use Illuminate\\Foundation\\Application;
            use Illuminate\\Http\\Request;
            return Application::configure(basePath: dirname(__DIR__))
                ->withRouting(commands: __DIR__.'/../routes/console.php')
                ->withMiddleware(function (\$middleware) { {$call}; })
                ->withExceptions(function (\$exceptions) {})
                ->create();
            PHP;

        $this->skeleton = SkeletonApp::create([
            'bootstrap/app.php' => $bootstrap("\$middleware->trustProxies(at: '*')"),
        ]);
        $this->assertSame('fail', $this->trustedHostsRow()['status']);

        $this->skeleton->write('bootstrap/app.php', $bootstrap("\$middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO)"));
        $this->assertSame('later', $this->trustedHostsRow()['status']);

        $this->skeleton->write('bootstrap/app.php', $bootstrap("\$middleware->trustProxies(at: '*'); \$middleware->trustHosts()"));
        $this->assertSame('ok', $this->trustedHostsRow()['status']);
    }

    /** @return array{id: string, status: string, label: string, detail: ?string} */
    private function trustedHostsRow(): array
    {
        $process = $this->skeleton->run(['artisan', 'foundation:deploy-check', '--json']);
        $json = json_decode($process->getOutput(), true);
        $this->assertIsArray($json, $process->getOutput().$process->getErrorOutput());

        return array_column($json['checks'], null, 'id')['trusted_hosts'];
    }
}
