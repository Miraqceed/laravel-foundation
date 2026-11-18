<?php

namespace Miraqceed\Foundation\Tests\Unit\Mail;

use Miraqceed\Foundation\Mail\ReservedEmailDomains;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * アプリを起こさずに呼ぶ（判定は設定・DB・DNS を読まない）。
 */
class ReservedEmailDomainsTest extends TestCase
{
    #[DataProvider('addresses')]
    public function test_reserved_domain_classification(string $address, bool $expected): void
    {
        $this->assertSame($expected, ReservedEmailDomains::isReserved($address));
    }

    public static function addresses(): iterable
    {
        yield 'invalid TLD' => ['person@invalid', true];
        yield 'test TLD' => ['person@test', true];
        yield 'example TLD' => ['person@example', true];
        yield 'localhost' => ['person@localhost', true];
        yield 'under invalid' => ['person@one.two.invalid', true];
        yield 'under test' => ['person@one.test', true];
        yield 'under example' => ['person@one.example', true];
        yield 'under localhost' => ['person@one.localhost', true];
        yield 'example com' => ['person@example.com', true];
        yield 'example net' => ['person@example.net', true];
        yield 'example org' => ['person@example.org', true];
        yield 'subdomain of example com' => ['person@one.example.com', true];
        yield 'subdomain of example net' => ['person@one.two.example.net', true];
        yield 'subdomain of example org' => ['person@one.example.org', true];
        yield 'placeholder of a deleted account' => ['deleted-5@anonymized.invalid', true];
        yield 'ASCII case and domain whitespace' => ["person@ \tExAmPlE.CoM\r\n", true];
        yield 'upper-case TLD' => ['person@Foo.INVALID', true];
        yield 'reserved TLD with root dot' => ['person@one.invalid.', true];
        yield 'reserved TLD with several trailing dots' => ['person@one.test...', true];
        yield 'reserved domain with root dot' => ['person@example.com.', true];
        yield 'reserved domain with several trailing dots' => ['person@example.net...', true];
        yield 'reserved subdomain with root dot' => ['person@one.example.org.', true];
        yield 'reserved subdomain with several trailing dots' => ['person@one.example.com...', true];

        // 点のないドメインはインターネットの宛先にならない
        yield 'dotless domain' => ['person@intranet', true];
        yield 'dotless domain with root dot' => ['person@intranet.', true];

        // 全角の英数字・全角のピリオド・句点は、ブラウザやメールのソフトと同じく半角の名前として読む
        yield 'full-width domain' => ['ａ@ｅｘａｍｐｌｅ．ｃｏｍ', true];
        yield 'ideographic full stop' => ['person@example。com', true];
        yield 'half-width ideographic full stop' => ['person@example｡invalid', true];
        yield 'full-width upper case' => ['person@ＥＸＡＭＰＬＥ．ＯＲＧ', true];

        // IDN にできない値でも、全角を半角にして判定する
        yield 'empty label' => ['person@example..invalid', true];
        yield 'full-width with an empty label' => ['a@ｅｘａｍｐｌｅ．．ｉｎｖａｌｉｄ', true];
        yield 'label longer than 63' => ['person@'.str_repeat('a', 64).'.com', false];
        yield 'label longer than 63 under example com' => ['person@'.str_repeat('a', 64).'.example.com', true];
        yield 'hyphen-only label' => ['person@-.com', false];

        yield 'normal domain' => ['person@ordinary.tld', false];
        yield 'free mail domain' => ['person@gmail.com', false];
        yield 'Japanese example-like domain' => ['person@example.co.zz', false];
        yield 'internationalized domain' => ['person@例え.zz', false];
        yield 'lookalike domain prefix' => ['person@notexample.com', false];
        yield 'lookalike subdomain prefix' => ['person@one.notexample.org', false];
        yield 'reserved domain followed by another domain' => ['person@example.com.other.tld', false];
        yield 'reserved domain followed by a country code' => ['person@example.com.zz', false];
        yield 'reserved TLD in a middle label' => ['person@invalid.other.tld', false];
        yield 'reserved TLD as the first label' => ['person@invalid.zz', false];
        yield 'reserved names in the middle' => ['person@test.example.co.zz', false];
        yield 'TLD with extra characters' => ['person@one.invalidsuffix', false];
        yield 'TLD-like label' => ['person@testing.zz', false];
        yield 'similar example name' => ['person@example.zz', false];
        yield 'outside the selected special-use names' => ['person@host.local', false];
        yield 'no at sign' => ['example.com', false];
        yield 'no at sign and no dot' => ['taro', false];
        yield 'empty address' => ['', false];
        yield 'no domain' => ['person@', false];
        yield 'whitespace domain' => ["person@ \t\n", false];
        yield 'domain containing dots only' => ['person@...', false];
        yield 'no local-part validation' => ['@example.com', true];
        yield 'last ASCII at sign determines the domain' => ['person@@example.com', true];
        yield 'no validation of empty labels' => ['person@one..example.com', true];
        yield 'full-width at is not an at sign' => ['person＠example.com', false];
        yield 'Unicode whitespace is not PHP trim whitespace' => ["person@example.com\u{00A0}", false];
        yield 'domain whitespace is not removed internally' => ['person@exam ple.com', false];
    }

    public function test_IDN_にできない値でも警告を出さない(): void
    {
        set_error_handler(function (int $errno, string $message): never {
            throw new \ErrorException($message, 0, $errno);
        });

        try {
            foreach (['person@example..com', 'person@'.str_repeat('a', 64).'.com', 'person@-.com', "person@\xff.com"] as $address) {
                ReservedEmailDomains::isReserved($address);
            }
        } finally {
            restore_error_handler();
        }

        $this->addToAssertionCount(1);
    }
}
