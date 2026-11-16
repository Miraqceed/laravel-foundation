<?php

namespace Miraqceed\Foundation\OneTimeCodes;

use Carbon\CarbonImmutable;

/** 出した合言葉。平文はここにだけある（表・ログには書かない）。1度だけ渡す */
final class IssuedCode
{
    public function __construct(
        /** 平文の合言葉 */
        public readonly string $code,
        /** 期限（アプリの時間帯）。この時刻からは受け取れない */
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
