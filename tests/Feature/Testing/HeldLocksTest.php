<?php

namespace Miraqceed\Foundation\Tests\Feature\Testing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Miraqceed\Foundation\Database\LockErrors;
use Miraqceed\Foundation\Database\LockNotAcquired;
use Miraqceed\Foundation\Database\LockWait;
use Miraqceed\Foundation\Database\NamedLock;
use Miraqceed\Foundation\Testing\HeldLocks;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\SkippedWithMessageException;
use RuntimeException;

/**
 * 試しの助け：2本目の接続で行・名前を握ったまま関数を動かす。中の処理は待ちの上限で止まり、終われば（投げても）放す
 */
class HeldLocksTest extends TestCase
{
    protected function tearDown(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::dropIfExists('held_lock_rows');
        }

        parent::tearDown();
    }

    private function createRows(): void
    {
        Schema::dropIfExists('held_lock_rows');
        Schema::create('held_lock_rows', function ($table) {
            $table->integer('id')->primary();
            $table->string('code')->unique();
        });
        DB::table('held_lock_rows')->insert([['id' => 1, 'code' => 'a'], ['id' => 2, 'code' => 'b']]);
    }

    private function skipOnSqlite(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('MySQL・PostgreSQL で流す');
        }
    }

    private function lockWait(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? (string) DB::selectOne("select current_setting('lock_timeout') as value")->value
            : (string) DB::selectOne('select @@SESSION.innodb_lock_wait_timeout as value')->value;
    }

    public function test_a_held_row_makes_the_code_under_test_give_up_after_about_a_second(): void
    {
        $this->skipOnSqlite();
        $this->createRows();
        $before = $this->lockWait();
        $started = microtime(true);

        $error = HeldLocks::row('held_lock_rows', 1, function (): ?QueryException {
            try {
                DB::table('held_lock_rows')->where('id', 1)->lockForUpdate()->first();
            } catch (QueryException $e) {
                return $e;
            }

            return null;
        });

        $waited = microtime(true) - $started;
        $this->assertNotNull($error);
        $this->assertTrue(LockErrors::isLockWaitTimeout($error));
        $this->assertGreaterThanOrEqual(0.9, $waited);
        $this->assertLessThan(3.0, $waited);
        $this->assertSame($before, $this->lockWait());
        // 他の行は握っていない。終われば握っていた行も放す
        $this->assertSame(1, app(LockWait::class)->atMost(1, fn (): int => (int) DB::table('held_lock_rows')->where('id', 1)->lockForUpdate()->value('id')));
    }

    public function test_a_column_other_than_id_can_select_the_row(): void
    {
        $this->skipOnSqlite();
        $this->createRows();

        $other = HeldLocks::row('held_lock_rows', 'b', fn () => app(LockWait::class)->atMost(1, fn (): int => (int) DB::table('held_lock_rows')->where('id', 1)->lockForUpdate()->value('id')), column: 'code');

        $this->assertSame(1, $other);
    }

    public function test_a_held_name_makes_named_lock_fail_and_is_released_after(): void
    {
        $this->skipOnSqlite();

        $refused = HeldLocks::name('invoice-number:2026', function (): bool {
            try {
                app(NamedLock::class)->run('invoice-number:2026', 0, fn () => null);
            } catch (LockNotAcquired) {
                return true;
            }

            return false;
        });

        $this->assertTrue($refused);
        $this->assertSame('free', app(NamedLock::class)->run('invoice-number:2026', 0, fn (): string => 'free'));
    }

    public function test_when_the_function_throws_everything_is_released(): void
    {
        $this->skipOnSqlite();
        $this->createRows();
        $before = $this->lockWait();

        foreach (['row', 'name'] as $kind) {
            try {
                $kind === 'row'
                    ? HeldLocks::row('held_lock_rows', 1, fn () => throw new RuntimeException('test failed'))
                    : HeldLocks::name('n', fn () => throw new RuntimeException('test failed'));
                $this->fail('関数の例外が要る');
            } catch (RuntimeException $e) {
                $this->assertSame('test failed', $e->getMessage());
            }
        }

        $this->assertSame($before, $this->lockWait());
        $this->assertSame(1, app(LockWait::class)->atMost(1, fn (): int => (int) DB::table('held_lock_rows')->where('id', 1)->lockForUpdate()->value('id')));
        $this->assertSame('free', app(NamedLock::class)->run('n', 0, fn (): string => 'free'));
    }

    public function test_an_uncommitted_row_stops_with_a_clear_message(): void
    {
        $this->skipOnSqlite();
        $this->createRows();
        DB::beginTransaction();
        DB::table('held_lock_rows')->insert(['id' => 3, 'code' => 'c']);

        try {
            HeldLocks::row('held_lock_rows', 3, fn () => null);
            $this->fail('LogicException が要る');
        } catch (LogicException $e) {
            $this->assertStringContainsString('DatabaseTruncation', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_sqlite_skips_the_test(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite だけ');
        }

        foreach ([fn () => HeldLocks::row('t', 1, fn () => null), fn () => HeldLocks::name('n', fn () => null)] as $call) {
            try {
                $call();
                $this->fail('試しを飛ばす例外が要る');
            } catch (SkippedWithMessageException $e) {
                $this->assertStringContainsString('SQLite', $e->getMessage());
            }
        }
    }
}
