<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * DB のロックの試しの助け：既定の接続の設定を写した別の接続（2本目・3本目）と、接続の今の待ちの上限。
 * 作った接続は試しの終わりに閉じる
 */
trait LockConnections
{
    /** @var list<string> */
    private array $lockConnections = [];

    /**
     * 既定の接続の設定を写した接続。$overrides に別の DB を渡すとき（同じサーバーの information_schema・postgres）は、
     * テストの DB の守りから外す（名前を偽らない。そこではロックを取るだけで、表を書かない）
     *
     * @param  array<string, mixed>  $overrides
     */
    private function otherConnection(string $name = 'lock-other', array $overrides = []): Connection
    {
        if (isset($overrides['database'])) {
            config(['foundation.test_safety.ignore_connections' => [...(array) config('foundation.test_safety.ignore_connections', []), $name]]);
        }
        config(["database.connections.{$name}" => array_merge(config('database.connections.'.config('database.default')), $overrides)]);
        DB::purge($name);
        $this->lockConnections[] = $name;

        return DB::connection($name);
    }

    private function closeLockConnections(): void
    {
        foreach ($this->lockConnections as $name) {
            try {
                $db = DB::connection($name);
                if ($db->transactionLevel() > 0) {
                    $db->rollBack(0);
                }
            } catch (\Throwable) {
                // 閉じれば巻き戻る
            }
            DB::purge($name);
        }
        $this->lockConnections = [];
    }

    private function skipOnSqlite(string $why = 'MySQL・PostgreSQL で流す'): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped($why);
        }
    }

    /** 接続の今の待ちの上限（MySQL は innodb_lock_wait_timeout の秒、PostgreSQL は lock_timeout の文字） */
    private function currentLockWait(?string $connection = null): string
    {
        $db = DB::connection($connection);

        return $db->getDriverName() === 'pgsql'
            ? (string) $db->selectOne("select current_setting('lock_timeout') as value")->value
            : (string) (int) $db->selectOne('select @@SESSION.innodb_lock_wait_timeout as value')->value;
    }

    /** 接続の id（MySQL の CONNECTION_ID()、PostgreSQL の pg_backend_pid()） */
    private function connectionId(?string $connection = null): int
    {
        $db = DB::connection($connection);

        return (int) $db->selectOne($db->getDriverName() === 'pgsql' ? 'select pg_backend_pid() as id' : 'select CONNECTION_ID() as id')->id;
    }

    /** $killer の接続から、$id の接続を切る */
    private function killConnection(Connection $killer, int $id): void
    {
        if ($killer->getDriverName() === 'pgsql') {
            $killer->selectOne('select pg_terminate_backend(?) as killed', [$id]);
        } else {
            $killer->statement('KILL '.$id);
        }
    }
}
