<?php

namespace Miraqceed\Foundation\Tests\Unit\Login;

use Miraqceed\Foundation\Login\CanonicalEmailKey;
use PHPUnit\Framework\TestCase;

class CanonicalEmailKeyTest extends TestCase
{
    public function test_比べの形が同じなら同じ鍵(): void
    {
        $key = new CanonicalEmailKey;
        $base = $key->key('taroyamada@gmail.com');

        foreach (['Taro.Yamada@gmail.com', 'taro.yamada+news@googlemail.com', 'ｔａｒｏｙａｍａｄａ＠ｇｍａｉｌ．ｃｏｍ', ' taroyamada@Gmail.com. '] as $alias) {
            $this->assertSame($base, $key->key($alias), $alias);
        }
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $base);
    }

    public function test_ほかのドメインの点と_plus_は別の鍵(): void
    {
        $key = new CanonicalEmailKey;

        $this->assertNotSame($key->key('taro.yamada@example.com'), $key->key('taroyamada@example.com'));
        $this->assertNotSame($key->key('taro+a@example.com'), $key->key('taro@example.com'));
        $this->assertSame($key->key('Taro@Example.com'), $key->key('taro@example.com'));
    }
}
