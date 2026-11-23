<?php

namespace Miraqceed\Foundation\Tests\Feature\Integrity;

use Miraqceed\Foundation\DeployCheck\Checks\Integrity as IntegrityCheck;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Integrity;
use Miraqceed\Foundation\Integrity\ViewsProcess;
use Miraqceed\Foundation\Tests\Support\Integrity\IntegrityTree;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 木の中の SQLite のファイル（雛形のアプリの database/database.sqlite など）：sqlite の接続が指すものと -wal・-shm・-journal を、
 * ふつうのファイルのときだけ自動で外す。PHP として動く名前・調べが読み込む場所の中のものは外さずに知らせる。一覧の設定にも入る
 */
class SqliteFilesTest extends TestCase
{
    use IntegrityTree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTree();
    }

    protected function tearDown(): void
    {
        $this->tearDownTree();
        parent::tearDown();
    }

    private function useSqlite(string $database): void
    {
        config(['database.connections.integrity_sqlite' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '']]);
    }

    public function test_the_database_file_and_its_companions_are_excluded_while_they_change(): void
    {
        $this->useSqlite($this->root.'/database/database.sqlite');
        $this->writeFile('database/database.sqlite', 'made-up database 1');
        $this->writeFile('database/database.sqlite-wal', 'made-up wal 1');
        $this->writeFile('database/other.sqlite', 'not the database');
        $this->assertSame(0, $this->snapshot());

        $this->writeFile('database/database.sqlite', 'made-up database 2');
        $this->writeFile('database/database.sqlite-wal', 'made-up wal 2');
        $this->writeFile('database/database.sqlite-shm', 'made-up shm');
        $this->writeFile('database/database.sqlite-journal', 'made-up journal');
        $this->writeFile('database/other.sqlite', 'changed');
        $report = $this->check();

        $this->assertSame(['changed:database/other.sqlite'], $this->findings($report));
        $this->assertSame([], $this->gaps($report));
    }

    public function test_a_relative_path_is_taken_from_the_root_only_when_the_file_exists(): void
    {
        $this->writeFile('database/rel.sqlite', 'made-up database 1');
        // 雛形の設定は sqlite の接続も DB_DATABASE を読む。MySQL のデータベースの名前は、根からの道に見えても外さない
        config(['database.connections.integrity_sqlite' => ['driver' => 'sqlite', 'database' => 'database/rel.sqlite', 'prefix' => ''],
            'database.connections.integrity_named' => ['driver' => 'sqlite', 'database' => 'app', 'prefix' => '']]);
        $this->snapshot();
        $this->writeFile('database/rel.sqlite', 'made-up database 2');

        $report = $this->check();

        $this->assertSame([], $this->findings($report));
        $this->assertSame([], $this->gaps($report));
    }

    public function test_a_link_or_a_folder_in_its_place_is_compared(): void
    {
        $this->useSqlite($this->root.'/database/database.sqlite');
        $this->writeFile('database/database.sqlite', 'made-up database');
        $this->snapshot();

        unlink($this->root.'/database/database.sqlite');
        symlink($this->root.'/.env', $this->root.'/database/database.sqlite');
        mkdir($this->root.'/database/database.sqlite-wal');
        $this->writeFile('database/database.sqlite-wal/x.txt', 'x');
        $report = $this->check();

        $this->assertFinding($report, Finding::Added, 'database/database.sqlite');
        $this->assertFinding($report, Finding::Added, 'database/database.sqlite-wal/x.txt');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function notExcluded(): iterable
    {
        yield 'a PHP name' => ['database/x.sqlite.php', 'database/x.sqlite.php'];
        yield 'inside a load path' => ['config/db.sqlite', 'config/db.sqlite'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notExcluded')]
    public function test_a_php_name_or_a_load_path_is_not_excluded_and_is_told(string $database, string $told): void
    {
        $this->useSqlite($this->root.'/'.$database);
        $this->writeFile($database, 'made-up database 1');
        $this->snapshot();
        $this->writeFile($database, 'made-up database 2');

        $report = $this->check();

        $this->assertFinding($report, Finding::Changed, $database);
        $this->assertContains('sqlite-not-excluded', array_map(fn ($g) => $g[0]->value, $report->gaps()));
        $this->assertStringContainsString($told, implode(', ', $this->gaps($report)));
    }

    public function test_the_excluded_files_are_in_the_snapshot_settings_and_the_strictest_wins(): void
    {
        $this->writeFile('database/database.sqlite', 'made-up database 1');
        $this->snapshot();
        $this->useSqlite($this->root.'/database/database.sqlite');
        $this->writeFile('database/database.sqlite', 'made-up database 2');

        $report = $this->check();

        $this->assertGap($report, Gap::SettingsDiffer);
        $this->assertFinding($report, Finding::Changed, 'database/database.sqlite');
    }

    public function test_the_deploy_check_fails_when_a_database_file_cannot_be_excluded(): void
    {
        $this->app->instance(ViewsProcess::class, new ViewsProcess(available: true));
        config(['foundation.monitoring.enabled' => true, 'foundation.monitoring.alerts.channels' => ['log', 'mail']]);
        Integrity::schedule('04:40');
        $this->useSqlite($this->root.'/database/database.sqlite');
        $this->snapshot();
        $this->assertSame(Status::Ok, (new IntegrityCheck)->run()->status);

        $this->useSqlite($this->root.'/config/db.sqlite');
        $this->snapshot();
        $result = (new IntegrityCheck)->run();

        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString(Gap::SqliteNotExcluded->label(), (string) $result->detail);
    }
}
