<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Miraqceed\Foundation\Database\LockErrors;
use Miraqceed\Foundation\Database\LockWait;
use Miraqceed\Foundation\Tests\Support\LockConnections;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * 行のロックを待つ時間の上限：中だけ効き、後で（投げても）元の値に戻る。設定できない・戻せないときの扱い。
 * 試しの包みのない試し（2本目の接続から見える、コミットした行で握る）
 */
class LockWaitTest extends TestCase
{
    use LockConnections;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'sqlite') {
            // 接続の値を、サーバーの既定と違う値にしておく（戻した先が、この値であることを確かめる）
            DB::getDriverName() === 'pgsql'
                ? DB::statement("SET lock_timeout = '45s'")
                : DB::statement('SET SESSION innodb_lock_wait_timeout = 45');
        }
    }

    protected function tearDown(): void
    {
        $this->closeLockConnections();
        if (DB::getDriverName() !== 'sqlite') {
            Schema::dropIfExists('lock_wait_rows');
        }

        parent::tearDown();
    }

    private function original(): string
    {
        return DB::getDriverName() === 'pgsql' ? '45s' : '45';
    }

    private function wait(): LockWait
    {
        return $this->app->make(LockWait::class);
    }

    public function test_the_limit_applies_inside_and_the_value_comes_back_after(): void
    {
        $this->skipOnSqlite();

        $inside = $this->wait()->atMost(7, fn (): string => $this->currentLockWait());

        $this->assertSame(DB::getDriverName() === 'pgsql' ? '7s' : '7', $inside);
        $this->assertSame($this->original(), $this->currentLockWait());
    }

    public function test_the_value_comes_back_when_the_work_throws(): void
    {
        $this->skipOnSqlite();

        try {
            $this->wait()->atMost(7, fn () => throw new RuntimeException('work failed'));
            $this->fail('処理の例外が要る');
        } catch (RuntimeException $e) {
            $this->assertSame('work failed', $e->getMessage());
        }

        $this->assertSame($this->original(), $this->currentLockWait());
    }

    public function test_a_held_row_is_given_up_after_about_the_limit(): void
    {
        $this->skipOnSqlite();
        $this->createRows();
        $holder = $this->otherConnection();
        $holder->beginTransaction();
        $holder->table('lock_wait_rows')->where('id', 1)->lockForUpdate()->first();

        $started = microtime(true);
        try {
            $this->wait()->atMost(1, fn () => DB::table('lock_wait_rows')->where('id', 1)->lockForUpdate()->first());
            $this->fail('待ちの誤りが要る');
        } catch (QueryException $e) {
            $waited = microtime(true) - $started;
            $this->assertTrue(LockErrors::isLockWaitTimeout($e), $e->getMessage());
            $this->assertGreaterThanOrEqual(0.9, $waited);
            $this->assertLessThan(3.0, $waited);
        }

        $this->assertSame($this->original(), $this->currentLockWait());
    }

    public function test_when_the_value_cannot_be_set_the_work_runs_and_it_is_recorded_once(): void
    {
        $this->skipOnSqlite();
        Exceptions::fake();
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'lock_timeout') || str_contains($query->sql, 'innodb_lock_wait_timeout')) {
                throw new RuntimeException('database gone 7000');
            }
        });

        $this->assertSame('done', $this->wait()->atMost(7, fn (): string => 'done'));
        $this->assertSame('again', $this->wait()->atMost(7, fn (): string => 'again'));

        $messages = collect(Exceptions::reported())->map(fn (Throwable $e): string => $e->getMessage())->all();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('LockWait', $messages[0]);
        $this->assertStringNotContainsString('database gone', $messages[0]);
        $this->assertStringNotContainsString('7', $messages[0]);
    }

    public function test_when_the_value_cannot_be_restored_outside_a_transaction_the_connection_is_dropped(): void
    {
        $this->skipOnSqlite();
        Exceptions::fake();
        $this->failRestore();

        try {
            $this->wait()->atMost(7, fn () => throw new LogicException('work failed'));
            $this->fail('処理の例外が要る');
        } catch (LogicException $e) {
            $this->assertSame('work failed', $e->getMessage());
        }

        $this->assertNull(DB::connection()->getRawPdo());
        $messages = collect(Exceptions::reported())->map(fn (Throwable $e): string => $e->getMessage())->all();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('元に戻せませんでした', $messages[0]);
        $this->assertStringNotContainsString('database gone', $messages[0]);
        $this->assertStringNotContainsString('45', $messages[0]);
    }

    public function test_when_the_value_cannot_be_restored_inside_a_transaction_the_connection_is_kept(): void
    {
        $this->skipOnSqlite();
        Exceptions::fake();
        DB::beginTransaction();
        $this->failRestore();

        try {
            $this->assertSame('done', $this->wait()->atMost(7, fn (): string => 'done'));
            $this->assertNotNull(DB::connection()->getRawPdo());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertCount(1, Exceptions::reported());
        } finally {
            DB::rollBack();
        }
    }

    public function test_postgres_after_a_lock_wait_error_inside_a_transaction_the_rollback_brings_the_value_back_silently(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL だけ（トランザクションが壊れる）');
        }
        Exceptions::fake();
        $this->createRows();
        $holder = $this->otherConnection();
        $holder->beginTransaction();
        $holder->table('lock_wait_rows')->where('id', 1)->lockForUpdate()->first();

        DB::beginTransaction();
        try {
            $this->wait()->atMost(1, fn () => DB::table('lock_wait_rows')->where('id', 1)->lockForUpdate()->first());
            $this->fail('待ちの誤りが要る');
        } catch (QueryException $e) {
            $this->assertTrue(LockErrors::isLockWaitTimeout($e));
        } finally {
            DB::rollBack();
        }

        $this->assertSame($this->original(), $this->currentLockWait());
        $this->assertSame([], Exceptions::reported());
    }

    public function test_postgres_a_limit_set_outside_a_transaction_that_cannot_be_restored_is_recorded(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL だけ（トランザクションが壊れる）');
        }
        Exceptions::fake();

        // 外で設定した後、work がトランザクションを始め、壊れたまま（巻き戻さずに）投げる。外で設定した値は確定済みで、
        // 巻き戻しても消えないので、戻せなかったことを黙らない
        try {
            $this->wait()->atMost(7, function (): void {
                DB::beginTransaction();
                try {
                    DB::select('select * from lock_wait_no_such_table');
                } catch (QueryException) {
                    // トランザクションは壊れた
                }

                throw new RuntimeException('work failed');
            });
            $this->fail('処理の例外が要る');
        } catch (RuntimeException $e) {
            $this->assertSame('work failed', $e->getMessage());
        } finally {
            $messages = collect(Exceptions::reported())->map(fn (Throwable $e): string => $e->getMessage())->all();
            // トランザクションの中なので切らない
            $this->assertNotNull(DB::connection()->getRawPdo());
            DB::rollBack();
        }

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('元に戻せませんでした', $messages[0]);
    }

    public function test_mysql_the_restored_value_stays_after_a_rollback(): void
    {
        if (DB::getDriverName() === 'pgsql' || DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('MySQL だけ（SET SESSION は巻き戻らない）');
        }

        try {
            DB::transaction(fn () => $this->wait()->atMost(7, fn () => throw new RuntimeException('work failed')));
        } catch (RuntimeException) {
            // 巻き戻った
        }

        $this->assertSame($this->original(), $this->currentLockWait());
    }

    public function test_seconds_below_one_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->wait()->atMost(0, fn () => null);
    }

    public function test_sqlite_runs_the_work_without_a_limit(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite だけ');
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame('done', $this->wait()->atMost(7, fn (): string => 'done'));
        $this->assertSame(0, $queries);
    }

    public function test_other_drivers_are_refused(): void
    {
        config(['database.connections.lock-sqlsrv' => ['driver' => 'sqlsrv', 'host' => '127.0.0.1', 'database' => 'lock_test', 'username' => 'x', 'password' => '']]);

        $this->expectException(LogicException::class);

        $this->wait()->atMost(7, fn () => null, 'lock-sqlsrv');
    }

    /** 2回目の書き込み（戻す）の後に投げる：DB が戻すのに失敗したのと同じ */
    private function failRestore(): void
    {
        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (str_contains($query->sql, 'innodb_lock_wait_timeout =') || str_contains($query->sql, "set_config('lock_timeout'")) {
                $writes++;
                if ($writes === 2) {
                    throw new RuntimeException('database gone');
                }
            }
        });
    }

    private function createRows(): void
    {
        Schema::dropIfExists('lock_wait_rows');
        Schema::create('lock_wait_rows', function ($table) {
            $table->integer('id')->primary();
        });
        DB::table('lock_wait_rows')->insert([['id' => 1]]);
    }
}
