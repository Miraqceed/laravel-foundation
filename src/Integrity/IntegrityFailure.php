<?php

namespace Miraqceed\Foundation\Integrity;

use Exception;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * FailureReporter に渡す、この部品の例外。文は根からの道と印・数だけ。元の例外は持たない（previous にしない）：
 * 元の例外の文とスタックには、木の根の絶対の道が入りうる。
 *
 * 作った所（file・line）と積み跡も消す。PHP の例外は、作った所と呼んだ所の絶対の道を持ち、Laravel の report() は
 * それをログに書く。本番ではパッケージは木の根の下（vendor）にあるので、根の道（共用サーバーの利用者の名前を含む）が出る
 */
final class IntegrityFailure extends RuntimeException
{
    public static function caught(string $check, Throwable $e): self
    {
        return self::withoutPaths("改ざんの検知：調べ「{$check}」が途中で落ちました（".$e::class.'）');
    }

    /** 調べの結果の1行（数と根からの道だけ） */
    public static function summary(string $message): self
    {
        return self::withoutPaths($message);
    }

    private static function withoutPaths(string $message): self
    {
        $e = new self($message);
        (new ReflectionProperty(Exception::class, 'file'))->setValue($e, '');
        (new ReflectionProperty(Exception::class, 'line'))->setValue($e, 0);
        (new ReflectionProperty(Exception::class, 'trace'))->setValue($e, []);

        return $e;
    }
}
