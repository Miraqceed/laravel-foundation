<?php

namespace Miraqceed\Foundation\Monitoring;

/** 1つのキューの溜まり（QueueCheck::states() が返す）。件数と待ちだけで、ジョブの中身は持たない */
final class QueueState
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $allowedMinutes,
        // false は読めなかった（DB が落ちている・表がない・設定の誤り）
        public readonly bool $readable,
        // 取れるジョブ（待ちの時刻を過ぎ、まだ取られていない）
        public readonly int $ready = 0,
        // 取られてから retry_after を過ぎた予約（worker が落ちた。次の worker が取り直す）
        public readonly int $expiredReservations = 0,
        // 一番長く待っているジョブの待ち（秒）。取れるジョブも期限切れの予約もなければ null
        public readonly ?int $oldestWaitSeconds = null,
    ) {}

    /** 止まっている（読めない、または一番長い待ちが許す待ちを超えた） */
    public function stalled(): bool
    {
        return ! $this->readable
            || ($this->oldestWaitSeconds !== null && $this->oldestWaitSeconds > $this->allowedMinutes * 60);
    }

    /** 知らせ・画面に出す1行 */
    public function message(): string
    {
        $name = "キュー {$this->connection}:{$this->queue}";

        if (! $this->readable) {
            return "{$name}：溜まりを読めません";
        }

        $minutes = intdiv((int) $this->oldestWaitSeconds, 60);

        return "{$name}：{$minutes}分待っているジョブがあります（許す待ち {$this->allowedMinutes}分。取れるジョブ {$this->ready}件・期限切れの予約 {$this->expiredReservations}件）";
    }
}
