<?php

namespace Miraqceed\Foundation\Tests\Unit\DataFiles;

use InvalidArgumentException;
use Miraqceed\Foundation\DataFiles\Download;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DownloadTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function refusedUrls(): array
    {
        return [
            'http' => ['http://data.example.com/list.txt'],
            'http の手元' => ['http://127.0.0.1:8080/list.txt'],
            '相対' => ['/list.txt'],
            'スキームなし' => ['data.example.com/list.txt'],
            'userinfo つき' => ['https://user:secret@data.example.com/list.txt'],
            'ftp' => ['ftp://data.example.com/list.txt'],
            '空白' => ["https://data.example.com/list.txt\n"],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_refuses_anything_but_https_without_userinfo(string $url): void
    {
        try {
            Download::url($url);
            $this->fail('通した');
        } catch (InvalidArgumentException $e) {
            // 文に URL を入れない
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringNotContainsString('data.example.com', $e->getMessage());
        }
    }

    public function test_is_immutable_and_keeps_its_rules(): void
    {
        $plain = Download::url('https://data.example.com/list.txt?key=x-secret-x#part');
        $gzip = $plain->gzip()->notFoundMeansNotYet()->followRedirects()->withHeaders(['Authorization' => 'Bearer x']);

        $this->assertFalse($plain->isGzip());
        $this->assertFalse($plain->treatsNotFoundAsNotYet());
        $this->assertFalse($plain->followsRedirects());
        $this->assertSame([], $plain->headers());
        $this->assertTrue($gzip->isGzip());
        $this->assertTrue($gzip->treatsNotFoundAsNotYet());
        $this->assertTrue($gzip->followsRedirects());
        $this->assertSame(['Authorization' => 'Bearer x'], $gzip->headers());
        $this->assertSame('https://data.example.com/list.txt?key=x-secret-x#part', $gzip->target());
        // 失敗の文の形：問い・#・userinfo を落とす
        $this->assertSame('https://data.example.com/list.txt', $gzip->redactedUrl());
    }

    public function test_permits_only_https_for_a_public_download(): void
    {
        $download = Download::url('https://data.example.com/list.txt');

        $this->assertTrue($download->permits('https://cdn.example.net/x'));
        $this->assertFalse($download->permits('http://cdn.example.net/x'));
        $this->assertFalse($download->permits('http://127.0.0.1:8080/x'));
        $this->assertFalse($download->permits('https://u:p@cdn.example.net/x'));
    }

    public function test_refuses_header_values_with_line_breaks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Download::url('https://data.example.com/list.txt')->withHeaders(['X-Key' => "a\r\nX-Other: b"]);
    }
}
