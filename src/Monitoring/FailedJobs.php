<?php

namespace Miraqceed\Foundation\Monitoring;

/** 新しく失敗したジョブの数（FailedJobCheck::read() が返す） */
final class FailedJobs
{
    /**
     * @param  array<string, int>  $counts  「接続:キュー」ごとの数
     * @param  string  $until  数えた範囲の上の端（しおりを進める先）
     */
    public function __construct(
        public readonly array $counts,
        public readonly string $until,
    ) {}

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /** 知らせの本文 */
    public function message(): string
    {
        $lines = [];
        foreach ($this->counts as $queue => $count) {
            $lines[] = "・{$queue}：{$count}件";
        }

        return "新しく失敗したジョブが {$this->total()}件あります（{$this->until} まで）。php artisan queue:failed で確かめてください。\n\n".implode("\n", $lines);
    }
}
