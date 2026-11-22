<?php

namespace Miraqceed\Foundation\Tests\Feature\MachineTokens;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\MachineTokens\MachineKind;
use Miraqceed\Foundation\MachineTokens\MachineTokens;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * DB の接続の時間帯が、書いたときと読むときで違っても、猶予と期限は動かない（列が時差なしの dateTime のため）。
 * つなぎ直すとトランザクションが消えるので、RefreshDatabase を使わず、表を作って消す。PostgreSQL・MySQL で流す
 */
class MachineTokensSessionTimezoneTest extends TestCase
{
    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('PostgreSQL・MySQL で流す');
        }
        $this->connection = (string) config('database.default');

        Schema::dropIfExists(MachineTokens::TABLE);
        $migration = require __DIR__.'/../../../database/machine-token-migrations/2026_11_22_000000_create_foundation_machine_tokens_table.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connectWithTimezone(null);
            Schema::dropIfExists(MachineTokens::TABLE);
        }

        parent::tearDown();
    }

    /** 表を作って消したので、次のクラスが今の DB をそのまま使わないように移行の状態を戻す */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }

    private function connectWithTimezone(?string $timezone): void
    {
        config(["database.connections.{$this->connection}.timezone" => $timezone]);
        DB::purge($this->connection);
    }

    public function test_grace_and_pending_expiry_do_not_move_when_the_database_session_timezone_differs(): void
    {
        $tokens = $this->app->make(MachineTokens::class);
        $kind = new MachineKind('server-agent', 'sag_', 3600, graceSeconds: 600, pendingSeconds: 300);
        $mysql = DB::getDriverName() === 'mysql';

        $this->connectWithTimezone($mysql ? '+00:00' : 'UTC');
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC'));
        $current = $tokens->issue($kind, 'server:1')->token;
        $pending = $tokens->rotate($kind, $current)?->token;
        $unused = $tokens->issue($kind, 'server:2')->token;
        $unusedPending = $tokens->rotate($kind, $unused)?->token;
        $expiring = $tokens->rotate($kind, $tokens->issue($kind, 'server:3')->token)?->token;
        $this->assertNotNull($tokens->authenticate($kind, $pending));

        // 読むときは、DB の接続の時間帯が 9 時間ずれている
        $this->connectWithTimezone($mysql ? '+09:00' : 'Asia/Tokyo');
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:04:59', 'UTC'));
        $this->assertNotNull($tokens->authenticate($kind, $unusedPending));
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:05:00', 'UTC'));
        $this->assertNull($tokens->authenticate($kind, $expiring));
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:09:59', 'UTC'));
        $this->assertTrue($tokens->authenticate($kind, $current)?->viaPrevious);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:10:00', 'UTC'));
        $this->assertNull($tokens->authenticate($kind, $current));
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:59:59', 'UTC'));
        $this->assertFalse($tokens->authenticate($kind, $pending)?->shouldRotate);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 13:00:00', 'UTC'));
        $this->assertTrue($tokens->authenticate($kind, $pending)?->shouldRotate);
    }
}
