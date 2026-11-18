<?php

namespace Miraqceed\Foundation\Accounts\Fortify;

use Illuminate\Http\Request;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Miraqceed\Foundation\Accounts\PasswordSessions;

/**
 * Fortify でパスワードを変えた後に、ほかのセッションを消し、この端末を入り直させる聞き手。パッケージは登録しない。アプリが登録する：
 *
 *   Event::listen(\Laravel\Fortify\Events\PasswordUpdatedViaController::class, EndSessionsAfterChange::class);
 *
 * キューに載せない（今の要求のセッションと Cookie を作り直す）
 */
final class EndSessionsAfterChange
{
    public function __construct(private readonly PasswordSessions $sessions) {}

    public function handle(PasswordUpdatedViaController $event): void
    {
        // 聞き手は出来事のときに作られるが、要求は呼んだ時点のものを読む
        $this->sessions->afterChange(app(Request::class), $event->user);
    }
}
