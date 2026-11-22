<?php

namespace Miraqceed\Foundation\MachineTokens;

/** 出した鍵。平文はここにだけある（表・ログには書かない）。1度だけ渡す */
final class IssuedToken
{
    public function __construct(
        /** 平文の鍵（頭の印 + 英数字40字） */
        #[\SensitiveParameter]
        public readonly string $token,
        /** 種類の名前 */
        public readonly string $kind,
        public readonly string $subject,
    ) {}

    /** var_dump・dump()・print_r に平文を出さない */
    public function __debugInfo(): array
    {
        return ['token' => '[伏せた]', 'kind' => $this->kind, 'subject' => $this->subject];
    }
}
