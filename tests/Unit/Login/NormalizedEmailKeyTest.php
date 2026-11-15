<?php

namespace Miraqceed\Foundation\Tests\Unit\Login;

use Miraqceed\Foundation\Login\NormalizedEmailKey;
use PHPUnit\Framework\TestCase;

class NormalizedEmailKeyTest extends TestCase
{
    public function test_email_variants_share_key(): void
    {
        $key = new NormalizedEmailKey();

        $base = $key->key('a@example.com');
        $this->assertSame($base, $key->key('A@Example.com'));
        $this->assertSame($base, $key->key('a@example.com '));
        $this->assertSame($base, $key->key("\u{3000}a@example.com\u{00A0}"));
        $this->assertSame($base, $key->key('ａ@example.com'));
        $this->assertNotSame($base, $key->key('b@example.com'));
    }

    public function test_key_is_fixed_length_hex(): void
    {
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', (new NormalizedEmailKey())->key('a@example.com'));
    }
}
