<?php

namespace Miraqceed\Foundation\Tests\Unit\Csv;

use Miraqceed\Foundation\Csv\CellGuard;
use PHPUnit\Framework\TestCase;

class CellGuardTest extends TestCase
{
    public function test_guards_formula_starts(): void
    {
        foreach (['=1+1', '+cmd', '-cmd', '@SUM', "\tx", "\rx", "\nx", '＝1', '＋1', '－1', '＠1'] as $v) {
            $this->assertSame("'".$v, CellGuard::guard($v), json_encode($v));
        }
    }

    public function test_numbers_are_not_guarded(): void
    {
        foreach (['-5', '+3.2', '10', '.5', 'abc'] as $v) {
            $this->assertSame($v, CellGuard::guard($v));
        }
    }

    public function test_unguard_reverses_guard(): void
    {
        foreach (['=1+1', "'=1", '+81-3-1234', '＝1', 'abc', "'abc", '-5'] as $v) {
            $this->assertSame($v, CellGuard::unguard(CellGuard::guard($v)), $v);
        }
    }
}
