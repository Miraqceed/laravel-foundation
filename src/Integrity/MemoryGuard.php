<?php

namespace Miraqceed\Foundation\Integrity;

/**
 * 安全網：個別の上限の見落としを、落ちる前に「確かめられなかった（メモリーの上限）」に替える最後の守り。
 * 1回の割り当てで一気に増える分は止められないので、それは個別の上限で抑える。
 *
 * @internal
 */
final class MemoryGuard
{
    /** この件数ごとに見る */
    public const EVERY = 256;

    private int $ticks = 0;

    public function __construct(private readonly ?int $budget = null) {}

    public function budget(): int
    {
        return $this->budget ?? Limits::budget();
    }

    /** 今、網の上限を超えているか */
    public function exceeded(): bool
    {
        return memory_get_usage() > $this->budget();
    }

    /** 項目ごとに呼ぶ。EVERY 件ごとにだけ見る */
    public function tick(): bool
    {
        if (++$this->ticks % self::EVERY !== 0) {
            return false;
        }

        return $this->exceeded();
    }

    /** これから $bytes を使っても、網の残りの $ratio の内に収まるか（丸ごと読む前に） */
    public function fits(int $bytes, float $ratio = 1.0): bool
    {
        return $bytes <= (int) (($this->budget() - memory_get_usage()) * $ratio);
    }

    /** 網の残り（バイト） */
    public function remaining(): int
    {
        return max(0, $this->budget() - memory_get_usage());
    }
}
