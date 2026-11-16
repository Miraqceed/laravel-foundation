<?php

namespace Miraqceed\Foundation\Ai;

/**
 * 1回の呼び出しの原価（マイクロ円。1円 = 1,000,000）。
 * actualMicroYen は単価が分かるときの実際の原価、countedMicroYen は上限に数える額、priced は単価が見つかったか。
 */
final readonly class Cost
{
    public function __construct(
        public ?int $actualMicroYen,
        public int $countedMicroYen,
        public bool $priced,
    ) {}
}
