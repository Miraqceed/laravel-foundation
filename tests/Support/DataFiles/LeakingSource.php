<?php

namespace Miraqceed\Foundation\Tests\Support\DataFiles;

use InvalidArgumentException;

/**
 * 作ると、目印の文（作り物の鍵の形）を入れた InvalidArgumentException（LogicException の子）を投げる種類。
 * アプリのコードから来た例外の文を、命令の出力・知らせ・点検に出さないことを確かめる
 */
final class LeakingSource extends TestSource
{
    public const MARK = 'lk_live_x-secret-mark-0000';

    public function __construct()
    {
        throw new InvalidArgumentException('cannot read the key '.self::MARK);
    }
}
