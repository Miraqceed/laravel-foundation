<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RowsParent extends Model
{
    protected $table = 'rows_parents';

    public $timestamps = false;

    protected $guarded = [];

    public function children(): HasMany
    {
        return $this->hasMany(RowsChild::class, 'parent_id');
    }
}
