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

    /** SQLite の頭の印（作り物の中身の前に付ける） */
    private const DB = "SQLite format 3\0";

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
        $this->writeFile('database/database.sqlite', self::DB.'made-up database 1');
        $this->writeFile('database/database.sqlite-wal', 'made-up wal 1');
        $this->writeFile('database/other.sqlite', 'not the database');
        $this->assertSame(0, $this->snapshot());

        $this->writeFile('database/database.sqlite', self::DB.'made-up database 2');
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
        $this->writeFile('database/rel.sqlite', self::DB.'made-up database 1');
        // 雛形の設定は sqlite の接続も DB_DATABASE を読む。MySQL のデータベースの名前は、根からの道に見えても外さない
        config(['database.connections.integrity_sqlite' => ['driver' => 'sqlite', 'database' => 'database/rel.sqlite', 'prefix' => ''],
            'database.connections.integrity_named' => ['driver' => 'sqlite', 'database' => 'app', 'prefix' => '']]);
        $this->snapshot();
        $this->writeFile('database/rel.sqlite', self::DB.'made-up database 2');

        $report = $this->check();

        $this->assertSame([], $this->findings($report));
        $this->assertSame([], $this->gaps($report));
    }

    public function test_a_link_or_a_folder_in_its_place_is_compared(): void
    {
        $this->useSqlite($this->root.'/database/database.sqlite');
        $this->writeFile('database/database.sqlite', self::DB.'made-up database');
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
        yield 'under public' => ['public/db.sqlite', 'public/db.sqlite'];
        yield 'public/.htaccess' => ['public/.htaccess', 'public/.htaccess'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notExcluded')]
    public function test_a_php_name_or_a_load_path_is_not_excluded_and_is_told(string $database, string $told): void
    {
        $this->useSqlite($this->root.'/'.$database);
        $this->writeFile($database, self::DB.'made-up database 1');
        $this->snapshot();
        $this->writeFile($database, self::DB.'made-up database 2');

        $report = $this->check();

        $this->assertFinding($report, Finding::Changed, $database);
        $this->assertContains('sqlite-not-excluded', array_map(fn ($g) => $g[0]->value, $report->gaps()));
        $this->assertStringContainsString($told, implode(', ', $this->gaps($report)));
    }

    public function test_the_excluded_files_are_in_the_snapshot_settings_and_the_strictest_wins(): void
    {
        $this->writeFile('database/database.sqlite', self::DB.'made-up database 1');
        $this->snapshot();
        $this->useSqlite($this->root.'/database/database.sqlite');
        $this->writeFile('database/database.sqlite', self::DB.'made-up database 2');

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

    /** SQLite の頭の印を持たないファイルは、接続が指していても外さない（中身を書き換えられた設定のファイルを黙らせない） */
    public function test_a_file_without_the_sqlite_header_is_compared_and_told(): void
    {
        $this->useSqlite($this->root.'/database/database.sqlite');
        $this->writeFile('database/database.sqlite', 'not a database 1');
        $this->assertSame(0, $this->snapshot());
        $this->writeFile('database/database.sqlite', 'not a database 2');

        $report = $this->check();

        $this->assertFinding($report, Finding::Changed, 'database/database.sqlite');
        $this->assertContains('sqlite-not-excluded:database/database.sqlite', $this->gaps($report));
    }

    /**
     * 大きさ 0 のふつうのファイルは、SQLite のファイルとして外す（SQLite は初めて書くまで空。雛形の接続を残した MySQL のアプリ・
     * touch する台本で、毎日の誤報にしない）。後で SQLite でない中身が書かれれば、比べに戻る
     */
    public function test_an_empty_database_file_is_excluded_until_something_not_sqlite_is_written(): void
    {
        $this->useSqlite($this->root.'/database/database.sqlite');
        $this->writeFile('database/database.sqlite', '');
        $this->assertSame(0, $this->snapshot());

        $report = $this->check();
        $this->assertSame([], $this->findings($report));
        $this->assertSame([], $this->gaps($report));

        $this->writeFile('database/database.sqlite', 'not a database');
        $report = $this->check();
        $this->assertFinding($report, Finding::Added, 'database/database.sqlite');
        $this->assertContains('sqlite-not-excluded:database/database.sqlite', $this->gaps($report));
    }
}
