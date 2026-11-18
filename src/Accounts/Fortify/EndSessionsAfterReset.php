<?php

namespace Miraqceed\Foundation\Accounts\Fortify;

use Illuminate\Auth\Events\PasswordReset;
use Miraqceed\Foundation\Accounts\PasswordSessions;

/**
 * 再設定の後に、その利用者のすべてのセッションを消す聞き手。パッケージは登録しない。アプリが登録する：
 *
 *   Event::listen(\Illuminate\Auth\Events\PasswordReset::class, EndSessionsAfterReset::class);
 *
 * PasswordReset は Fortify の再設定（CompletePasswordReset）も、Laravel の Password::reset() の雛形も出す。
 * キューに載せない（同じ要求の中で消す）
 */
final class EndSessionsAfterReset
{
    public function __construct(private readonly PasswordSessions $sessions) {}

    public function handle(PasswordReset $event): void
    {
        $this->sessions->afterReset($event->user);
    }
}
