<?php

namespace Miraqceed\Foundation\Tests\Feature\Notices;

use Miraqceed\Foundation\Tests\TestCase;

class OnceNoticesDisabledTest extends TestCase
{
    public function test_有効でなければ_マイグレーションを読み込まない(): void
    {
        // 表の有無は、ほかの試しが作った DB の残りで変わる。マイグレーションの置き場が登録されないことで確かめる
        $paths = array_filter(app('migrator')->paths(), fn ($path) => str_contains($path, 'notice-migrations'));

        $this->assertSame([], array_values($paths));
    }
}
