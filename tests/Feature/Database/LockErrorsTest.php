<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Database\LockErrors;
use Miraqceed\Foundation\Database\LockWait;
use Miraqceed\Foundation\Tests\Support\LockConnections;
use Miraqceed\Foundation\Tests\TestCase;
use PDOException;
use RuntimeException;

/**
 * ロックの誤りの見分け：SQLSTATE と DB の番号で見る。本物の誤りは、2本目の接続（デッドロックは別のプロセス）で起こす
 */
class LockErrorsTest extends TestCase
{
    use LockConnections;

    protected function tearDown(): void
    {
        $this->closeLockConnections();
        if (DB::getDriverName() !== 'sqlite') {
            Schema::dropIfExists('lock_error_rows');
        }

        parent::tearDown();
    }

    /** @param  array{0: string, 1: int|null, 2: string}  $errorInfo */
    private static function queryException(array $errorInfo): QueryException
    {
        $pdo = new PDOException($errorInfo[2]);
        $pdo->errorInfo = $errorInfo;

        return new QueryException('testing', 'select 1', [], $pdo);
    }

    public function test_it_reads_the_sqlstate_and_the_driver_code(): void
    {
        $this->assertTrue(LockErrors::isLockWaitTimeout(self::queryException(['HY000', 1205, 'x'])));
        $this->assertTrue(LockErrors::isLockWaitTimeout(self::queryException(['55P03', 7, 'x'])));
        $this->assertFalse(LockErrors::isLockWaitTimeout(self::queryException(['40001', 1213, 'x'])));
        $this->assertFalse(LockErrors::isLockWaitTimeout(self::queryException(['HY000', 1105, 'Lock wait timeout exceeded; try restarting transaction'])));

        $this->assertTrue(LockErrors::isDeadlock(self::queryException(['40001', 1213, 'x'])));
        $this->assertTrue(LockErrors::isDeadlock(self::queryException(['40P01', 7, 'x'])));
        $this->assertTrue(LockErrors::isDeadlock(self::queryException(['40001', 7, 'x'])));
        $this->assertFalse(LockErrors::isDeadlock(self::queryException(['55P03', 7, 'x'])));
        $this->assertFalse(LockErrors::isDeadlock(self::queryException(['23000', 1062, 'Deadlock found when trying to get lock'])));
    }

    public function test_other_exceptions_are_not_lock_errors_and_wrapped_ones_are_followed(): void
    {
        $this->assertFalse(LockErrors::isLockWaitTimeout(new RuntimeException('Lock wait timeout exceeded; try restarting transaction')));
        $this->assertFalse(LockErrors::isDeadlock(new RuntimeException('Deadlock found when trying to get lock')));
        $this->assertFalse(LockErrors::isDeadlock(new PDOException('no info')));

        // 入れ子のトランザクションのデッドロックは、Laravel が DeadlockException に包んで投げる
        $wrapped = new DeadlockException('deadlock', 0, self::queryException(['40001', 1213, 'x']));
        $this->assertTrue(LockErrors::isDeadlock($wrapped));
    }

    public function test_a_real_lock_wait_timeout_is_recognised(): void
    {
        $this->skipOnSqlite();
        $this->createRows();
        $holder = $this->otherConnection();
        $holder->beginTransaction();
        $holder->table('lock_error_rows')->where('id', 1)->lockForUpdate()->first();

        try {
            app(LockWait::class)->atMost(1, fn () => DB::table('lock_error_rows')->where('id', 1)->lockForUpdate()->first());
            $this->fail('待ちの誤りが要る');
        } catch (QueryException $e) {
            $this->assertTrue(LockErrors::isLockWaitTimeout($e), $e->getMessage());
            $this->assertFalse(LockErrors::isDeadlock($e));
        }
    }

    public function test_a_real_deadlock_is_recognised(): void
    {
        $this->skipOnSqlite();
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl と posix が要る');
        }
        $this->createRows();
        $log = tempnam(sys_get_temp_dir(), 'lock-errors');
        // 子は親の接続を受け継がず、それぞれつなぎ直す
        DB::disconnect();

        $pid = pcntl_fork();
        if ($pid === 0) {
            // 子：行2 → 行1 の順に握る（親は 行1 → 行2）
            file_put_contents($log, $this->lockInOrder([2, 1])."\n", FILE_APPEND | LOCK_EX);
            posix_kill(posix_getpid(), SIGKILL);
        }
        $mine = $this->lockInOrder([1, 2]);
        pcntl_waitpid($pid, $status);
        $child = trim((string) file_get_contents($log));
        @unlink($log);

        // どちらか一方が、デッドロックの誤りで落ちる（もう一方は通る）
        $outcomes = [$mine, $child];
        sort($outcomes);
        $this->assertSame(['deadlock', 'locked'], $outcomes);
    }

    /** @param  list<int>  $ids */
    private function lockInOrder(array $ids): string
    {
        try {
            DB::transaction(function () use ($ids): void {
                DB::table('lock_error_rows')->where('id', $ids[0])->lockForUpdate()->first();
                usleep(500_000);
                DB::table('lock_error_rows')->where('id', $ids[1])->lockForUpdate()->first();
                usleep(200_000);
            });

            return 'locked';
        } catch (\Throwable $e) {
            return LockErrors::isDeadlock($e) && ! LockErrors::isLockWaitTimeout($e) ? 'deadlock' : 'other: '.$e::class;
        }
    }

    private function createRows(): void
    {
        Schema::dropIfExists('lock_error_rows');
        Schema::create('lock_error_rows', function ($table) {
            $table->integer('id')->primary();
        });
        DB::table('lock_error_rows')->insert([['id' => 1], ['id' => 2]]);
    }
}
