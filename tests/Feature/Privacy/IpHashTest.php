<?php

namespace Miraqceed\Foundation\Tests\Feature\Privacy;

use Illuminate\Http\Request;
use Miraqceed\Foundation\Privacy\IpHash;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * IpHash：DB に残す IP の値を、APP_KEY と別の鍵の HMAC-SHA256 にする
 */
class IpHashTest extends TestCase
{
    private const KEY = 'ip-hash-key-for-tests-only-0123456789';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.privacy.ip_hash_key', self::KEY);
    }

    public function test_it_is_the_hmac_sha256_of_the_address_with_the_configured_key(): void
    {
        $this->assertSame(hash_hmac('sha256', '203.0.113.7', self::KEY), IpHash::of('203.0.113.7'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', IpHash::of('2001:db8::7'));
    }

    public function test_spellings_of_the_same_address_give_the_same_value(): void
    {
        $value = IpHash::of('2001:db8::7');

        foreach (['2001:DB8::7', '2001:0db8:0000:0000:0000:0000:0000:0007', '2001:db8:0:0:0:0:0:7', ' 2001:db8::7 '] as $spelling) {
            $this->assertSame($value, IpHash::of($spelling), $spelling);
        }
        $this->assertNotSame($value, IpHash::of('2001:db8::8'));
    }

    public function test_it_does_not_use_the_app_key(): void
    {
        $before = IpHash::of('203.0.113.7');

        config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

        $this->assertSame($before, IpHash::of('203.0.113.7'));
    }

    public function test_another_key_gives_another_value(): void
    {
        $before = IpHash::of('203.0.113.7');

        config(['foundation.privacy.ip_hash_key' => 'another-ip-hash-key-for-tests-0123456789']);

        $this->assertNotSame($before, IpHash::of('203.0.113.7'));
    }

    public function test_an_empty_or_short_key_stops_without_showing_the_key(): void
    {
        foreach ([null, '', str_repeat('s', 31)] as $key) {
            config(['foundation.privacy.ip_hash_key' => $key]);

            try {
                IpHash::of('203.0.113.7');
                $this->fail('短い鍵で作った');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('foundation.privacy.ip_hash_key', $e->getMessage());
                if (is_string($key) && $key !== '') {
                    $this->assertStringNotContainsString($key, $e->getMessage());
                }
            }
        }

        config(['foundation.privacy.ip_hash_key' => str_repeat('s', 32)]);
        $this->assertSame(64, strlen(IpHash::of('203.0.113.7')));
    }

    public function test_a_value_that_is_not_an_ip_is_refused_without_showing_it(): void
    {
        foreach (['not-an-ip', '203.0.113.256', '', null, '2001:db8::7%eth0'] as $value) {
            try {
                IpHash::of($value);
                $this->fail('IP でない値で作った');
            } catch (\InvalidArgumentException $e) {
                if (is_string($value) && $value !== '') {
                    $this->assertStringNotContainsString($value, $e->getMessage());
                }
            }
        }
    }

    public function test_of_bucket_uses_the_slash_64_for_ipv6_and_the_address_for_ipv4(): void
    {
        $this->assertSame(IpHash::ofBucket('2001:db8:1:2::7'), IpHash::ofBucket('2001:db8:1:2:ffff::9'));
        $this->assertNotSame(IpHash::ofBucket('2001:db8:1:2::7'), IpHash::ofBucket('2001:db8:1:3::7'));
        $this->assertNotSame(IpHash::of('2001:db8:1:2::7'), IpHash::ofBucket('2001:db8:1:2::7'));

        $this->assertSame(IpHash::of('203.0.113.7'), IpHash::ofBucket('203.0.113.7'));
        $this->assertNotSame(IpHash::ofBucket('203.0.113.7'), IpHash::ofBucket('203.0.113.8'));
        // IPv4 を埋め込んだ IPv6 は IPv4 として（IpBucket と同じ）
        $this->assertSame(IpHash::of('203.0.113.7'), IpHash::ofBucket('::ffff:203.0.113.7'));

        $request = Request::create('/', server: ['REMOTE_ADDR' => '2001:db8:1:2::7']);
        $this->assertSame(IpHash::ofBucket('2001:db8:1:2::7'), IpHash::ofBucket($request));
    }
}
