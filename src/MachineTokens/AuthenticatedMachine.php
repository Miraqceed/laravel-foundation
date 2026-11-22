<?php

namespace Miraqceed\Foundation\MachineTokens;

/** 認めた機械 */
final class AuthenticatedMachine
{
    public function __construct(
        /** 種類の名前 */
        public readonly string $kind,
        /** 対象（出すときに渡した文字列。例 "server:7"） */
        public readonly string $subject,
        /** 入れ替えを促すか（今の鍵で、促す秒を過ぎ、期限の内の入れ替え待ちがないとき真）。機械に伝えるのはアプリ */
        public readonly bool $shouldRotate,
        /** 前の鍵（切り替えた後の猶予の間）で認めたか */
        public readonly bool $viaPrevious,
    ) {}
}
