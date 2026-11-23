<?php

namespace Miraqceed\Foundation\Integrity;

use RuntimeException;
use Throwable;

/**
 * FailureReporter に渡す、この部品の例外。文は根からの道と印・数だけ。元の例外は持たない（previous にしない）：
 * 元の例外の文とスタックには、木の根の絶対の道が入りうる
 */
final class IntegrityFailure extends RuntimeException
{
    public static function caught(string $check, Throwable $e): self
    {
        return new self("改ざんの検知：調べ「{$check}」が途中で落ちました（".$e::class.'）');
    }
}
