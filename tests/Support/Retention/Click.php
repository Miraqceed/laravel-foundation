<?php

namespace Miraqceed\Foundation\Tests\Support\Retention;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** 期限で消すモデル（作り物。90日より前のクリック） */
class Click extends Model
{
    use MassPrunable;

    protected $table = 'clicks';

    public $timestamps = false;

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(90));
    }
}
