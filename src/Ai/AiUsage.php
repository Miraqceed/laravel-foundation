<?php

namespace Miraqceed\Foundation\Ai;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * AI の呼び出しごとの使用量と原価。古い行はアプリが schedule に入れた model:prune で消す。
 * 日ごとの合計（foundation_ai_totals）は1日1行で小さく、月の合計に要るので消さない。
 *
 * @property int $id
 * @property string $invocation_id
 * @property int $cost_micro_yen
 * @property \Illuminate\Support\Carbon $created_at
 */
class AiUsage extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $table = 'foundation_ai_usages';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime', 'priced' => 'boolean'];

    public function getConnectionName(): ?string
    {
        return AiStore::connectionName();
    }

    /** prune_days 日（既定 90。1未満は1）より前の行 */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('foundation.ai.prune_days', 90));

        return static::query()->where('created_at', '<', now()->subDays($days));
    }
}
