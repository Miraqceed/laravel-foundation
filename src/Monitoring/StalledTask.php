<?php

namespace Miraqceed\Foundation\Monitoring;

use Carbon\CarbonInterface;

/** 止まっている処理の1つ（StallCheck::stalled() が返す） */
final class StalledTask
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly int $maxAgeMinutes,
        // null は一度も成功していない（見張りが初めて見てから許す古さがたった）
        public readonly ?CarbonInterface $lastSucceededAt,
    ) {}

    /** 知らせ・画面に出す1行。foundation:watch の知らせの本文の行と同じ */
    public function message(): string
    {
        if ($this->lastSucceededAt === null) {
            return "{$this->label}：一度も成功していません";
        }

        return "{$this->label}：最後の成功が{$this->maxAgeMinutes}分より前です（{$this->lastSucceededAt->toIso8601String()}）";
    }
}
