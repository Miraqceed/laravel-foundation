<?php

namespace Miraqceed\Foundation\OneTimeCodes;

use Carbon\CarbonImmutable;

/** 受け取れた合言葉。受け取った後の処理（$then）の戻り値は result に入る */
final class ClaimedCode
{
    /**
     * @param  array<array-key, mixed>  $data  出すときに添えたもの
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $subject,
        public readonly array $data,
        /** 出した時刻（アプリの時間帯） */
        public readonly CarbonImmutable $issuedAt,
        /** 受け取った後の処理の戻り値（渡さなければ null） */
        public readonly mixed $result = null,
    ) {}

    /** 受け取った後の処理の戻り値を入れた写し */
    public function withResult(mixed $result): self
    {
        return new self($this->kind, $this->subject, $this->data, $this->issuedAt, $result);
    }
}
