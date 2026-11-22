<?php

namespace Miraqceed\Foundation\MachineTokens;

use InvalidArgumentException;

/**
 * 機械用のトークンの種類（何の機械の鍵か）。確かめ・入れ替えは、種類が合うものだけを通す。
 * 名前と頭の印の決まりは OneTimeCodes\CodeKind と同じ。違う値は作るときに InvalidArgumentException（文に値は入れない）
 */
final class MachineKind
{
    /**
     * @param  string  $name  表の kind に入る名前（1〜64字の ASCII の英数字・`-`・`_`・`.`）
     * @param  string  $prefix  頭の印（小文字で始まり `_` で終わる、2〜17字。例 `sag_`）
     * @param  ?int  $rotateAfterSeconds  今の鍵を出して（切り替えて）からこの秒を過ぎたら入れ替えを促す（1以上。null は促さない）。既定を持たない
     * @param  int  $graceSeconds  切り替えた後、前の鍵を効かせる秒（0以上。0 なら切り替えと同時に効かない）
     * @param  int  $pendingSeconds  入れ替え待ちの鍵が効く秒（1以上）。過ぎたら効かず、今の鍵は効いたまま
     */
    public function __construct(
        public readonly string $name,
        public readonly string $prefix,
        public readonly ?int $rotateAfterSeconds,
        public readonly int $graceSeconds = 600,
        public readonly int $pendingSeconds = 600,
    ) {
        if (preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $name) !== 1) {
            throw new InvalidArgumentException('機械用のトークンの種類の名前は、1〜64字の ASCII の英数字・-・_・. で書いてください');
        }
        if (preg_match('/\A[a-z][a-z0-9]{0,15}_\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('機械用のトークンの頭の印は、小文字で始まり _ で終わる 2〜17字（小文字の英数字）で書いてください');
        }
        if ($rotateAfterSeconds !== null && $rotateAfterSeconds < 1) {
            throw new InvalidArgumentException('入れ替えを促すまでの秒は 1 以上か null にしてください');
        }
        if ($graceSeconds < 0) {
            throw new InvalidArgumentException('前の鍵を効かせる秒は 0 以上にしてください');
        }
        if ($pendingSeconds < 1) {
            throw new InvalidArgumentException('入れ替え待ちの鍵が効く秒は 1 以上にしてください');
        }
    }
}
