<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Database\LockErrors;
use Miraqceed\Foundation\Tests\Support\LockConnections;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 部品の作りが頼る DB の振る舞いを、本物の DB で確かめる（文書に書いていない、または読み方の分かれる所）。
 * ここが落ちたら、部品の作りと説明書を見直す
 */
class LockFactsTest extends TestCase
{
    use LockConnections;

    protected function tearDown(): void
    {
        $this->closeLockConnections();
        if (DB::getDriverName() !== 'sqlite') {
            Schema::dropIfExists('lock_fact_cache_locks');
        }

        parent::tearDown();
    }

    private function onlyOn(string $driver): void
    {
        if (DB::getDriverName() !== $driver) {
            $this->markTestSkipped("{$driver} だけ");
        }
    }

    /** LockWait は finally で戻す。巻き戻しでは戻らない */
    public function test_mysql_set_session_is_not_undone_by_a_rollback(): void
    {
        $this->onlyOn('mysql');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 45');

        DB::beginTransaction();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 7');
        DB::rollBack();

        $this->assertSame('7', $this->currentLockWait());
    }

    /** NamedLock は名前を43字にそろえる。長い名前を黙って切られると、別の名前とぶつかる */
    public function test_mysql_refuses_a_lock_name_longer_than_64_characters(): void
    {
        $this->onlyOn('mysql');

        $this->assertSame(1, (int) DB::selectOne('select GET_LOCK(?, 0) as got', [str_repeat('a', 64)])->got);
        DB::selectOne('select RELEASE_LOCK(?) as released', [str_repeat('a', 64)]);

        $this->expectException(QueryException::class);
        DB::selectOne('select GET_LOCK(?, 0) as got', [str_repeat('a', 65)]);
    }

    /** NamedLock は鍵に DB の名前を混ぜる。advisory lock が DB ごとなら、混ぜなくてもぶつからない（混ぜても害はない） */
    public function test_postgres_advisory_locks_are_per_database(): void
    {
        $this->onlyOn('pgsql');
        $other = $this->otherConnection('lock-fact-postgres', ['database' => 'postgres']);
        $key = 4242424242;

        $this->assertTrue(DB::selectOne('select pg_try_advisory_lock(?::bigint) as got', [$key])->got);
        try {
            $this->assertTrue($other->selectOne('select pg_try_advisory_lock(?::bigint) as got', [$key])->got);
            // 同じ DB の別の接続では取れない（確かめ方が効いていること）
            $same = $this->otherConnection('lock-fact-same');
            $this->assertFalse($same->selectOne('select pg_try_advisory_lock(?::bigint) as got', [$key])->got);
        } finally {
            DB::selectOne('select pg_advisory_unlock(?::bigint)', [$key]);
        }
    }

    /**
     * 説明書の選び分け：database のキャッシュのロックを、アプリと同じ接続のトランザクションの中で取ると、
     * ロックの行は確定まで見えず、ほかの要求はその行のロックで待つ（すぐに「取れない」とは返らない）
     */
    public function test_a_cache_lock_taken_inside_a_transaction_makes_others_wait(): void
    {
        $this->skipOnSqlite();
        Schema::dropIfExists('lock_fact_cache_locks');
        Schema::create('lock_fact_cache_locks', function ($table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        $this->otherConnection('lock-fact-cache');
        $default = (string) config('database.default');
        config([
            'cache.stores.lock-fact-a' => ['driver' => 'database', 'connection' => $default, 'table' => 'cache', 'lock_connection' => $default, 'lock_table' => 'lock_fact_cache_locks', 'lock_lottery' => [0, 100]],
            'cache.stores.lock-fact-b' => ['driver' => 'database', 'connection' => 'lock-fact-cache', 'table' => 'cache', 'lock_connection' => 'lock-fact-cache', 'lock_table' => 'lock_fact_cache_locks', 'lock_lottery' => [0, 100]],
        ]);
        $other = DB::connection('lock-fact-cache');
        $other->getDriverName() === 'pgsql'
            ? $other->statement("SET lock_timeout = '1s'")
            : $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();
        try {
            $this->assertTrue(Cache::store('lock-fact-a')->lock('fact', 30)->get());

            $started = microtime(true);
            $outcome = null;
            try {
                $outcome = Cache::store('lock-fact-b')->lock('fact', 30)->get() ? 'acquired' : 'refused';
            } catch (QueryException $e) {
                $outcome = LockErrors::isLockWaitTimeout($e) ? 'lock wait timeout' : 'other: '.$e->getMessage();
            }
            $waited = microtime(true) - $started;
        } finally {
            DB::rollBack();
        }

        // どちらも待つ。待った後は DB で違う：MySQL は待ちの誤りが UPDATE から出る。PostgreSQL は Laravel が 55P03 を
        // 「取れない」と読み（待ちの誤りと見ない）、UPDATE は確定していない行を見ないので、取れないと返る
        $this->assertGreaterThanOrEqual(0.9, $waited, "すぐに返った：{$outcome}");
        $this->assertSame(DB::getDriverName() === 'pgsql' ? 'refused' : 'lock wait timeout', $outcome);
    }
}
