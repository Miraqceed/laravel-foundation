<?php

namespace Miraqceed\Foundation\Tests\Feature\OneTimeCodes;

use Illuminate\Support\ServiceProvider;
use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Tests\TestCase;

class OneTimeCodesDisabledTest extends TestCase
{
    public function test_migrations_are_not_loaded_unless_enabled(): void
    {
        // 表の有無は、ほかの試しが作った DB の残りで変わる。マイグレーションの置き場が登録されないことで確かめる
        $paths = array_filter(app('migrator')->paths(), fn ($path) => str_contains($path, 'one-time-code-migrations'));

        $this->assertSame([], array_values($paths));
    }

    public function test_migrations_can_be_published(): void
    {
        $paths = ServiceProvider::pathsToPublish(FoundationServiceProvider::class, 'foundation-one-time-code-migrations');

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('one-time-code-migrations', (string) array_key_first($paths));
        $this->assertDirectoryExists((string) array_key_first($paths));
    }
}
