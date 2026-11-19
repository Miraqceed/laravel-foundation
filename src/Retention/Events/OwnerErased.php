<?php

namespace Miraqceed\Foundation\Retention\Events;

/**
 * 持ち主を台帳のとおりに消した（コミットの後）。アプリはこれで監査の記録を書く。
 * 記録には持ち主の種類と id と数だけを書く（消した値は入っていない）
 */
final class OwnerErased
{
    /** @param  array<string, int>  $counts  表 => 消した・書き換えた行の数 */
    public function __construct(
        public readonly string $owner,
        public readonly int|string $id,
        public readonly array $counts,
    ) {}
}
