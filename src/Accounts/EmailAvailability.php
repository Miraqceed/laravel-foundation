<?php

namespace Miraqceed\Foundation\Accounts;

use Illuminate\Database\Eloquent\Model;

/**
 * メールアドレスが、ほかのアカウントに使われているか（設定 foundation.email_changes.availability）。
 * 利用者の表を複数持つアプリ・アドレスを別の表に持つアプリが差し替える
 */
interface EmailAvailability
{
    /** $except 以外のアカウントが $email を使っていれば true */
    public function isTaken(string $email, Model $except): bool;
}
