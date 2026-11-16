<?php

namespace Miraqceed\Foundation\Tests\Unit\OneTimeCodes;

use Miraqceed\Foundation\OneTimeCodes\CodeFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CodeFormatTest extends TestCase
{
    public function test_generated_code_has_the_prefix_and_forty_alphanumerics(): void
    {
        $code = CodeFormat::generate('apc_');

        $this->assertMatchesRegularExpression('/\Aapc_[A-Za-z0-9]{40}\z/', $code);
        $this->assertTrue(CodeFormat::matches('apc_', $code));
    }

    /** 1000個作って重ならず、62字のどれも出る（偏りの粗い確かめ） */
    public function test_generated_codes_are_distinct_and_use_every_character(): void
    {
        $codes = [];
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $code = CodeFormat::generate('it_');
            $codes[$code] = true;
            foreach (str_split(substr($code, 3)) as $char) {
                $seen[$char] = true;
            }
        }

        $this->assertCount(1000, $codes);
        $this->assertCount(62, $seen);
    }

    /** @return iterable<string, array{mixed}> */
    public static function wrongShapes(): iterable
    {
        $body = str_repeat('aB3', 13).'x'; // 40字
        yield '39 chars' => ['apc_'.substr($body, 1)];
        yield '41 chars' => ['apc_'.$body.'y'];
        yield 'trailing newline' => ['apc_'.$body."\n"];
        yield 'leading space' => [' apc_'.$body];
        yield 'other prefix' => ['apd_'.$body];
        yield 'no prefix' => [$body];
        yield 'symbol' => ['apc_'.substr($body, 1).'-'];
        yield 'full-width alphanumerics' => ['apc_'.substr($body, 1).'Ａ'];
        yield 'array' => [['apc_'.$body]];
        yield 'null' => [null];
        yield 'integer' => [12345];
    }

    #[DataProvider('wrongShapes')]
    public function test_matches_rejects_wrong_shapes(mixed $value): void
    {
        $this->assertFalse(CodeFormat::matches('apc_', $value));
    }

    public function test_matches_accepts_the_exact_shape(): void
    {
        $this->assertTrue(CodeFormat::matches('apc_', 'apc_'.str_repeat('aB3', 13).'x'));
    }

    public function test_hash_is_sha256_hex(): void
    {
        $this->assertSame(hash('sha256', 'apc_x'), CodeFormat::hash('apc_x'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', CodeFormat::hash(CodeFormat::generate('it_')));
    }
}
