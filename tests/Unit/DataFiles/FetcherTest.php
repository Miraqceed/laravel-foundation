<?php

namespace Miraqceed\Foundation\Tests\Unit\DataFiles;

use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\DataFiles\DataFileException;
use Miraqceed\Foundation\DataFiles\Download;
use Miraqceed\Foundation\DataFiles\Fetcher;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 手元の口（http）では作れない https の形だけを、Http::fake で確かめる（本物の経路は Integration\DataFiles の試し）
 */
class FetcherTest extends TestCase
{
    private string $sink;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->sink = tempnam(sys_get_temp_dir(), 'df-sink');
    }

    protected function tearDown(): void
    {
        @unlink($this->sink);
        parent::tearDown();
    }

    public function test_never_follows_a_redirect_from_https_to_http(): void
    {
        Http::fake([
            'https://data.example.com/list.txt' => Http::response('', 302, ['Location' => 'http://data.example.com/list.txt']),
            'http://data.example.com/*' => Http::response("plain\n"),
        ]);

        try {
            (new Fetcher)->fetch('sample', Download::url('https://data.example.com/list.txt')->followRedirects(), $this->sink, 1024);
            $this->fail('http へ従った');
        } catch (DataFileException $e) {
            $this->assertStringContainsString('sample：許さない先へのリダイレクトです（http://data.example.com/list.txt）', $e->getMessage());
        }
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'http://'));
    }

    public function test_follows_a_redirect_to_https_when_allowed(): void
    {
        Http::fake([
            'https://data.example.com/list.txt' => Http::response('', 302, ['Location' => 'https://cdn.example.net/list.txt']),
            'https://cdn.example.net/list.txt' => Http::response("cdn\n"),
        ]);

        $this->assertSame(200, (new Fetcher)->fetch('sample', Download::url('https://data.example.com/list.txt')->followRedirects(), $this->sink, 1024));
        $this->assertSame("cdn\n", file_get_contents($this->sink));
    }
}
