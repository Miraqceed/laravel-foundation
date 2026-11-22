<?php

namespace Miraqceed\Foundation\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Miraqceed\Foundation\Failures\FailureReporter;
use RuntimeException;
use Throwable;

/**
 * 名前つきのロック：同じ名前の処理を、プロセスをまたいで1つずつ動かす。
 *
 * - MySQL：GET_LOCK・RELEASE_LOCK（接続に付く。確定・巻き戻しでは放されない。接続が切れれば放される）
 * - PostgreSQL：セッションの advisory lock（pg_advisory_lock・pg_advisory_unlock。同じ性質）
 * - SQLite：ロックを取らずに処理を動かす（名前つきのロックがない。書き込みはもともと1つずつ）
 *
 * トランザクションの外でだけ呼べる：中で取ると、処理のトランザクションは savepoint になり、外の確定より先に放すため。
 * 放すのは処理の後（処理のトランザクションの確定の後）。放すのに失敗しても投げない
 * （投げると、確定した処理が誤りに化け、送り直しで二重になる）。
 *
 * 名前は、接続の DB の名前と合わせた sha256 にする。MySQL の名前はサーバー全体で1つ（共用サーバーのほかの DB・
 * 並べて流す試しの DB とぶつかる）で、64字までのため。PostgreSQL の鍵は bigint なので、同じ値の先頭8バイトを使う。
 * 記録・例外の文に、名前・鍵を入れない
 *
 * 取る・放す文は、どれも書き込み用の接続で流す（selectOne() の既定は、トランザクションの外で読み取り用の接続に流れる。
 * read/write を分けた接続で、取る・放すが別の接続になり、ロックが残る・並ばない）
 */
final class NamedLock
{
    public function __construct(private readonly FailureReporter $reporter = new FailureReporter) {}

    /**
     * 名前のロックを取り（$waitSeconds 秒まで待つ。0 は待たない）、$work を動かし、放す。$work の戻り値を返す。
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws LockNotAcquired 待つ時間の上限までに取れなかったとき
     * @throws InvalidArgumentException 名前が空・壊れた UTF-8、秒が負のとき
     * @throws \LogicException アプリのトランザクションの中、または MySQL・PostgreSQL・SQLite のほかの driver のとき
     */
    public function run(string $name, int $waitSeconds, Closure $work, ?string $connection = null): mixed
    {
        self::checkName($name);
        if ($waitSeconds < 0) {
            throw new InvalidArgumentException('名前つきのロックを待つ秒は0以上にしてください（0 は待たない）');
        }

        $db = DB::connection($connection);
        $driver = LockDriver::of($db);

        // SQLite でも確かめる（SQLite で流す試しが、本番の DB で落ちる呼び方を見逃さないように）
        Transactions::assertOutside(
            '名前つきのロックは、トランザクションの外で取ってください（中で取ると、処理のトランザクションが savepoint になり、外の確定より先にロックを放します）',
            $db->getName(),
        );

        if ($driver === LockDriver::Sqlite) {
            return $work();
        }

        $serverName = self::serverNameFor($db, $driver, $name);
        $this->acquire($db, $driver, $serverName, $waitSeconds);

        try {
            return $work();
        } finally {
            $this->release($db, $driver, $serverName);
        }
    }

    /**
     * DB に渡す名前（MySQL は 'lf:' と16進の40字。PostgreSQL は advisory lock の鍵の整数）。調べるとき・試しで握るときに使う
     *
     * @throws InvalidArgumentException 名前が空・壊れた UTF-8 のとき
     */
    public static function serverName(string $name, ?string $connection = null): string|int
    {
        self::checkName($name);
        $db = DB::connection($connection);

        return self::serverNameFor($db, LockDriver::of($db), $name);
    }

    private static function serverNameFor(Connection $db, LockDriver $driver, string $name): string|int
    {
        $hash = hash('sha256', $db->getDatabaseName()."\0".$name, true);

        return match ($driver) {
            // 先頭8バイトを、大きい桁から読む符号付きの64ビットの整数にする（PHP の整数は符号付き）
            LockDriver::Postgres => unpack('J', substr($hash, 0, 8))[1],
            LockDriver::MySql, LockDriver::Sqlite => 'lf:'.substr(bin2hex($hash), 0, 40),
        };
    }

    private static function checkName(string $name): void
    {
        if ($name === '' || ! mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidArgumentException('名前つきのロックの名前は、空でない UTF-8 の文字列にしてください');
        }
    }

    private function acquire(Connection $db, LockDriver $driver, string|int $serverName, int $waitSeconds): void
    {
        if ($driver === LockDriver::MySql) {
            $got = $db->selectOne('select GET_LOCK(?, ?) as got', [$serverName, $waitSeconds], false)->got;
            if ($got === null) {
                throw new RuntimeException('GET_LOCK が NULL を返しました（DB の誤り。接続が切られたなど）');
            }
            if ((int) $got !== 1) {
                throw new LockNotAcquired($waitSeconds);
            }

            return;
        }

        // PostgreSQL：lock_timeout = 0 は「限りなく待つ」なので、待たないときは try にする
        if ($waitSeconds === 0) {
            if (! self::truthy($db->selectOne('select pg_try_advisory_lock(?::bigint) as got', [$serverName], false)->got)) {
                throw new LockNotAcquired(0);
            }

            return;
        }

        // 取る文だけをトランザクション（外にトランザクションがあれば savepoint）で包む。待ちの誤りで呼び手のトランザクション
        // （試しの包み）を壊さず、lock_timeout の替えは巻き戻しで消える。セッションのロックは確定の後も残る
        $acquired = false;
        try {
            $db->transaction(function (Connection $db) use ($serverName, $waitSeconds, &$acquired): void {
                $previous = (string) $db->selectOne("select current_setting('lock_timeout') as value", [], false)->value;
                $db->selectOne("select set_config('lock_timeout', ?, true) as value", [($waitSeconds * 1000).'ms'], false);
                $db->selectOne('select pg_advisory_lock(?::bigint) as got', [$serverName], false);
                $acquired = true;
                // 外のトランザクションの中（savepoint）では、確定まで残るので、ここで戻す
                $db->selectOne("select set_config('lock_timeout', ?, true) as value", [$previous], false);
            });
        } catch (Throwable $e) {
            if ($acquired) {
                // 取れた後に包みが落ちた：取ったまま返さない
                $this->release($db, $driver, $serverName);
            }
            if (LockErrors::isLockWaitTimeout($e)) {
                throw new LockNotAcquired($waitSeconds);
            }

            throw $e;
        }
    }

    /** 放す。投げない */
    private function release(Connection $db, LockDriver $driver, string|int $serverName): void
    {
        try {
            $released = match ($driver) {
                LockDriver::MySql => $db->selectOne('select RELEASE_LOCK(?) as released', [$serverName], false)->released,
                LockDriver::Postgres => $db->selectOne('select pg_advisory_unlock(?::bigint) as released', [$serverName], false)->released,
                LockDriver::Sqlite => true,
            };
        } catch (Throwable $e) {
            $this->reporter->report(new RuntimeException(
                'NamedLock：名前つきのロックを放せませんでした。接続が終わるまで残ります（'.LockWait::describe($e).'）',
            ));

            // 接続が残ると、ロックはワーカーが終わるまで残る。トランザクションの中で切ると黙って巻き戻るので、外のときだけ
            if ($db->transactionLevel() === 0) {
                try {
                    $db->disconnect();
                } catch (Throwable) {
                    // 切れなくても、処理の結果・例外を置き換えない
                }
            }

            return;
        }

        if (! self::truthy($released)) {
            // MySQL の 0・NULL、PostgreSQL の false：この接続は握っていなかった。処理の途中で接続が切れ、
            // Laravel がつなぎ直して続けた（ロックなしで動いた）見込み
            $this->reporter->report(new RuntimeException(
                "NamedLock：放すときに、名前つきのロックを握っていませんでした。処理の途中で接続が切れ、ロックなしで続いた見込みがあります（{$db->getDriverName()}）",
            ));
        }
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
