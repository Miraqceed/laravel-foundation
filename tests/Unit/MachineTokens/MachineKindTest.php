<?php

namespace Miraqceed\Foundation\Tests\Unit\MachineTokens;

use InvalidArgumentException;
use Miraqceed\Foundation\MachineTokens\MachineKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MachineKindTest extends TestCase
{
    public function test_kind_keeps_its_values_and_defaults(): void
    {
        $kind = new MachineKind('server-agent', 'sag_', 86_400);

        $this->assertSame('server-agent', $kind->name);
        $this->assertSame('sag_', $kind->prefix);
        $this->assertSame(86_400, $kind->rotateAfterSeconds);
        $this->assertSame(600, $kind->graceSeconds);
        $this->assertSame(600, $kind->pendingSeconds);
        $this->assertNull((new MachineKind('worker', 'wk_', null, graceSeconds: 0, pendingSeconds: 1))->rotateAfterSeconds);
    }

    /** @return iterable<string, array{string, string, ?int, int, int}> */
    public static function badKinds(): iterable
    {
        yield 'empty name' => ['', 'sag_', null, 600, 600];
        yield 'name with space' => ['server agent', 'sag_', null, 600, 600];
        yield 'name 65 chars' => [str_repeat('a', 65), 'sag_', null, 600, 600];
        yield 'name trailing newline' => ["server\n", 'sag_', null, 600, 600];
        yield 'prefix upper case' => ['server', 'Sag_', null, 600, 600];
        yield 'prefix without underscore' => ['server', 'sag', null, 600, 600];
        yield 'prefix inner underscore' => ['server', 'sa_g_', null, 600, 600];
        yield 'prefix eighteen chars' => ['server', 'a'.str_repeat('b', 16).'_', null, 600, 600];
        yield 'prefix trailing newline' => ['server', "sag_\n", null, 600, 600];
        yield 'rotate zero' => ['server', 'sag_', 0, 600, 600];
        yield 'grace negative' => ['server', 'sag_', null, -1, 600];
        yield 'pending zero' => ['server', 'sag_', null, 600, 0];
    }

    #[DataProvider('badKinds')]
    public function test_kind_rejects_bad_values(string $name, string $prefix, ?int $rotate, int $grace, int $pending): void
    {
        try {
            new MachineKind($name, $prefix, $rotate, $grace, $pending);
            $this->fail('例外が出なかった');
        } catch (InvalidArgumentException $e) {
            // 文に渡した値を入れない
            if (trim($name) !== '') {
                $this->assertStringNotContainsString(trim($name), $e->getMessage());
            }
            $this->assertStringNotContainsString(trim($prefix), $e->getMessage());
        }
    }
}
