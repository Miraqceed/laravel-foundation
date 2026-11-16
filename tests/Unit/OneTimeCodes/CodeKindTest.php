<?php

namespace Miraqceed\Foundation\Tests\Unit\OneTimeCodes;

use InvalidArgumentException;
use Miraqceed\Foundation\OneTimeCodes\CodeKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CodeKindTest extends TestCase
{
    public function test_kind_keeps_its_values_and_defaults_ttl_to_600(): void
    {
        $kind = new CodeKind('token-claim', 'apc_');

        $this->assertSame('token-claim', $kind->name);
        $this->assertSame('apc_', $kind->prefix);
        $this->assertSame(600, $kind->ttlSeconds);
        $this->assertSame(60, (new CodeKind('ide.ticket', 'it_', 60))->ttlSeconds);
    }

    /** @return iterable<string, array{string}> */
    public static function badPrefixes(): iterable
    {
        yield 'upper case' => ['App_'];
        yield 'leading underscore' => ['_x_'];
        yield 'no underscore' => ['app'];
        yield 'eighteen chars' => ['a'.str_repeat('b', 16).'_'];
        yield 'hyphen' => ['app-c_'];
        yield 'inner underscore' => ['app_c_'];
        yield 'trailing newline' => ["app_\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('badPrefixes')]
    public function test_kind_rejects_bad_prefix(string $prefix): void
    {
        try {
            new CodeKind('token-claim', $prefix);
            $this->fail('例外が出なかった');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
            if ($prefix !== '') {
                $this->assertStringNotContainsString($prefix, $e->getMessage());
            }
        }
    }

    public function test_kind_accepts_the_longest_prefix(): void
    {
        $prefix = 'a'.str_repeat('b', 15).'_';

        $this->assertSame($prefix, (new CodeKind('x', $prefix))->prefix);
    }

    /** @return iterable<string, array{string, int}> */
    public static function badNamesAndTtls(): iterable
    {
        yield 'empty name' => ['', 600];
        yield '65 char name' => [str_repeat('n', 65), 600];
        yield 'space in name' => ['token claim', 600];
        yield 'newline after name' => ["token-claim\n", 600];
        yield 'zero ttl' => ['token-claim', 0];
        yield 'negative ttl' => ['token-claim', -1];
    }

    #[DataProvider('badNamesAndTtls')]
    public function test_kind_rejects_bad_name_and_ttl(string $name, int $ttl): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CodeKind($name, 'apc_', $ttl);
    }

    public function test_kind_accepts_a_64_char_name(): void
    {
        $this->assertSame(str_repeat('n', 64), (new CodeKind(str_repeat('n', 64), 'apc_'))->name);
    }
}
