<?php

namespace Miraqceed\Foundation\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Tests\Support\SkeletonApp;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 本物の `php artisan test`（単独・`--parallel`）を、使い捨てのアプリで流して確かめる。
 *
 * 事故の形：開発用の DB を環境変数で渡して流すと、テストがそこへつなぎ、RefreshDatabase が中身を消した。
 * ここでは目印の行を入れた使い捨ての DB（名前に印がない）を環境変数で渡し、親プロセス・子プロセスとも、その DB に触れずに止まり、
 * 目印の行が残っていることを確かめる。PostgreSQL・MySQL の組み合わせで流す（sqlite では飛ばす）。
 */
class ParallelGuardTest extends TestCase
{
    private const ADMIN = 'foundation_guard_admin';

    private const DEV = 'foundation_guard_dev';

    private string $driver;

    private string $prefix;

    private string $devDatabase;

    private string $okDatabase;

    private ?SkeletonApp $skeleton = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = (string) env('DB_CONNECTION', 'sqlite');
        if (! in_array($this->driver, ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('PostgreSQL・MySQL の組み合わせで流す');
        }

        $this->prefix = 'fdn_guard_'.bin2hex(random_bytes(4));
        $this->devDatabase = $this->prefix.'_dev';
        $this->okDatabase = $this->prefix.'_test';

        // 印のない DB を読むつなぎ先は、印を偽らずに ignore_connections で外す
        config(['foundation.test_safety.ignore_connections' => [self::DEV]]);
        config(['database.connections.'.self::ADMIN => $this->connection((string) env('DB_DATABASE'))]);
        config(['database.connections.'.self::DEV => $this->connection($this->devDatabase)]);

        $this->dropDatabases();
        DB::connection(self::ADMIN)->statement($this->quoted('create database %s', $this->devDatabase));
        DB::connection(self::ADMIN)->statement($this->quoted('create database %s', $this->okDatabase));
        // 開発用の DB らしく、migrations の表を持たせる（migrate:fresh はこの表があるときだけ全部の表を消す。事故の DB もそうだった）
        $schema = DB::connection(self::DEV)->getSchemaBuilder();
        $schema->create('migrations', function ($table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $schema->create('sentinels', fn ($table) => $table->integer('id'));
        DB::connection(self::DEV)->table('migrations')->insert(['migration' => '2026_01_01_000000_create_sentinels_table', 'batch' => 1]);
        DB::connection(self::DEV)->table('sentinels')->insert(['id' => 1]);

        $this->skeleton = SkeletonApp::create([
            'tests/Feature/FirstTest.php' => $this->testFile('FirstTest'),
            'tests/Feature/SecondTest.php' => $this->testFile('SecondTest'),
        ]);
        $this->skeleton->write('phpunit.ok.xml', SkeletonApp::phpunitXml($this->skeleton->path, ['DB_DATABASE' => $this->okDatabase]));
    }

    protected function tearDown(): void
    {
        $this->skeleton?->remove();
        if (isset($this->prefix)) {
            DB::purge(self::DEV);
            $this->dropDatabases();
            DB::purge(self::ADMIN);
        }
        parent::tearDown();
    }

    public function test_single_process_run_is_stopped_before_refresh_database_wipes_it(): void
    {
        // 事故そのもの：1本だけ順に流すと、RefreshDatabase の migrate:fresh が開発用 DB の表をすべて消す
        $process = $this->artisanTest([], $this->devDatabase);

        $this->assertStopped($process);
        $this->assertSentinelIntact();
    }

    public function test_parallel_parent_process_is_stopped(): void
    {
        // paratest の親プロセスは、テストを配る前に既定の接続を作る（RunsInParallel::execute の setUpProcess →
        // TestDatabases::whenNotUsingInMemoryDatabase の DB::getConfig('database')。--recreate-databases ならそのまま元の DB へつなぐ）。
        // このときまだ PHPUnit の TestCase は読み込まれておらず、守りが働くのは `artisan test --parallel` が渡す LARAVEL_PARALLEL_TESTING による
        $process = $this->artisanTest(['--parallel', '--recreate-databases', '--processes=2'], $this->devDatabase);

        $this->assertStopped($process);
        $this->assertSame([], $this->skeleton->probes(), '親で止まったので、子のプロセスは1つも始まっていない');
        $this->assertSentinelIntact();
    }

    public function test_parallel_child_processes_are_stopped(): void
    {
        // --without-databases では、親は DB へつながず、子はプロセスごとの DB へ切り替えずに元の DB で RefreshDatabase を流す
        // （守りがなければ migrate:fresh が開発用 DB の表を消す）
        $process = $this->artisanTest(['--parallel', '--without-databases', '--processes=2'], $this->devDatabase);

        $this->assertStopped($process);
        $this->assertNotSame([], $this->skeleton->probes(), '子のプロセスが動いた');
        $this->assertSentinelIntact();
    }

    public function test_correct_environment_given_by_alternate_config_is_not_stopped(): void
    {
        $process = $this->artisanTest(['--parallel', '--recreate-databases', '--processes=2', '-c', 'phpunit.ok.xml'], null);

        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertNotSame([], $this->skeleton->probes());
        $this->assertNotSame([], $this->databasesLike($this->okDatabase.'_test_'), '子がプロセスごとの DB を作って流した');
        $this->assertSentinelIntact();
    }

    /**
     * collision の `artisan test` は、自分の知らない引数（--processes・-c）に当たるとそこで自分の引数を読むのをやめる
     * （その後ろの --recreate-databases などが黙って効かない）。自分の引数を先に並べる。paratest へはすべて渡る
     *
     * @param  list<string>  $options
     */
    private function artisanTest(array $options, ?string $database): \Symfony\Component\Process\Process
    {
        $admin = $this->admin();

        return $this->skeleton->run(['artisan', 'test', '--without-tty', ...$options], array_filter([
            'DB_CONNECTION' => $this->driver,
            'DB_HOST' => (string) env('DB_HOST', '127.0.0.1'),
            'DB_PORT' => (string) env('DB_PORT', $this->driver === 'pgsql' ? '5432' : '3306'),
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $admin['username'],
            'DB_PASSWORD' => $admin['password'],
        ], fn ($value) => $value !== null));
    }

    private function assertStopped(\Symfony\Component\Process\Process $process): void
    {
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('テスト用の印', $output);
        $this->assertStringContainsString($this->devDatabase, $output);
    }

    private function assertSentinelIntact(): void
    {
        DB::purge(self::DEV);
        $this->assertSame(1, DB::connection(self::DEV)->table('sentinels')->count());
    }

    private function testFile(string $class): string
    {
        return <<<PHP
            <?php
            namespace Tests\Feature;
            use Illuminate\Foundation\Testing\RefreshDatabase;
            use Illuminate\Support\Facades\Schema;
            class {$class} extends \Illuminate\Foundation\Testing\TestCase
            {
                use RefreshDatabase;

                protected function setUp(): void
                {
                    file_put_contents(getenv('PROBE_DIR').'/'.getmypid().'-{$class}', '1');
                    parent::setUp();
                }

                public function test_migrated(): void
                {
                    \$this->assertTrue(Schema::hasTable('items'));
                }
            }
            PHP;
    }

    /** @return array{username: string, password: string} */
    private function admin(): array
    {
        // MySQL の利用者 foundation は自分の DB しか作れない。プロセスごとの DB を作るので root で動かす
        return $this->driver === 'mysql'
            ? ['username' => 'root', 'password' => (string) env('DB_ROOT_PASSWORD', 'root')]
            : ['username' => (string) env('DB_USERNAME', 'foundation'), 'password' => (string) env('DB_PASSWORD', 'foundation')];
    }

    /** @return array<string, mixed> */
    private function connection(string $database): array
    {
        $admin = $this->admin();

        return array_merge(config("database.connections.{$this->driver}"), [
            'database' => $database,
            'username' => $admin['username'],
            'password' => $admin['password'],
        ]);
    }

    private function quoted(string $sql, string $database): string
    {
        return sprintf($sql, $this->driver === 'mysql' ? "`{$database}`" : "\"{$database}\"");
    }

    /** @return list<string> */
    private function databasesLike(string $prefix): array
    {
        $like = str_replace('_', '\\_', $prefix).'%';
        $rows = $this->driver === 'mysql'
            ? DB::connection(self::ADMIN)->select('select schema_name as name from information_schema.schemata where schema_name like ?', [$like])
            : DB::connection(self::ADMIN)->select('select datname as name from pg_database where datname like ?', [$like]);

        return array_map(fn ($row) => $row->name, $rows);
    }

    private function dropDatabases(): void
    {
        foreach ($this->databasesLike($this->prefix) as $database) {
            DB::connection(self::ADMIN)->statement($this->quoted(
                $this->driver === 'mysql' ? 'drop database if exists %s' : 'drop database if exists %s with (force)',
                $database,
            ));
        }
    }
}
