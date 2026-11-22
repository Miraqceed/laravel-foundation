<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Miraqceed\Foundation\Database\LockNotAcquired;
use Miraqceed\Foundation\Database\NamedLock;
use Miraqceed\Foundation\Tests\Support\LockConnections;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 試しの包み（RefreshDatabase）の中：名前つきのロックは動く（包みは外に数える）。アプリのトランザクションの中では断る。
 * PostgreSQL で取れなかった後も、包みのトランザクションが使える
 */
class NamedLockInTestTransactionTest extends TestCase
{
    use LockConnections;
    use RefreshDatabase;

    private const NAME = 'report:2026-03';

    protected function tearDown(): void
    {
        $this->closeLockConnections();

        parent::tearDown();
    }

    public function test_it_runs_inside_the_test_wrapper(): void
    {
        $this->assertSame(1, DB::transactionLevel());

        $this->assertSame('done', app(NamedLock::class)->run(self::NAME, 1, fn (): string => 'done'));
    }

    public function test_it_is_refused_inside_an_application_transaction_in_a_test_too(): void
    {
        $this->expectException(LogicException::class);

        DB::transaction(fn () => app(NamedLock::class)->run(self::NAME, 1, fn () => null));
    }

    public function test_the_test_wrapper_is_still_usable_after_the_lock_was_not_acquired(): void
    {
        $this->skipOnSqlite();
        $other = $this->otherConnection();
        $serverName = NamedLock::serverName(self::NAME);
        $other->getDriverName() === 'pgsql'
            ? $other->selectOne('select pg_advisory_lock(?::bigint)', [$serverName])
            : $other->selectOne('select GET_LOCK(?, 0)', [$serverName]);

        try {
            app(NamedLock::class)->run(self::NAME, 1, fn () => null);
            $this->fail('LockNotAcquired が要る');
        } catch (LockNotAcquired) {
            $this->addToAssertionCount(1);
        }

        // PostgreSQL：待ちの誤りは savepoint の中で起き、包みのトランザクションは壊れていない
        $this->assertSame(1, (int) DB::selectOne('select 1 as one')->one);
        $this->assertSame(1, DB::transactionLevel());
    }
}
