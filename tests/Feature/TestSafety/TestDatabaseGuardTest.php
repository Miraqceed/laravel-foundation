<?php

namespace Miraqceed\Foundation\Tests\Feature\TestSafety;

use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\TestSafety\TestDatabaseGuard;
use Miraqceed\Foundation\TestSafety\UnsafeTestDatabase;
use Miraqceed\Foundation\Tests\Support\SkeletonApp;
use Miraqceed\Foundation\Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    private ?SkeletonApp $skeleton = null;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_SERVER = $this->server;
        $this->skeleton?->remove();
    }

    public function test_throws_when_env_overrides_database(): void
    {
        // フォーム担当 #834：コンテナの環境変数は $_SERVER に入り、phpunit.xml の force より勝つ
        $_SERVER['DB_DATABASE'] = 'app';
        $this->refreshApplication();

        try {
            DB::connection()->select('select 1');
            $this->fail('止まらなかった');
        } catch (UnsafeTestDatabase $e) {
            $this->assertStringContainsString('app', $e->getMessage());
            $this->assertStringContainsString('環境変数', $e->getMessage());
        }
    }

    public function test_throws_when_db_url_points_to_non_test_database(): void
    {
        // DB_URL は database の鍵より優先される。設定の鍵を読むと素通りする
        $this->connectionConfig('other', ['url' => 'pgsql://u:p@127.0.0.1:5432/app', 'database' => 'app_test']);

        $this->expectException(UnsafeTestDatabase::class);
        DB::connection('other');
    }

    public function test_marker_must_be_a_word(): void
    {
        $markers = ['test', 'testing'];

        foreach (['app_test', 'test_app', 'app-test', 'APP_TEST', 'app_test_test_3', 'testing', 'testing_test_2', 'app.test'] as $name) {
            $this->assertTrue(TestDatabaseGuard::isTestDatabase('pgsql', $name, $markers), $name);
        }
        foreach (['contest', 'app', 'testdb', 'apptest', 'attesting', ''] as $name) {
            $this->assertFalse(TestDatabaseGuard::isTestDatabase('pgsql', $name, $markers), $name);
        }
    }

    public function test_allows_sqlite_memory(): void
    {
        // Laravel 12・13 の雛形の phpunit.xml（sqlite・:memory:）
        $this->assertTrue(TestDatabaseGuard::isTestDatabase('sqlite', ':memory:', ['test', 'testing']));

        config(['database.connections.memory' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->assertSame([1], array_map(fn ($row) => (int) $row->one, DB::connection('memory')->select('select 1 as one')));
    }

    public function test_sqlite_file_uses_file_name(): void
    {
        $markers = ['test', 'testing'];

        $this->assertTrue(TestDatabaseGuard::isTestDatabase('sqlite', '/srv/app/database/testing.sqlite', $markers));
        $this->assertTrue(TestDatabaseGuard::isTestDatabase('sqlite', '/srv/app/database/test.sqlite', $markers));
        $this->assertFalse(TestDatabaseGuard::isTestDatabase('sqlite', '/srv/app/database/database.sqlite', $markers));
        // フォルダーの名前は見ない
        $this->assertFalse(TestDatabaseGuard::isTestDatabase('sqlite', '/srv/test/database.sqlite', $markers));
    }

    public function test_checks_non_default_connections_too(): void
    {
        $this->connectionConfig('other', ['database' => 'app']);

        $this->expectException(UnsafeTestDatabase::class);
        DB::connection('other');
    }

    public function test_ignored_connections_are_not_checked(): void
    {
        config(['foundation.test_safety.ignore_connections' => ['external']]);
        $this->connectionConfig('external', ['database' => 'app']);

        $this->assertSame('app', DB::connection('external')->getConfig('database'));
    }

    public function test_reconnecting_to_another_database_is_checked_again(): void
    {
        $this->connectionConfig('other', ['database' => 'app_test']);
        DB::connection('other');

        config(['database.connections.other.database' => 'app']);
        DB::purge('other');

        $this->expectException(UnsafeTestDatabase::class);
        DB::connection('other');
    }

    public function test_markers_are_configurable(): void
    {
        config(['foundation.test_safety.markers' => ['qa.env']]);

        $this->connectionConfig('other', ['database' => 'app_qa.env']);
        $this->assertSame('app_qa.env', DB::connection('other')->getConfig('database'));

        // 記号は文字どおりに扱う（. は任意の1文字ではない）
        $this->connectionConfig('second', ['database' => 'app_qaXenv']);
        $this->expectException(UnsafeTestDatabase::class);
        DB::connection('second');
    }

    public function test_does_not_open_a_connection(): void
    {
        // 誰も待っていない番号。つなぎに行けば、守りより先に接続の失敗になる
        $this->connectionConfig('other', ['database' => 'app', 'port' => 1]);

        try {
            DB::connection('other');
            $this->fail('止まらなかった');
        } catch (UnsafeTestDatabase $e) {
            $this->assertNull($e->getPrevious());
        }
        $this->assertArrayNotHasKey('other', DB::getConnections());
    }

    public function test_second_connection_call_is_also_stopped(): void
    {
        $this->connectionConfig('other', ['database' => 'app']);

        foreach ([1, 2] as $attempt) {
            try {
                DB::connection('other');
                $this->fail("{$attempt} 回目が止まらなかった");
            } catch (UnsafeTestDatabase) {
                // 例外を受け止めて続ける経路でも、次の呼び出しが手元の接続を返さない
            }
        }
        $this->assertArrayNotHasKey('other', DB::getConnections());
    }

    public function test_detected_under_pest(): void
    {
        $this->skeleton = SkeletonApp::create([
            'tests/Pest.php' => "<?php\nuses(Illuminate\\Foundation\\Testing\\TestCase::class)->in('Feature');\n",
            'tests/Feature/GuardTest.php' => <<<'PHP'
                <?php
                use Illuminate\Support\Facades\DB;
                use Miraqceed\Foundation\TestSafety\UnsafeTestDatabase;
                it('stops a database without the marker', function () {
                    expect(fn () => DB::connection()->select('select 1'))->toThrow(UnsafeTestDatabase::class);
                });
                PHP,
        ], withPest: true);
        touch($this->skeleton->path.'/database/app.sqlite');

        $process = $this->skeleton->run(['vendor/pestphp/pest/bin/pest', '--colors=never'], $this->sqliteEnv('app.sqlite'));

        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('1 passed', $output);
    }

    public function test_does_nothing_outside_tests(): void
    {
        $this->skeleton = SkeletonApp::create(withPest: true);
        touch($this->skeleton->path.'/database/database.sqlite');
        $env = $this->sqliteEnv('database.sqlite');

        // PHPUnit の外（artisan）では確かめない。Pest の関数を読んでいても同じ
        $outside = $this->skeleton->run(['artisan', 'probe:connect'], $env);
        $this->assertSame(0, $outside->getExitCode(), $outside->getOutput().$outside->getErrorOutput());
        $this->assertStringContainsString('connected', $outside->getOutput());

        // 並列の印があれば確かめる（paratest の親プロセスはテストのクラスを読む前に DB へつなぐことがある）
        $parallel = $this->skeleton->run(['artisan', 'probe:connect'], $env + ['LARAVEL_PARALLEL_TESTING' => '1']);
        $this->assertNotSame(0, $parallel->getExitCode());
        $this->assertStringContainsString('database.sqlite', $parallel->getOutput().$parallel->getErrorOutput());
        $this->assertStringNotContainsString('connected', $parallel->getOutput());
    }

    public function test_works_even_if_app_testcase_defines_create_application(): void
    {
        // Laravel 10 までの雛形のように、アプリの TestCase が自分でアプリを作る
        $this->skeleton = SkeletonApp::create([
            'tests/TestCase.php' => <<<'PHP'
                <?php
                namespace Tests;
                use Illuminate\Contracts\Console\Kernel;
                abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
                {
                    public function createApplication()
                    {
                        $app = require __DIR__.'/../bootstrap/app.php';
                        $app->make(Kernel::class)->bootstrap();

                        return $app;
                    }
                }
                PHP,
            'tests/Feature/GuardTest.php' => <<<'PHP'
                <?php
                namespace Tests\Feature;
                use Illuminate\Support\Facades\DB;
                use Miraqceed\Foundation\TestSafety\UnsafeTestDatabase;
                class GuardTest extends \Tests\TestCase
                {
                    public function test_stops(): void
                    {
                        $this->expectException(UnsafeTestDatabase::class);
                        DB::connection()->select('select 1');
                    }
                }
                PHP,
        ]);
        touch($this->skeleton->path.'/database/app.sqlite');

        $process = $this->skeleton->run(
            [dirname(__DIR__, 3).'/vendor/phpunit/phpunit/phpunit', '--configuration='.$this->skeleton->path.'/phpunit.xml', '--colors=never'],
            $this->sqliteEnv('app.sqlite'),
        );

        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('OK (1 test', $output);
    }

    /** @param array<string, mixed> $overrides */
    private function connectionConfig(string $name, array $overrides): void
    {
        config(["database.connections.{$name}" => $overrides + [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'username' => 'u',
            'password' => 'p',
            'prefix' => '',
        ]]);
    }

    /** @return array<string, string> */
    private function sqliteEnv(string $file): array
    {
        return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->skeleton->path.'/database/'.$file];
    }
}
