<?php

namespace Miraqceed\Foundation\Tests\Support\Retention;

use Illuminate\Database\Eloquent\Model;

/** users と別の表の持ち主（作り物。多態の列で、同じ id でも消さないことを確かめる） */
class RetentionAdmin extends Model
{
    protected $table = 'admins';
}
