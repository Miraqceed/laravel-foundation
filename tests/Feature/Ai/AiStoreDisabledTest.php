<?php

namespace Miraqceed\Foundation\Tests\Feature\Ai;

use Miraqceed\Foundation\Tests\TestCase;

class AiStoreDisabledTest extends TestCase
{
    public function test_入れただけでは表を作らない(): void
    {
        // 表の有無は、ほかの試しが作った DB の残りで変わる。マイグレーションの置き場が登録されないことで確かめる
        $ai = array_filter(app('migrator')->paths(), fn ($path) => str_contains($path, 'ai-migrations'));

        $this->assertSame([], array_values($ai));
    }
}
