<?php

namespace Miraqceed\Foundation\Tests\Unit\Login;

use Illuminate\Http\Request;
use Miraqceed\Foundation\Login\IpBucket;
use PHPUnit\Framework\TestCase;

class IpBucketTest extends TestCase
{
    public function test_ipv4_is_kept(): void
    {
        $this->assertSame('203.0.113.5', IpBucket::forIp('203.0.113.5'));
    }

    public function test_ipv6_is_rounded_to_64(): void
    {
        $this->assertSame('2001:db8:1:2::/64', IpBucket::forIp('2001:db8:1:2:aaaa::1'));
        $this->assertSame(IpBucket::forIp('2001:db8:1:2::1'), IpBucket::forIp('2001:db8:1:2:ffff:ffff:ffff:ffff'));
    }

    public function test_mapped_and_nat64_count_as_ipv4(): void
    {
        $this->assertSame('203.0.113.5', IpBucket::forIp('::ffff:203.0.113.5'));
        $this->assertSame('203.0.113.5', IpBucket::forIp('64:ff9b::cb00:7105'));
    }

    public function test_missing_ip_is_one_bucket(): void
    {
        $this->assertSame('', IpBucket::forIp(null));
        $this->assertSame('', IpBucket::forIp(''));
    }

    public function test_unparsable_ip_is_returned_as_is(): void
    {
        $this->assertSame('not-an-ip', IpBucket::forIp('not-an-ip'));
    }

    public function test_of_reads_the_request_ip(): void
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '2001:db8:1:2:aaaa::1']);

        $this->assertSame('2001:db8:1:2::/64', IpBucket::of($request));
    }
}
