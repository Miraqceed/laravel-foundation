<?php

namespace Miraqceed\Foundation\Tests\Unit\Monitoring;

use Miraqceed\Foundation\Monitoring\DatabaseSize;
use PHPUnit\Framework\TestCase;

class DatabaseSizeTest extends TestCase
{
    public function test_表ごとのファイルなら各表の空きを足す(): void
    {
        $this->assertSame(100 + 10 + 5 + 200 + 20 + 7, DatabaseSize::total([[100, 10, 5], [200, 20, 7]], true));
    }

    public function test_共有の表領域なら空きは一番大きい1つだけを足す(): void
    {
        // どの表もファイル全体の空き（7）を返す。足すと何倍にも数える
        $this->assertSame(100 + 10 + 200 + 20 + 7, DatabaseSize::total([[100, 10, 7], [200, 20, 7]], false));
    }

    public function test_表がなければ0(): void
    {
        $this->assertSame(0, DatabaseSize::total([], false));
        $this->assertSame(0, DatabaseSize::total([], true));
    }
}
