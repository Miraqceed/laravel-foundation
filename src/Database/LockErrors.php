<?php

namespace Miraqceed\Foundation\Database;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * ロックの誤りを、SQLSTATE と DB の番号で見分ける（文の言葉は DB の版・言語で変わるので見ない）。
 *
 * Laravel の causedByConcurrencyError() は PostgreSQL の lock_timeout の誤り（55P03）を含まない。
 * これは替えずに、アプリが自分で見てやり直すための関数だけを出す。
 * 包まれた例外（入れ子のトランザクションの DeadlockException など）は、前の例外をたどって見る
 */
final class LockErrors
{
    /** 行・名前のロックを待つ時間の上限を超えた：MySQL の 1205、PostgreSQL の 55P03（lock_not_available） */
    public static function isLockWaitTimeout(Throwable $e): bool
    {
        return self::matches($e, fn (string $state, ?int $code): bool => $state === '55P03' || ($state === 'HY000' && $code === 1205));
    }

    /** デッドロック：MySQL の 1213、PostgreSQL の 40P01（deadlock_detected）、SQLSTATE 40001（直列化の失敗） */
    public static function isDeadlock(Throwable $e): bool
    {
        return self::matches($e, fn (string $state, ?int $code): bool => $state === '40001' || $state === '40P01' || $code === 1213);
    }

    /** @param  \Closure(string, ?int): bool  $test */
    private static function matches(Throwable $e, \Closure $test): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $info = match (true) {
                $current instanceof QueryException, $current instanceof PDOException => $current->errorInfo,
                default => null,
            };
            if (! is_array($info) || ! isset($info[0]) || ! is_string($info[0])) {
                continue;
            }

            $code = isset($info[1]) && is_numeric($info[1]) ? (int) $info[1] : null;
            if ($test($info[0], $code)) {
                return true;
            }
        }

        return false;
    }
}
