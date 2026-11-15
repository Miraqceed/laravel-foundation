<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** グローバルスコープ（get の時に足される条件）を持つモデル */
class RowsScoped extends Model
{
    protected $table = 'rows_parents';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('named', function (Builder $q) {
            $q->where('name', 'like', 'p%')->orWhere('name', 'never');
        });
    }
}
