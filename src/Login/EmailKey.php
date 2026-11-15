<?php

namespace Miraqceed\Foundation\Login;

/**
 * メールアドレスを、回数の制限で「同じ」とみなす範囲ごとに1つの値へ写す。
 */
interface EmailKey
{
    /**
     * @return string 固定長の16進（DB のキャッシュの鍵の長さに収まる）
     */
    public function key(string $email): string;
}
