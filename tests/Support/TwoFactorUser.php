<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** 二段階の認証の試しの利用者（作り物） */
class TwoFactorUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
