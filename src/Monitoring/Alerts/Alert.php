<?php

namespace Miraqceed\Foundation\Monitoring\Alerts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $subject
 * @property string $body
 * @property \Illuminate\Support\Carbon $created_at
 */
class Alert extends Model
{
    public $timestamps = false;

    protected $table = 'foundation_alerts';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class, 'alert_id');
    }
}
