<?php

namespace Miraqceed\Foundation\Testing;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Miraqceed\Foundation\Database\LockDriver;
use Miraqceed\Foundation\Database\LockWait;
use Miraqceed\Foundation\Database\NamedLock;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * 試しの助け：2本目の接続で行・名前つきのロックを握ったまま、渡した関数を動かす。
 * 「ほかの要求が握っている間は待つ（上限で止まる）」ことを、本物の DB のロックで確かめる。
 *
 * - 2本目の接続は、試しの接続の設定を写した別の名前の接続。終われば（投げても）巻き戻し・放し・閉じる
 * - 握れるのはコミットされた行だけ。RefreshDatabase の中で作った行は2本目から見えないので、分かる文で止める
 *   （DatabaseTruncation などで片付ける試しにする）
 * - row() の関数の間は、試しの接続の行のロックを待つ時間を1秒にする（LockWait。後で戻す）
 * - SQLite では試しを飛ばす（行のロックも名前つきのロックもない）
 * - PostgreSQL では、待ちの誤りでその接続のトランザクションが壊れる。試しの接続がトランザクションの中なら、巻き戻すまで使えない
 *
 * 本番のコードからは呼ばない。
 */
final class HeldLocks
{
    /** 2本目の接続の名前 */
    public const CONNECTION = 'foundation-held-locks';

    /** 関数の間の、試しの接続の行のロックを待つ時間（秒） */
    private const WAIT_SECONDS = 1;

    /**
     * 表 $table の $column = $id の行を、2本目の接続で FOR UPDATE で握ったまま $fn を動かし、その戻り値を返す
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function row(string $table, int|string $id, Closure $fn, string $column = 'id', ?string $connection = null): mixed
    {
        $db = self::testConnection($connection);
        $holder = self::holder($db);

        try {
            // 先に握らずに読む：MySQL の握る読み方は、コミットされていない行を見つけると、その確定まで待つ
            if (! $holder->table($table)->useWritePdo()->where($column, $id)->exists()) {
                throw new LogicException(
                    "2本目の接続から、表 {$table} の行が見えません。RefreshDatabase の中で作った行はコミットされないので、"
                    .'DatabaseTruncation などで片付ける試しにして、コミットした行を握ってください',
                );
            }
            $holder->beginTransaction();
            $holder->table($table)->where($column, $id)->lockForUpdate()->first();

            return app(LockWait::class)->atMost(self::WAIT_SECONDS, $fn, $db->getName());
        } finally {
            self::close($holder);
        }
    }

    /**
     * 名前つきのロック $name（NamedLock と同じ名前の作り）を、2本目の接続で握ったまま $fn を動かし、その戻り値を返す
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function name(string $name, Closure $fn, ?string $connection = null): mixed
    {
        $db = self::testConnection($connection);
        $serverName = NamedLock::serverName($name, $db->getName());
        $holder = self::holder($db);

        try {
            $got = LockDriver::of($holder) === LockDriver::MySql
                ? (int) $holder->selectOne('select GET_LOCK(?, 0) as got', [$serverName], false)->got === 1
                : in_array($holder->selectOne('select pg_try_advisory_lock(?::bigint) as got', [$serverName], false)->got, [true, 1, '1', 't'], true);
            if (! $got) {
                throw new LogicException('2本目の接続で名前つきのロックを握れませんでした（ほかの接続が既に握っています）');
            }

            return $fn();
        } finally {
            // 閉じる前に放す：閉じるだけだと、サーバーが名前を放すのは切断の片付けの後になり、戻った直後にまだ取れないことがある
            try {
                LockDriver::of($holder) === LockDriver::MySql
                    ? $holder->selectOne('select RELEASE_LOCK(?) as released', [$serverName], false)
                    : $holder->selectOne('select pg_advisory_unlock(?::bigint) as released', [$serverName], false);
            } catch (Throwable) {
                // 放せなくても、閉じればサーバーが放す
            }
            self::close($holder);
        }
    }

    private static function testConnection(?string $connection): Connection
    {
        $db = DB::connection($connection);

        if (LockDriver::of($db) === LockDriver::Sqlite) {
            Assert::markTestSkipped('SQLite には行のロックも名前つきのロックもないので、この試しを飛ばします');
        }

        return $db;
    }

    private static function holder(Connection $db): Connection
    {
        config(['database.connections.'.self::CONNECTION => config('database.connections.'.$db->getName())]);
        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    private static function close(Connection $holder): void
    {
        try {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack(0);
            }
        } catch (Throwable) {
            // 閉じれば巻き戻る
        }

        DB::purge(self::CONNECTION);
    }
}
