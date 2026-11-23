<?php

namespace Miraqceed\Foundation\Tests\Support\DataFiles;

use RuntimeException;

/** 作ると投げる種類（プロバイダーの起動で種類を作らないことを確かめる） */
final class ThrowingSource extends TestSource
{
    public static int $made = 0;

    public function __construct()
    {
        self::$made++;
        throw new RuntimeException('made while booting');
    }
}
