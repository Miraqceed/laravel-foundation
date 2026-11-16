<?php

namespace Miraqceed\Foundation\Tests\Feature\TestSafety;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Tests\Support\SkeletonApp;
use Miraqceed\Foundation\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * foundation.test_safety.prevent_stray_requests：テストの中だけ、Http::fake() に当たらない要求を送る前に止める。
 * 送り先は閉じた手元のポート（掛け損なっても外へは出ない）。
 */
class PreventStrayRequestsTest extends TestCase
{
    private const URL = 'http://127.0.0.1:9/charge';

    private ?SkeletonApp $skeleton = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->skeleton?->remove();
    }

    protected function enablePrevention($app): void
    {
        $app['config']->set('foundation.test_safety.prevent_stray_requests', true);
    }

    public function test_既定では掛けない(): void
    {
        $this->assertFalse(Http::preventingStrayRequests());
    }

    #[DefineEnvironment('enablePrevention')]
    public function test_真なら_fake_のない要求は送る前に止まる(): void
    {
        $this->assertTrue(Http::preventingStrayRequests());

        $this->expectException(StrayRequestException::class);
        Http::timeout(2)->get(self::URL);
    }

    #[DefineEnvironment('enablePrevention')]
    public function test_真でも_fake_に当たる要求は通り_当たらない要求は止まる(): void
    {
        Http::fake(['127.0.0.1:9/charge' => Http::response('ok')]);

        $this->assertSame('ok', Http::get(self::URL)->body());

        $this->expectException(StrayRequestException::class);
        Http::timeout(2)->get('http://127.0.0.1:9/other');
    }

    #[DefineEnvironment('enablePrevention')]
    public function test_アプリは試しの中で外せる(): void
    {
        Http::allowStrayRequests();

        $this->assertFalse(Http::preventingStrayRequests());
    }

    public function test_テストの外では真でも掛けない(): void
    {
        $this->skeleton = SkeletonApp::create([
            'config/foundation.php' => "<?php\nreturn ['test_safety' => ['prevent_stray_requests' => true]];\n",
            'routes/console.php' => <<<'PHP'
                <?php
                use Illuminate\Support\Facades\Artisan;
                use Illuminate\Support\Facades\Http;
                Artisan::command('probe:http', function () {
                    $this->line(Http::preventingStrayRequests() ? 'preventing' : 'not-preventing');
                });
                PHP,
        ]);

        // PHPUnit の外（artisan）
        $outside = $this->skeleton->run(['artisan', 'probe:http']);
        $this->assertSame(0, $outside->getExitCode(), $outside->getOutput().$outside->getErrorOutput());
        $this->assertStringContainsString('not-preventing', $outside->getOutput());

        // 並列の印があればテストの中とみなす（DB の守りと同じ判定）
        $parallel = $this->skeleton->run(['artisan', 'probe:http'], ['LARAVEL_PARALLEL_TESTING' => '1']);
        $this->assertSame(0, $parallel->getExitCode(), $parallel->getOutput().$parallel->getErrorOutput());
        $this->assertStringContainsString('preventing', $parallel->getOutput());
        $this->assertStringNotContainsString('not-preventing', $parallel->getOutput());
    }
}
