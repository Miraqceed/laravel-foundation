<?php

namespace Miraqceed\Foundation\Tests\Feature\Health;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Miraqceed\Foundation\Health\Health;
use Miraqceed\Foundation\TestSafety\UnsafeTestDatabase;
use Miraqceed\Foundation\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ConfiguredChecksTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Health::route();

        $router->get('/page', fn () => 'page');
    }

    protected function checkDatabase($app): void
    {
        $app['config']->set('foundation.health.checks', ['database']);
    }

    protected function misspellCheck($app): void
    {
        $app['config']->set('foundation.health.checks', ['databse']);
    }

    /**
     * 既定の接続を、つなげない DB へ向ける（ファイルのない SQLite。名前にテストの印を入れ、テストの DB の守りには止められない）。
     * 起動の後に変える（DefineEnvironment で変えると、試しの始まりには database.default が元に戻っていた）
     */
    private function breakDatabase(): void
    {
        config([
            'database.connections.broken' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent/foundation_test.sqlite',
                'prefix' => '',
            ],
            'database.default' => 'broken',
        ]);
    }

    public function test_既定では聞き手を登録しない(): void
    {
        $this->assertFalse(Event::hasListeners(DiagnosingHealth::class));
    }

    public function test_DB_を見ない設定なら_DB_が使えなくても_OK(): void
    {
        $this->breakDatabase();

        $this->get('/up')->assertOk()->assertContent('OK');
    }

    #[DefineEnvironment('checkDatabase')]
    public function test_DB_を見る設定で_DB_が使えれば_OK(): void
    {
        $this->assertTrue(Event::hasListeners(DiagnosingHealth::class));

        $this->get('/up')->assertOk()->assertContent('OK');
    }

    #[DefineEnvironment('checkDatabase')]
    public function test_DB_を見る設定で_DB_が使えなければ_NG(): void
    {
        $this->breakDatabase();
        Exceptions::fake();

        $response = $this->get('/up');

        $response->assertStatus(500);
        $this->assertSame('NG', $response->getContent());
        // DB につなげないことで NG になった（テストの DB の守りが止めたのではない）
        Exceptions::assertReportedCount(1);
        Exceptions::assertNotReported(UnsafeTestDatabase::class);
    }

    #[DefineEnvironment('misspellCheck')]
    public function test_知らない名前の確かめは_NG_になり_ほかの経路は動く(): void
    {
        Exceptions::fake();

        // 起動は止まらない
        $this->get('/page')->assertOk();

        $response = $this->get('/up');

        $response->assertStatus(500);
        $this->assertSame('NG', $response->getContent());
        Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), '[databse]'));
    }
}
