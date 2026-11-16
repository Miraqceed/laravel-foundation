<?php

namespace Miraqceed\Foundation\Tests\Feature\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Support\DatabaseTimezone;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * Laravel は日時を時差なしの文字列（アプリの時間帯の時刻）で書く。DB の接続の時間帯がアプリと違うと、時差を持つ列に時差の分ずれた時刻が入る。
 * 接続の時間帯と、アプリの時間帯の今の時差を比べる（名前でなく時差で）
 */
class DatabaseTimezoneTest extends TestCase
{
    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = (string) config('database.default');
        config(['app.timezone' => 'Asia/Tokyo']);
    }

    protected function tearDown(): void
    {
        config(["database.connections.{$this->connection}.timezone" => null]);
        DB::purge($this->connection);

        parent::tearDown();
    }

    private function requireServerDatabase(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('PostgreSQL・MySQL で流す');
        }
    }

    /** 接続の時間帯を替えて、つなぎ直す */
    private function connectWithTimezone(string $timezone): void
    {
        config(["database.connections.{$this->connection}.timezone" => $timezone]);
        DB::purge($this->connection);
    }

    /** アプリと同じ時差の、その DB での書き方（MySQL は時間帯の表を読み込んでいないことがあるので時差で書く） */
    private function sameAsApp(): string
    {
        return DB::connection()->getDriverName() === 'mysql' ? '+09:00' : 'Asia/Tokyo';
    }

    public function test_reports_a_mismatch_between_the_database_and_the_app(): void
    {
        $this->requireServerDatabase();
        $this->connectWithTimezone(DB::connection()->getDriverName() === 'mysql' ? '+00:00' : 'UTC');

        $message = DatabaseTimezone::mismatch();

        $this->assertNotNull($message);
        $this->assertStringContainsString($this->connection, $message);
        $this->assertStringContainsString('Asia/Tokyo', $message);
        $this->assertStringContainsString('+09:00', $message);
        $this->assertStringContainsString('+00:00', $message);
        $this->assertSame(0, DatabaseTimezone::databaseOffset());
    }

    public function test_finds_no_mismatch_when_they_agree(): void
    {
        $this->requireServerDatabase();
        $this->connectWithTimezone($this->sameAsApp());

        $this->assertNull(DatabaseTimezone::mismatch());
        $this->assertSame(9 * 3600, DatabaseTimezone::databaseOffset());
    }

    /** MySQL の接続を時差（+09:00）で書き、アプリを名前（Asia/Tokyo）で書いても、違うと読まない */
    public function test_compares_offsets_not_names(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL の時差の書き方で確かめる');
        }
        $this->connectWithTimezone('+09:00');

        $this->assertNull(DatabaseTimezone::mismatch());
    }

    /**
     * PostgreSQL は、時間帯に書いた「+09:00」を POSIX の書き方と読み、UTC より 9 時間西（時差 -09:00）にする。
     * 名前で比べると同じに見えるが、時差で比べるので、ずれとして見つかる（PostgreSQL では時間帯の名前で書く）
     */
    public function test_reports_a_bare_offset_that_postgres_reads_the_other_way(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL の時差の読み方で確かめる');
        }
        $this->connectWithTimezone('+09:00');

        $this->assertSame(-9 * 3600, DatabaseTimezone::databaseOffset());
        $this->assertStringContainsString('-09:00', (string) DatabaseTimezone::mismatch());
    }

    /**
     * 何のための確かめかの記録：ずれた接続では、アプリの今を時差なしの文字列で timestamptz に書くと、時差の分ずれて入る
     */
    public function test_mismatch_really_shifts_timestamptz(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL の timestamptz で確かめる');
        }

        foreach (['UTC' => 9 * 3600, 'Asia/Tokyo' => 0] as $timezone => $expectedShift) {
            $this->connectWithTimezone($timezone);
            DB::statement('create temporary table timezone_probe (at timestamptz)');
            $appNow = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
            DB::table('timezone_probe')->insert(['at' => $appNow->format('Y-m-d H:i:s')]);

            $stored = (int) DB::selectOne('select extract(epoch from at)::bigint as epoch from timezone_probe')->epoch;
            DB::statement('drop table timezone_probe');

            $this->assertEqualsWithDelta($expectedShift, $stored - $appNow->getTimestamp(), 5, $timezone);
        }
    }

    /** PostgreSQL で、名前の違う時間帯（Etc/GMT-9 は時差 +09:00）を、時差が同じなら違うと読まない */
    public function test_compares_offsets_not_names_on_postgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL の時間帯の名前で確かめる');
        }
        $this->connectWithTimezone('Etc/GMT-9');

        $this->assertSame(9 * 3600, DatabaseTimezone::databaseOffset());
        $this->assertNull(DatabaseTimezone::mismatch());
    }

    public function test_sqlite_is_not_checked(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite で流す');
        }

        $this->assertNull(DatabaseTimezone::databaseOffset());
        $this->assertNull(DatabaseTimezone::mismatch());
    }

    public function test_command_exits_one_on_mismatch_and_zero_otherwise(): void
    {
        $this->requireServerDatabase();

        $this->connectWithTimezone(DB::connection()->getDriverName() === 'mysql' ? '+00:00' : 'UTC');
        $this->assertSame(1, Artisan::call('foundation:db-timezone'));
        $this->assertSame(1, Artisan::call('foundation:db-timezone', ['--connection' => $this->connection]));

        $this->connectWithTimezone($this->sameAsApp());
        $this->assertSame(0, Artisan::call('foundation:db-timezone'));
    }

    public function test_command_output_has_no_host_or_user_name(): void
    {
        $this->requireServerDatabase();
        $this->connectWithTimezone(DB::connection()->getDriverName() === 'mysql' ? '+00:00' : 'UTC');

        Artisan::call('foundation:db-timezone');
        $output = Artisan::output();

        $this->assertNotSame('', trim($output));
        foreach (['host', 'username', 'password'] as $key) {
            $value = (string) config("database.connections.{$this->connection}.{$key}");
            if ($value !== '') {
                $this->assertStringNotContainsString($value, $output, $key);
            }
        }
    }

    public function test_command_refuses_an_empty_connection_option(): void
    {
        $this->assertSame(2, Artisan::call('foundation:db-timezone', ['--connection' => '']));
        $this->assertStringContainsString('--connection', Artisan::output());
    }

    /**
     * つながらないときは、ホスト・ユーザー名・DB の名前を出さずに、ずれ（1）と別の終わりの値 2 で終わる。
     * 例外の文（Laravel の QueryException）は、ホスト・ポート・DB の名前を含む
     */
    public function test_command_hides_connection_details_when_it_cannot_connect(): void
    {
        foreach (['pgsql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql'] as $driver => $extension) {
            if (! extension_loaded($extension)) {
                continue;
            }
            config(["database.connections.kasou_{$driver}" => [
                'driver' => $driver,
                'host' => 'kasou-db-host.invalid',
                'port' => $driver === 'pgsql' ? 5432 : 3306,
                'database' => 'kasou_database_test',
                'username' => 'kasou_user_name',
                'password' => 'kasou-password',
            ]]);

            $code = Artisan::call('foundation:db-timezone', ['--connection' => "kasou_{$driver}"]);
            $output = Artisan::output();

            $this->assertSame(2, $code, $driver);
            $this->assertStringContainsString("kasou_{$driver}", $output);
            foreach (['kasou-db-host', 'kasou_database_test', 'kasou_user_name', 'kasou-password'] as $secret) {
                $this->assertStringNotContainsString($secret, $output, "{$driver}: {$secret}");
            }
            DB::purge("kasou_{$driver}");
        }

        if (! extension_loaded('pdo_pgsql') && ! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_pgsql か pdo_mysql が要る');
        }
    }

    public function test_command_reports_that_sqlite_is_not_checked(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite で流す');
        }

        $this->assertSame(0, Artisan::call('foundation:db-timezone'));
        $this->assertStringContainsString('見ません', Artisan::output());
    }
}
