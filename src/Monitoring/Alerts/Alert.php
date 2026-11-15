<?php

namespace Miraqceed\Foundation\Monitoring\Alerts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 運営への知らせ。送り先ごとの行（AlertDelivery）は外部キーの cascade で一緒に消える。
 * 古い知らせはアプリが schedule に入れた model:prune で消す（prunable()）。
 *
 * @property int $id
 * @property string $key
 * @property string $subject
 * @property string $body
 * @property \Illuminate\Support\Carbon $created_at
 */
class Alert extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $table = 'foundation_alerts';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class, 'alert_id');
    }

    /**
     * すべての送り先が送れたか諦めて、prune_days 日（既定 90）たった知らせ。送り先のない知らせは作ってから数える。
     * まだ送り直す送り先が残る知らせは消さない。
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(max(1, (int) config('foundation.monitoring.alerts.prune_days', 90)));

        return static::query()
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('deliveries', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->whereNull('sent_at')->whereNull('abandoned_at'))
                ->orWhere('sent_at', '>', $cutoff)
                ->orWhere('abandoned_at', '>', $cutoff)));
    }
}
