<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** 利用者と別の表（admins）の、二段階の認証の試しの管理者（作り物） */
class TwoFactorAdmin extends Model
{
    protected $table = 'admins';

    protected $guarded = [];

    public $timestamps = false;
}
