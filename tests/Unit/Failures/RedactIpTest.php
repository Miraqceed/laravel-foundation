<?php

namespace Miraqceed\Foundation\Tests\Unit\Failures;

use Miraqceed\Foundation\Failures\FailureReporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedactIpTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function spellings(): array
    {
        return [
            'IPv4' => ['203.0.113.5', 'from 203.0.113.5 failed', 'from [ip] failed'],
            'IPv4 の文の終わりの点' => ['203.0.113.5', 'from 203.0.113.5.', 'from [ip].'],
            'IPv4 を ::ffff: の形で' => ['203.0.113.5', 'peer ::ffff:203.0.113.5 and ::ffff:cb00:7105', 'peer [ip] and [ip]'],
            'IPv6 の省略の形' => ['2001:db8::1', 'ip=2001:db8::1;', 'ip=[ip];'],
            'IPv6 の大文字' => ['2001:db8::1', 'ip=2001:DB8::1', 'ip=[ip]'],
            'IPv6 の全部の形' => ['2001:db8::1', 'ip=2001:0db8:0000:0000:0000:0000:0000:0001', 'ip=[ip]'],
            'IPv6 の0を省いた形' => ['2001:db8::1', 'ip=2001:db8:0:0:0:0:0:1', 'ip=[ip]'],
            '渡した側が全部の形' => ['2001:0DB8:0000:0000:0000:0000:0000:0001', 'ip=2001:db8::1', 'ip=[ip]'],
            'IPv4 を埋め込んだ IPv6 の中の IPv4' => ['::ffff:203.0.113.5', "Duplicate entry '203.0.113.5' for key", "Duplicate entry '[ip]' for key"],
            'NAT64 の中の IPv4' => ['64:ff9b::cb00:7105', 'v4=203.0.113.5 v6=64:ff9b::cb00:7105', 'v4=[ip] v6=[ip]'],
            'MySQL の一意の違反' => ['203.0.113.5', "1062 Duplicate entry '203.0.113.5' for key 'c.ip'", "1062 Duplicate entry '[ip]' for key 'c.ip'"],
            'PostgreSQL の一意の違反' => ['2001:db8::1', 'DETAIL:  Key (ip)=(2001:db8::1) already exists.', 'DETAIL:  Key (ip)=([ip]) already exists.'],
        ];
    }

    #[DataProvider('spellings')]
    public function test_書き方の揺れを伏せる(string $ip, string $message, string $expected): void
    {
        $this->assertSame($expected, FailureReporter::redactIp($message, $ip));
    }

    public function test_IPv4_は長い数の一部では当てない(): void
    {
        $message = '203.0.113.50 1203.0.113.5 203.0.113.5.1 203.0.113.5';

        $this->assertSame('203.0.113.50 1203.0.113.5 203.0.113.5.1 [ip]', FailureReporter::redactIp($message, '203.0.113.5'));
    }

    public function test_IPv6_は長いアドレスの一部では当てない(): void
    {
        $this->assertSame('2001:db8::12 [ip]', FailureReporter::redactIp('2001:db8::12 2001:db8::1', '2001:db8::1'));
    }

    public function test_null_や空の_IP_では文が変わらない(): void
    {
        $this->assertSame('from 203.0.113.5', FailureReporter::redactIp('from 203.0.113.5', null));
        $this->assertSame('from 203.0.113.5', FailureReporter::redactIp('from 203.0.113.5', ''));
    }

    public function test_IP_として読めない値は書いたとおりだけを伏せる(): void
    {
        $this->assertSame('client [ip] / CLIENT [ip]', FailureReporter::redactIp('client unknown-host / CLIENT UNKNOWN-HOST', 'unknown-host'));
    }

    public function test_壊れた_UTF8_の文でも投げずに伏せる(): void
    {
        $this->assertSame("\xff [ip] \xfe", FailureReporter::redactIp("\xff 203.0.113.5 \xfe", '203.0.113.5'));
    }
}
