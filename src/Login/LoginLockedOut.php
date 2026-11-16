<?php

namespace Miraqceed\Foundation\Login;

use Miraqceed\Foundation\Attempts\TooManyAttempts;

/**
 * ログインの失敗が上限に達していて、照合に進まなかったことを表す。
 * 試みの数えの例外（TooManyAttempts）の子。catch (LoginLockedOut) も catch (TooManyAttempts) も捕まえる
 */
final class LoginLockedOut extends TooManyAttempts
{
    /**
     * @param  int  $retryAfter  再び試せるまでの秒数（1以上）
     */
    public function __construct(int $retryAfter)
    {
        parent::__construct($retryAfter, 'ログインの失敗が上限に達しました。');
    }
}
