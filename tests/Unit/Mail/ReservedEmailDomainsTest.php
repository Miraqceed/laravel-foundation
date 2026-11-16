<?php

namespace Miraqceed\Foundation\Tests\Unit\Mail;

use Miraqceed\Foundation\Mail\ReservedEmailDomains;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        yield 'ASCII case and domain whitespace' => ["person@ \tExAmPlE.CoM\r\n", true];
        yield 'reserved TLD with root dot' => ['person@one.invalid.', true];
        yield 'reserved TLD with several trailing dots' => ['person@one.test...', true];
        yield 'reserved domain with root dot' => ['person@example.com.', true];
        yield 'reserved domain with several trailing dots' => ['person@example.net...', true];
        yield 'reserved subdomain with root dot' => ['person@one.example.org.', true];
        yield 'reserved subdomain with several trailing dots' => ['person@one.example.com...', true];
        yield 'normal domain' => ['person@ordinary.tld', false];
        yield 'lookalike domain prefix' => ['person@notexample.com', false];
        yield 'lookalike subdomain prefix' => ['person@one.notexample.org', false];
        yield 'reserved domain followed by another domain' => ['person@example.com.other.tld', false];
        yield 'reserved TLD in a middle label' => ['person@invalid.other.tld', false];
        yield 'TLD with extra characters' => ['person@one.invalidsuffix', false];
        yield 'similar example name' => ['person@example.co', false];
        yield 'outside the selected special-use names' => ['person@host.local', false];
        yield 'no at sign' => ['example.com', false];
        yield 'empty address' => ['', false];
        yield 'no domain' => ['person@', false];
        yield 'whitespace domain' => ["person@ \t\n", false];
        yield 'domain containing dots only' => ['person@...', false];
        yield 'no local-part validation' => ['@example.com', true];
        yield 'last ASCII at sign determines the domain' => ['person@@example.com', true];
        yield 'no validation of empty labels' => ['person@one..example.com', true];
        yield 'full-width at is not converted' => ['person＠example.com', false];
        yield 'full-width dot is not converted' => ['person@example。com', false];
        yield 'Unicode whitespace is not PHP trim whitespace' => ["person@example.com\u{00A0}", false];
        yield 'domain whitespace is not removed internally' => ['person@exam ple.com', false];
    }
}
