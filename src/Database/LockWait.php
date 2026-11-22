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
 * 渡した処理の間だけ、行のロックを待つ時間に上限を付け、後で接続の値を元に戻す。
 *
 * キューのワーカーは同じ接続で次の仕事を動かすので、戻し忘れると後の仕事に効く。処理が投げても戻す。
 * - MySQL：innodb_lock_wait_timeout（秒。最小1）。SET SESSION の値はトランザクションの巻き戻しで戻らない
 * - PostgreSQL：lock_timeout（ミリ秒）。トランザクションの中の SET は、巻き戻すと消える
 * - SQLite：何もしない（行のロックがない）
 *
 * 設定できなければ、処理を今の値のまま動かして記録する（処理は落ちた接続に自分で出会う）。
 * 戻せなければ、処理の結果・例外を置き換えずに記録し、トランザクションの外なら接続を切る
 * （次の接続はサーバーの既定で始まる。中で切ると、黙って巻き戻り、その後が自動のコミットになる）。
 * 記録の文には、例外のクラスと SQLSTATE だけを入れる（設定の値・SQL を入れない）
 *
 * 読む・設定する・戻す文は、どれも書き込み用の接続で流す（read/write を分けた接続で、行を握るのは書き込み用の接続。
 * selectOne() の既定はトランザクションの外で読み取り用の接続に流れ、上限が効かないまま黙る）
 */
final class LockWait
{
    /** 記録の間引きの窓（毎分の処理が毎回記録しないように） */
    private const REPORT_WINDOW = 3600;

    public function __construct(private readonly FailureReporter $reporter = new FailureReporter) {}

    /**
     * @template T
     *
     * @param  int  $seconds  1以上（MySQL の最小が1秒）
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws InvalidArgumentException 秒が1より小さいとき
     * @throws \LogicException MySQL・PostgreSQL・SQLite のほかの driver のとき
     */
    public function atMost(int $seconds, Closure $work, ?string $connection = null): mixed
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('ロックを待つ時間の上限は1秒以上にしてください（MySQL の最小が1秒）');
        }

        $db = DB::connection($connection);
        $driver = LockDriver::of($db);
        if ($driver === LockDriver::Sqlite) {
            return $work();
        }

        try {
            $previous = self::read($db, $driver);
            // 設定した時の段数：0 なら SET は確定済みで、後で巻き戻しても消えない
            $levelAtSet = $db->transactionLevel();
            self::write($db, $driver, $driver === LockDriver::MySql ? (string) $seconds : ($seconds * 1000).'ms');
        } catch (Throwable $e) {
            $this->reporter->report(new RuntimeException(
                'LockWait：ロックを待つ時間の上限を設定できないので、接続の今の値のまま処理を動かしました（'.self::describe($e).'）',
            ), 'foundation.lock-wait:set-failed', self::REPORT_WINDOW);

            return $work();
        }

        try {
            return $work();
        } finally {
            $this->restore($db, $driver, $previous, $levelAtSet);
        }
    }

    /** 戻す。投げない */
    private function restore(Connection $db, LockDriver $driver, string $previous, int $levelAtSet): void
    {
        try {
            self::write($db, $driver, $previous);
        } catch (Throwable $e) {
            // PostgreSQL の壊れたトランザクションの中（処理が誤った）：戻す SET は通らないが、巻き戻せば SET も消える。
            // 消えるのは、呼び手のトランザクションの中で設定したときだけ（外で設定した値は確定済みで残るので、記録する）
            if ($driver === LockDriver::Postgres && $levelAtSet > 0 && $db->transactionLevel() > 0 && self::sqlState($e) === '25P02') {
                return;
            }

            $this->reporter->report(new RuntimeException(
                'LockWait：ロックを待つ時間の上限を元に戻せませんでした。接続が終わるまで残ります（'.self::describe($e).'）',
            ), 'foundation.lock-wait:restore-failed', self::REPORT_WINDOW);

            if ($db->transactionLevel() === 0) {
                try {
                    $db->disconnect();
                } catch (Throwable) {
                    // 切れなくても、元の結果・例外を置き換えない
                }
            }
        }
    }

    private static function read(Connection $db, LockDriver $driver): string
    {
        return match ($driver) {
            LockDriver::MySql => (string) (int) $db->selectOne('select @@SESSION.innodb_lock_wait_timeout as value', [], false)->value,
            LockDriver::Postgres => (string) $db->selectOne("select current_setting('lock_timeout') as value", [], false)->value,
            LockDriver::Sqlite => '',
        };
    }

    private static function write(Connection $db, LockDriver $driver, string $value): void
    {
        match ($driver) {
            // 数だけを入れる（読んだ値も (int) にしてある）
            LockDriver::MySql => $db->statement('SET SESSION innodb_lock_wait_timeout = '.(int) $value),
            // is_local = false：SET SESSION と同じ（トランザクションの中なら、巻き戻すと消える）
            LockDriver::Postgres => $db->selectOne("select set_config('lock_timeout', ?, false) as value", [$value], false),
            LockDriver::Sqlite => null,
        };
    }

    /** @internal 記録の文に入れる、例外のクラスと SQLSTATE */
    public static function describe(Throwable $e): string
    {
        $state = self::sqlState($e);

        return $e::class.($state !== null ? ' SQLSTATE '.$state : '');
    }

    /** @internal */
    public static function sqlState(Throwable $e): ?string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $info = $current instanceof \PDOException ? $current->errorInfo : null;
            if (is_array($info) && isset($info[0]) && is_string($info[0])) {
                return $info[0];
            }
        }

        return null;
    }
}
