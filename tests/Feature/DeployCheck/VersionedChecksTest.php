<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Miraqceed\Foundation\DeployCheck\Checks\MailViewCache;
use Miraqceed\Foundation\DeployCheck\Checks\TrustedProxies;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\Support\DeployCheck\AppTrustProxies;
use Miraqceed\Foundation\Tests\Support\SkeletonApp;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * Laravel の版で振る舞いが変わる調べ：
 * - trusted_proxies：13.20.0 から trustProxies(at: '*') がすべてのアドレスを信頼する
 * - mail_view_cache：Markdown::withSecuredEncoding() は 12.15.0 から
 */
class VersionedChecksTest extends TestCase
{
    private ?SkeletonApp $skeleton = null;

    private ?string $compiled = null;

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        if (method_exists(Markdown::class, 'withoutSecuredEncoding')) {
            Markdown::withoutSecuredEncoding();
        }
        if ($this->compiled !== null) {
            @unlink($this->compiled);
        }
        $this->skeleton?->remove();
        parent::tearDown();
    }

    private static function newMeaning(): bool
    {
        return version_compare(Application::VERSION, '13.20.0', '>=');
    }

    /**
     * @return array{Status, ?string}
     */
    private function proxies(?string $mode): array
    {
        config(['foundation.deploy_check.proxies' => $mode]);
        $result = (new TrustedProxies)->run();

        return [$result->status, $result->detail];
    }

    public function test_trusted_proxies_の既定はどの形も_info(): void
    {
        [$status, $detail] = $this->proxies(null);
        $this->assertSame(Status::Info, $status);
        $this->assertStringContainsString('未設定', (string) $detail);

        TrustProxies::at('*');
        [$status, $detail] = $this->proxies(null);
        $this->assertSame(Status::Info, $status);
        $this->assertStringContainsString(self::newMeaning() ? '訪問者が X-Forwarded-For で IP を選べる' : '13.20 以降に上げると', (string) $detail);

        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_PROTO);
        [$status, $detail] = $this->proxies(null);
        $this->assertSame(Status::Info, $status);
        $this->assertStringContainsString('全員がプロキシの IP', (string) $detail);

        TrustProxies::flushState();
        TrustProxies::at(['10.0.0.0/8', '192.168.0.0/16']);
        [$status, $detail] = $this->proxies(null);
        $this->assertSame(Status::Info, $status);
        $this->assertSame('前段の IP・範囲: 2 件', $detail);
        $this->assertStringNotContainsString('10.0.0.0', (string) $detail);
    }

    public function test_trusted_proxies_の_required_は範囲を書いたときだけ通る(): void
    {
        [$status] = $this->proxies('required');
        $this->assertSame(Status::Fail, $status, '未設定');

        foreach (['*', '**', ' * '] as $at) {
            TrustProxies::flushState();
            TrustProxies::at($at);
            [$status, $detail] = $this->proxies('required');
            $this->assertSame(Status::Fail, $status, $at);
            // 13.19 までも失敗にし、版に触れる
            $this->assertStringContainsString(self::newMeaning() ? 'Laravel 13.20 以降' : 'Laravel 13.20 以降に上げると', (string) $detail);
        }

        // IP を運ぶ見出しを信頼しなくても、全員がプロキシの IP になるので失敗
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST);
        [$status, $detail] = $this->proxies('required');
        $this->assertSame(Status::Fail, $status);
        $this->assertStringContainsString('全員がプロキシの IP', (string) $detail);

        // Forwarded（RFC 7239）も IP を運ぶ見出し
        TrustProxies::withHeaders(Request::HEADER_FORWARDED);
        [, $detail] = $this->proxies('required');
        $this->assertStringNotContainsString('全員がプロキシの IP', (string) $detail);

        TrustProxies::flushState();
        TrustProxies::at('10.0.0.0/8, 172.16.0.0/12');
        $this->assertSame([Status::Ok, '前段の IP・範囲: 2 件'], $this->proxies('required'));
    }

    public function test_trusted_proxies_は_config_の値も読む(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
        $this->assertSame([Status::Ok, '前段の IP・範囲: 1 件'], $this->proxies('required'));
    }

    public function test_trusted_proxies_はアプリの_TrustProxies_の子を読めず_required_では失敗(): void
    {
        $this->app[HttpKernel::class]->pushMiddleware(AppTrustProxies::class);

        [$status, $detail] = $this->proxies(null);
        $this->assertSame(Status::Info, $status);
        $this->assertStringContainsString('読めない', (string) $detail);

        [$status] = $this->proxies('required');
        $this->assertSame(Status::Fail, $status);
    }

    /**
     * artisan の中では、bootstrap/app.php の trustProxies(at:, headers:) は HTTP の Kernel を作るまで入らない
     */
    public function test_artisan_の中でも_bootstrap_app_の_trustProxies_を読む(): void
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
            'config/foundation.php' => "<?php\nreturn ['deploy_check' => ['proxies' => 'required']];\n",
            'bootstrap/app.php' => $bootstrap("\$middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO)"),
        ]);
        $row = $this->trustedProxiesRow();
        $this->assertSame('fail', $row['status']);
        $this->assertStringContainsString('全員がプロキシの IP', (string) $row['detail']);

        $this->skeleton->write('bootstrap/app.php', $bootstrap("\$middleware->trustProxies(at: ['10.0.0.0/8'])"));
        $row = $this->trustedProxiesRow();
        $this->assertSame('ok', $row['status']);
        $this->assertSame('前段の IP・範囲: 1 件', $row['detail']);
    }

    /** @return array{id: string, status: string, label: string, detail: ?string} */
    private function trustedProxiesRow(): array
    {
        $process = $this->skeleton->run(['artisan', 'foundation:deploy-check', '--json']);
        $json = json_decode($process->getOutput(), true);
        $this->assertIsArray($json, $process->getOutput().$process->getErrorOutput());

        return array_column($json['checks'], null, 'id')['trusted_proxies'];
    }

    public function test_mail_view_cache(): void
    {
        if (! method_exists(Markdown::class, 'withSecuredEncoding')) {
            // 12.15.0 より前
            $this->assertSame(Status::Skip, (new MailViewCache)->run()->status);

            return;
        }

        // 使っていないアプリには当たらない
        $this->assertSame(Status::Skip, (new MailViewCache)->run()->status);

        Markdown::withSecuredEncoding();
        $this->compiled = app('blade.compiler')->getCompiledPath(app('view')->getFinder()->find('notifications::email'));
        @unlink($this->compiled);
        $this->assertSame(Status::Ok, (new MailViewCache)->run()->status, 'まだコンパイルされていない');

        // view:cache のコンパイル（素の echo）
        @mkdir(dirname($this->compiled), 0777, true);
        file_put_contents($this->compiled, '<?php echo e($greeting); ?>');
        $this->assertSame(Status::Fail, (new MailViewCache)->run()->status);

        // Markdown のコンパイル
        file_put_contents($this->compiled, '<?php echo new \Illuminate\Support\EncodedHtmlString($greeting); ?>');
        $this->assertSame(Status::Ok, (new MailViewCache)->run()->status);
    }
}
