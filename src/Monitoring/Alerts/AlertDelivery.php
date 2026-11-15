<?php

namespace Miraqceed\Foundation\Monitoring\Alerts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 送り先ごとの送れたか。
 *
 * @property int $id
 * @property int $alert_id
 * @property string $channel
 * @property ?\Illuminate\Support\Carbon $sent_at
 * @property int $attempts
 * @property ?\Illuminate\Support\Carbon $last_attempted_at
 * @property ?\Illuminate\Support\Carbon $abandoned_at
 * @property ?string $last_error
 */
class AlertDelivery extends Model
{
    public $timestamps = false;

    protected $table = 'foundation_alert_deliveries';

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'abandoned_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class, 'alert_id');
    }
}
