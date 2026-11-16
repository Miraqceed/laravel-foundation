<?php

namespace Miraqceed\Foundation\Tests\Feature\OneTimeCodes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\OneTimeCodes\CodeKind;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCodes;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * DB の接続の時間帯が、出したときと受け取るときで違っても、期限は動かない（列が時差なしの dateTime のため）。
 * MySQL の TIMESTAMP の列だと、接続の時間帯で変換が入り、期限がずれる。
 * つなぎ直すとトランザクションが消えるので、RefreshDatabase を使わず、表を作って消す。PostgreSQL・MySQL で流す
 */
class OneTimeCodesSessionTimezoneTest extends TestCase
{
    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('PostgreSQL・MySQL で流す');
        }
        $this->connection = (string) config('database.default');

        Schema::dropIfExists('foundation_one_time_codes');
        $migration = require __DIR__.'/../../../database/one-time-code-migrations/2026_11_18_000000_create_foundation_one_time_codes_table.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connectWithTimezone(null);
            Schema::dropIfExists('foundation_one_time_codes');
        }

        parent::tearDown();
    }

    private function connectWithTimezone(?string $timezone): void
    {
        config(["database.connections.{$this->connection}.timezone" => $timezone]);
        DB::purge($this->connection);
    }

    public function test_expiry_does_not_move_when_the_database_session_timezone_differs(): void
    {
        $codes = $this->app->make(OneTimeCodes::class);
        $kind = new CodeKind('token-claim', 'apc_');
        $mysql = DB::getDriverName() === 'mysql';

        $this->connectWithTimezone($mysql ? '+00:00' : 'UTC');
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC'));
        $before = $codes->issue($kind);
        $atExpiry = $codes->issue($kind);

        // 受け取るときは、DB の接続の時間帯が 9 時間ずれている
        $this->connectWithTimezone($mysql ? '+09:00' : 'Asia/Tokyo');
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:09:59', 'UTC'));
        $this->assertNotNull($codes->claim($kind, $before->code));
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:10:00', 'UTC'));
        $this->assertNull($codes->claim($kind, $atExpiry->code));
    }
}
