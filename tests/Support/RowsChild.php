<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class RowsChild extends Model
{
    protected $table = 'rows_children';

    public $timestamps = false;

    protected $guarded = [];
}
