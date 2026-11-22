<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use LogicException;
use Miraqceed\Foundation\Database\LockNotAcquired;
use Miraqceed\Foundation\Database\NamedLock;
use Miraqceed\Foundation\Tests\Support\LockConnections;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * 名前つきのロック：2本目の接続が握っている間は取れない・放す・放すのに失敗しても結果を変えない・途中で失ったら記録・
 * トランザクションの中では断る・名前は DB ごと。試しの包みのない試し（包みの中は NamedLockInTestTransactionTest）
 */
class NamedLockTest extends TestCase
{
    use LockConnections;

    private const NAME = 'invoice-number:2026';

    protected function tearDown(): void
    {
        $this->closeLockConnections();

        parent::tearDown();
    }

    private function lock(): NamedLock
    {
        return $this->app->make(NamedLock::class);
    }

    /** 2本目の接続で、同じ名前を待たずに取る。取れたら真 */
    private function grab(Connection $db, string $name = self::NAME): bool
    {
        $serverName = NamedLock::serverName($name, $db->getName());

        return $db->getDriverName() === 'pgsql'
            ? $db->selectOne('select pg_try_advisory_lock(?::bigint) as got', [$serverName])->got === true
            : (int) $db->selectOne('select GET_LOCK(?, 0) as got', [$serverName])->got === 1;
    }

    private function letGo(Connection $db, string $name = self::NAME): void
    {
        $serverName = NamedLock::serverName($name, $db->getName());
        $db->getDriverName() === 'pgsql'
            ? $db->selectOne('select pg_advisory_unlock(?::bigint) as released', [$serverName])
            : $db->selectOne('select RELEASE_LOCK(?) as released', [$serverName]);
    }

    public function test_it_runs_the_work_and_returns_its_result_while_holding_the_lock(): void
    {
        $this->skipOnSqlite();
        $other = $this->otherConnection();

        $heldInside = $this->lock()->run(self::NAME, 1, fn (): bool => ! $this->grab($other));

        $this->assertTrue($heldInside);
        // 放した後は取れる
        $this->assertTrue($this->grab($other));
    }

    public function test_while_another_connection_holds_the_name_it_is_not_acquired(): void
    {
        $this->skipOnSqlite();
        $other = $this->otherConnection();
        $this->assertTrue($this->grab($other));
        $ran = false;

        try {
            $this->lock()->run(self::NAME, 0, function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('LockNotAcquired が要る');
        } catch (LockNotAcquired $e) {
            $this->assertSame(0, $e->waitSeconds);
        }

        $started = microtime(true);
        try {
            $this->lock()->run(self::NAME, 1, function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('LockNotAcquired が要る');
        } catch (LockNotAcquired $e) {
            $waited = microtime(true) - $started;
            $this->assertSame(1, $e->waitSeconds);
            $this->assertGreaterThanOrEqual(0.9, $waited);
            $this->assertLessThan(3.0, $waited);
            $this->assertStringNotContainsString(self::NAME, $e->getMessage());
            $this->assertStringNotContainsString((string) NamedLock::serverName(self::NAME), $e->getMessage());
        }
        $this->assertFalse($ran);

        // 放せば取れる
        $this->letGo($other);
        $this->assertSame('done', $this->lock()->run(self::NAME, 1, fn (): string => 'done'));
    }

    public function test_the_lock_is_released_when_the_work_throws(): void
    {
        $this->skipOnSqlite();
        $other = $this->otherConnection();

        try {
            $this->lock()->run(self::NAME, 1, fn () => throw new RuntimeException('work failed'));
            $this->fail('処理の例外が要る');
        } catch (RuntimeException $e) {
            $this->assertSame('work failed', $e->getMessage());
        }

        $this->assertTrue($this->grab($other));
    }

    public function test_a_failed_release_keeps_the_result_records_it_and_drops_the_connection(): void
    {
        $this->skipOnSqlite();
        Exceptions::fake();
        $this->failRelease();

        $this->assertSame('committed', $this->lock()->run(self::NAME, 1, fn (): string => 'committed'));

        $this->assertNull(DB::connection()->getRawPdo());
        $messages = $this->reportedMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('放せませんでした', $messages[0]);
        $this->assertNoName($messages[0]);
    }

    public function test_a_failed_release_keeps_the_original_exception(): void
    {
        $this->skipOnSqlite();
        Exceptions::fake();
        $this->failRelease();

        try {
            $this->lock()->run(self::NAME, 1, fn () => throw new LogicException('limit reached'));
            $this->fail('処理の例外が要る');
        } catch (LogicException $e) {
            $this->assertSame('limit reached', $e->getMessage());
        }
        $this->assertCount(1, $this->reportedMessages());
    }

    public function test_a_lock_lost_with_the_connection_during_the_work_is_recorded_when_released(): void
    {
        $this->skipOnSqlite();
        Exceptions::fake();
        $killer = $this->otherConnection();

        $result = $this->lock()->run(self::NAME, 1, function () use ($killer): int {
            $this->killConnection($killer, $this->connectionId());
            usleep(200_000);

            // トランザクションの外なので、Laravel は黙ってつなぎ直して続ける（ロックは失われている）
            return (int) DB::selectOne('select 1 as one')->one;
        });

        $this->assertSame(1, $result);
        $messages = $this->reportedMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('握っていませんでした', $messages[0]);
        $this->assertNoName($messages[0]);
    }

    public function test_inside_an_application_transaction_it_is_refused(): void
    {
        $ran = false;

        try {
            DB::transaction(fn () => $this->lock()->run(self::NAME, 1, function () use (&$ran): void {
                $ran = true;
            }));
            $this->fail('LogicException が要る');
        } catch (LogicException $e) {
            $this->assertStringContainsString('トランザクションの外', $e->getMessage());
        }

        $this->assertFalse($ran);
    }

    public function test_the_name_is_per_database(): void
    {
        $this->skipOnSqlite();
        // 同じサーバーの別の DB（MySQL は誰でも使える information_schema、PostgreSQL は postgres）
        $otherDatabase = DB::getDriverName() === 'pgsql' ? 'postgres' : 'information_schema';
        $this->otherConnection('lock-other-db', ['database' => $otherDatabase]);
        $sameDatabase = $this->otherConnection('lock-same-db');

        $this->assertNotSame(NamedLock::serverName(self::NAME), NamedLock::serverName(self::NAME, 'lock-other-db'));

        $this->lock()->run(self::NAME, 0, function () use ($sameDatabase): void {
            // 別の DB では同じ名前を同時に取れる
            $this->assertSame('other db', $this->lock()->run(self::NAME, 0, fn (): string => 'other db', 'lock-other-db'));
            // 同じ DB の別の接続では取れない
            $this->assertFalse($this->grab($sameDatabase));
        });
    }

    public function test_names_have_no_length_limit_and_must_be_non_empty_utf8(): void
    {
        $this->assertSame('long', $this->lock()->run(str_repeat('長', 1000), 1, fn (): string => 'long'));

        foreach (['', "\xC3\x28"] as $bad) {
            try {
                $this->lock()->run($bad, 1, fn () => null);
                $this->fail('InvalidArgumentException が要る');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->lock()->run(self::NAME, -1, fn () => null);
    }

    public function test_the_server_name_is_43_characters_on_mysql_and_an_integer_on_postgres(): void
    {
        $serverName = NamedLock::serverName(self::NAME);

        if (DB::getDriverName() === 'pgsql') {
            $this->assertIsInt($serverName);
        } else {
            $this->assertIsString($serverName);
            $this->assertMatchesRegularExpression('/\Alf:[0-9a-f]{40}\z/', $serverName);
        }
        $this->assertSame($serverName, NamedLock::serverName(self::NAME));
        $this->assertNotSame($serverName, NamedLock::serverName(self::NAME.'x'));
    }

    public function test_the_same_name_can_be_taken_again_inside_the_lock(): void
    {
        $this->skipOnSqlite();
        $other = $this->otherConnection();

        $inner = $this->lock()->run(self::NAME, 0, fn (): string => $this->lock()->run(self::NAME, 0, fn (): string => 'inner'));

        $this->assertSame('inner', $inner);
        // 取った回数だけ放した
        $this->assertTrue($this->grab($other));
    }

    public function test_postgres_the_lock_timeout_is_back_after_acquiring(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL だけ（取るときに lock_timeout を替える）');
        }
        DB::statement("SET lock_timeout = '45s'");

        $inside = $this->lock()->run(self::NAME, 3, fn (): string => $this->currentLockWait());

        $this->assertSame('45s', $inside);
        $this->assertSame('45s', $this->currentLockWait());
    }

    public function test_sqlite_runs_the_work_without_a_lock(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite だけ');
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame('done', $this->lock()->run(self::NAME, 1, fn (): string => 'done'));
        $this->assertSame(0, $queries);
    }

    public function test_other_drivers_are_refused(): void
    {
        config(['database.connections.lock-sqlsrv' => ['driver' => 'sqlsrv', 'host' => '127.0.0.1', 'database' => 'lock_test', 'username' => 'x', 'password' => '']]);

        $this->expectException(LogicException::class);

        $this->lock()->run(self::NAME, 1, fn () => null, 'lock-sqlsrv');
    }

    /** 放す文の後に投げる：接続が確定の直後に切れたのと同じ */
    private function failRelease(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'RELEASE_LOCK(') || str_contains($query->sql, 'pg_advisory_unlock(')) {
                throw new RuntimeException('connection lost '.self::NAME);
            }
        });
    }

    /** @return list<string> */
    private function reportedMessages(): array
    {
        return collect(Exceptions::reported())->map(fn (Throwable $e): string => $e->getMessage())->values()->all();
    }

    private function assertNoName(string $message): void
    {
        $this->assertStringNotContainsString(self::NAME, $message);
        $this->assertStringNotContainsString((string) NamedLock::serverName(self::NAME), $message);
        $this->assertStringNotContainsString('connection lost', $message);
    }
}
