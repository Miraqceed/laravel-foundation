<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Database\Eloquent\Casts\Attribute;

/** 保存するときにメールアドレスを小文字にそろえる利用者（作り物。そろえて保存するアプリの形） */
class LowercaseEmailChangeUser extends EmailChangeUser
{
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower($value));
    }
}
