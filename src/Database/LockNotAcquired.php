<?php

namespace Miraqceed\Foundation\Database;

use RuntimeException;

/**
 * NamedLock::run() で、待つ時間の上限までにロックを取れなかった。アプリは 409・503 などの断りにする。
 * 文にロックの名前を入れない（名前に利用者の id・IP から作った値を入れるアプリがある）
 */
final class LockNotAcquired extends RuntimeException
{
    public function __construct(public readonly int $waitSeconds)
    {
        parent::__construct("名前つきのロックを {$waitSeconds} 秒の間に取れませんでした");
    }
}
