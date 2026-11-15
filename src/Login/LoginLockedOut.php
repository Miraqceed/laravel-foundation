<?php

namespace Miraqceed\Foundation\Login;

/**
 * 失敗の上限に達していて、照合に進まなかったことを表す。
 */
final class LoginLockedOut extends \RuntimeException
{
    /**
     * @param  int  $retryAfter  再び試せるまでの秒数（1以上）
     */
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('ログインの失敗が上限に達しました。');
    }
}
