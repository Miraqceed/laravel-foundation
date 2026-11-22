<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Database\LockErrors;
use Miraqceed\Foundation\Database\LockWait;
use Miraqceed\Foundation\Database\NamedLock;
use Miraqceed\Foundation\Tests\Support\LockConnections;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 読み取り用と書き込み用を分けた接続（read/write）でも、ロックを取る・放す・待ちの上限を替えるのは、どれも書き込み用の接続。
 * 行を握るのは書き込み用の接続なので、読み取り用の接続で取る・替えると、並ばない・効かない・ロックが残る
 */
class LockReadWriteSplitTest extends TestCase
{
    use LockConnections;

    private const NAME = 'split:invoice-number:2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipOnSqlite();
    }

    protected function tearDown(): void
    {
        $this->closeLockConnections();
        if (DB::getDriverName() !== 'sqlite') {
            Schema::dropIfExists('lock_split_rows');
        }

        parent::tearDown();
    }

    public function test_the_split_connection_has_two_sessions(): void
    {
        $split = $this->splitConnection();

        $this->assertNotSame($split->getReadPdo(), $split->getPdo());
        $this->assertNotSame($this->sessionId($split, true), $this->sessionId($split, false));
    }

    public function test_named_lock_takes_and_releases_on_the_write_connection(): void
    {
        Exceptions::fake();
        $split = $this->splitConnection();
        $other = $this->otherConnection();
        $writeId = $this->sessionId($split, false);
        $readId = $this->sessionId($split, true);

        foreach ([0, 1] as $wait) {
            $holder = $this->lock()->run(self::NAME, $wait, function () use ($other, $writeId, $readId): ?int {
                $this->assertFalse($this->grab($other), '握っている間は、ほかの接続は取れない');

                return $this->holderOf($other, $writeId, $readId);
            }, 'lock-split');

            $this->assertSame($writeId, $holder, "待ち {$wait} 秒：書き込み用の接続が握る");
            // 同じ接続で放したので、ほかの接続がすぐ取れる
            $this->assertTrue($this->grab($other), "待ち {$wait} 秒：放した後は取れる");
            $this->letGo($other);
        }

        // 「握っていませんでした」などの記録がない
        $this->assertSame([], Exceptions::reported());
    }

    public function test_lock_wait_applies_to_the_write_connection_and_comes_back(): void
    {
        $split = $this->splitConnection();
        $this->setLockWait($split, false, 45);
        $this->setLockWait($split, true, 33);

        $inside = app(LockWait::class)->atMost(7, fn (): array => [$this->lockWaitOf($split, false), $this->lockWaitOf($split, true)], 'lock-split');

        $pg = $split->getDriverName() === 'pgsql';
        $this->assertSame([$pg ? '7s' : '7', $pg ? '33s' : '33'], $inside);
        $this->assertSame($pg ? '45s' : '45', $this->lockWaitOf($split, false));
        $this->assertSame($pg ? '33s' : '33', $this->lockWaitOf($split, true));
    }

    public function test_lock_wait_gives_up_a_held_row_on_the_write_connection(): void
    {
        Schema::dropIfExists('lock_split_rows');
        Schema::create('lock_split_rows', function ($table) {
            $table->integer('id')->primary();
        });
        DB::table('lock_split_rows')->insert(['id' => 1]);
        $split = $this->splitConnection();
        // 上限が書き込み用の接続に効かないとき、試しが限りなく待たないように（PostgreSQL の lock_timeout の既定は「限りなく」）。
        // そのときは待ちの誤りでなく、文の時間切れ（57014）か5秒の待ちで落ちる
        $split->getDriverName() === 'pgsql'
            ? $split->getPdo()->exec("SET statement_timeout = '5s'")
            : $this->setLockWait($split, false, 5);
        $holder = $this->otherConnection();
        $holder->beginTransaction();
        $holder->table('lock_split_rows')->where('id', 1)->lockForUpdate()->first();

        $started = microtime(true);
        try {
            app(LockWait::class)->atMost(1, fn () => $split->table('lock_split_rows')->where('id', 1)->lockForUpdate()->first(), 'lock-split');
            $this->fail('待ちの誤りが要る');
        } catch (QueryException $e) {
            $this->assertTrue(LockErrors::isLockWaitTimeout($e));
            $this->assertLessThan(3.0, microtime(true) - $started);
        }
    }

    private function lock(): NamedLock
    {
        return $this->app->make(NamedLock::class);
    }

    private function grab(Connection $db): bool
    {
        $serverName = NamedLock::serverName(self::NAME, $db->getName());

        return $db->getDriverName() === 'pgsql'
            ? $db->selectOne('select pg_try_advisory_lock(?::bigint) as got', [$serverName], false)->got === true
            : (int) $db->selectOne('select GET_LOCK(?, 0) as got', [$serverName], false)->got === 1;
    }

    private function letGo(Connection $db): void
    {
        $serverName = NamedLock::serverName(self::NAME, $db->getName());
        $db->getDriverName() === 'pgsql'
            ? $db->selectOne('select pg_advisory_unlock(?::bigint) as released', [$serverName], false)
            : $db->selectOne('select RELEASE_LOCK(?) as released', [$serverName], false);
    }

    /** 名前を握っている接続の id（分けた接続の2つのうち） */
    private function holderOf(Connection $observer, int $writeId, int $readId): ?int
    {
        if ($observer->getDriverName() === 'pgsql') {
            $row = $observer->selectOne(
                "select pid from pg_locks where locktype = 'advisory' and granted and pid in (?, ?)",
                [$writeId, $readId],
                false,
            );

            return $row === null ? null : (int) $row->pid;
        }

        $id = $observer->selectOne('select IS_USED_LOCK(?) as id', [NamedLock::serverName(self::NAME, $observer->getName())], false)->id;

        return $id === null ? null : (int) $id;
    }

    private function setLockWait(Connection $db, bool $read, int $seconds): void
    {
        $pdo = $read ? $db->getReadPdo() : $db->getPdo();
        $pdo->exec($db->getDriverName() === 'pgsql' ? "SET lock_timeout = '{$seconds}s'" : "SET SESSION innodb_lock_wait_timeout = {$seconds}");
    }

    private function lockWaitOf(Connection $db, bool $read): string
    {
        return $db->getDriverName() === 'pgsql'
            ? (string) $db->selectOne("select current_setting('lock_timeout') as value", [], $read)->value
            : (string) (int) $db->selectOne('select @@SESSION.innodb_lock_wait_timeout as value', [], $read)->value;
    }
}
