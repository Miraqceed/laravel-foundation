<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** アカウントの守りの試しの利用者（作り物） */
class AccountsUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = ['password', 'remember_token'];
}
