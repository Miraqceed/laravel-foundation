<?php

namespace Miraqceed\Foundation\Attempts;

/**
 * 上限に達していて、照合に進まなかったことを表す
 */
class TooManyAttempts extends \RuntimeException
{
    /**
     * @param  int  $retryAfter  再び試せるまでの秒数（1以上）
     */
    public function __construct(public readonly int $retryAfter, string $message = '試みが上限に達しました。')
    {
        parent::__construct($message);
    }
}
