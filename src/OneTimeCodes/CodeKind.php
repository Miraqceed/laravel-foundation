<?php

namespace Miraqceed\Foundation\OneTimeCodes;

use InvalidArgumentException;

/**
 * 合言葉の種類（何のための合言葉か）。受け取りは、種類が合うものだけを通す。
 * 違う値は作るときに InvalidArgumentException（設定の誤りを黙って通さない。文に値は入れない）
 */
final class CodeKind
{
    /**
     * @param  string  $name  表の kind に入る名前（1〜64字の ASCII の英数字・`-`・`_`・`.`）
     * @param  string  $prefix  頭の印（小文字で始まり `_` で終わる、2〜17字。例 `apc_`・`it_`）
     * @param  int  $ttlSeconds  効く秒数（1以上）
     */
    public function __construct(
        public readonly string $name,
        public readonly string $prefix,
        public readonly int $ttlSeconds = 600,
    ) {
        if (preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $name) !== 1) {
            throw new InvalidArgumentException('合言葉の種類の名前は、1〜64字の ASCII の英数字・-・_・. で書いてください');
        }
        if (preg_match('/\A[a-z][a-z0-9]{0,15}_\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('合言葉の頭の印は、小文字で始まり _ で終わる 2〜17字（小文字の英数字）で書いてください');
        }
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('合言葉の効く秒数は 1 以上にしてください');
        }
    }
}
