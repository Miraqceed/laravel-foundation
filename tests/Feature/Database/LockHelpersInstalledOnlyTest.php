<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Database\LockErrors;
use Miraqceed\Foundation\Database\LockWait;
use Miraqceed\Foundation\Database\NamedLock;
use Miraqceed\Foundation\Database\Transactions;
use Miraqceed\Foundation\Testing\HeldLocks;
use Miraqceed\Foundation\Tests\TestCase;
use PDOException;
use Throwable;

/**
 * 入れただけでは何も変わらない：設定の節・容器の結び付け・Laravel の見分けの差し替えを足さず、接続の値も替えない
 */
class LockHelpersInstalledOnlyTest extends TestCase
{
    public function test_there_is_no_config_section_and_nothing_is_bound(): void
    {
        $this->assertNull(config('foundation.locks'));
        $this->assertNull(config('foundation.database'));

        foreach ([NamedLock::class, LockWait::class, Transactions::class, LockErrors::class, HeldLocks::class] as $class) {
            $this->assertFalse($this->app->bound($class), $class);
        }
        // Laravel 13 の見分けの契約（12 にはない）を差し替えない：Laravel が結び付けたものそのまま
        if (interface_exists(\Illuminate\Contracts\Database\ConcurrencyErrorDetector::class)
            && $this->app->bound(\Illuminate\Contracts\Database\ConcurrencyErrorDetector::class)) {
            $this->assertSame(\Illuminate\Database\ConcurrencyErrorDetector::class, $this->app->make(\Illuminate\Contracts\Database\ConcurrencyErrorDetector::class)::class);
        }
        $this->assertFalse(config()->has('database.connections.'.HeldLocks::CONNECTION));
    }

    public function test_laravel_still_does_not_treat_the_postgres_lock_timeout_as_a_concurrency_error(): void
    {
        $pdo = new PDOException('SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout');
        $pdo->errorInfo = ['55P03', 7, 'ERROR:  canceling statement due to lock timeout'];
        $e = new QueryException('testing', 'select 1', [], $pdo);

        $detector = new class
        {
            use DetectsConcurrencyErrors;

            public function check(Throwable $e): bool
            {
                return $this->causedByConcurrencyError($e);
            }
        };

        $this->assertFalse($detector->check($e));
        $this->assertTrue(LockErrors::isLockWaitTimeout($e));
    }

    public function test_the_connection_keeps_the_server_default_lock_wait(): void
    {
        $value = match (DB::getDriverName()) {
            'mysql', 'mariadb' => (int) DB::selectOne('select @@SESSION.innodb_lock_wait_timeout as value')->value,
            'pgsql' => (string) DB::selectOne("select current_setting('lock_timeout') as value")->value,
            default => $this->markTestSkipped('SQLite には待ちの上限がない'),
        };

        $this->assertSame(DB::getDriverName() === 'pgsql' ? '0' : 50, $value);
    }
}
