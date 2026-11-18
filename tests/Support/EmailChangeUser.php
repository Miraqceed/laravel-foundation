<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** メールアドレスの変更の試しの利用者（作り物。確認済みの印を持つ） */
class EmailChangeUser extends Authenticatable implements MustVerifyEmailContract
{
    use MustVerifyEmail;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = ['password', 'remember_token'];
}
