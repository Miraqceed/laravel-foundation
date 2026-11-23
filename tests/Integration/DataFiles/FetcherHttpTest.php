<?php

namespace Miraqceed\Foundation\Tests\Integration\DataFiles;

use Miraqceed\Foundation\DataFiles\DataFileException;
use Miraqceed\Foundation\DataFiles\Fetcher;
use Miraqceed\Foundation\Tests\Support\DataFiles\LocalHttpServer;
use Miraqceed\Foundation\Tests\Support\DataFiles\LoopbackDownload;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 取る所を、手元の HTTP の口（php -S）で確かめる：リダイレクト・受けている途中の大きさの上限・切れた本文・名乗り
 */
class FetcherHttpTest extends TestCase
{
    private LocalHttpServer $http;

    private string $sink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new LocalHttpServer;
        $this->sink = $this->http->directory.'/sink-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->http->stop();
        parent::tearDown();
    }

    /** 大きな本文のファイル（試しのプロセスのメモリーに全体を持たない） */
    private function bigFile(int $megabytes): string
    {
        $path = $this->http->directory.'/big-'.$megabytes;
        $handle = fopen($path, 'wb');
        $block = str_repeat('z', 1024 * 1024);
        for ($i = 0; $i < $megabytes; $i++) {
            fwrite($handle, $block);
        }
        fclose($handle);

        return $path;
    }

    private function fetchFailure(\Closure $fetch): string
    {
        try {
            $fetch();
        } catch (DataFileException $e) {
            return $e->getMessage();
        }
        $this->fail('失敗しなかった');
    }

    public function test_streams_the_body_into_the_file_and_names_only_the_package(): void
    {
        $this->http->route('/a.txt', ['body' => "hello\n"]);

        $status = (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/a.txt'))->withHeaders(['X-Api-Key' => 'k']), $this->sink, 1024);

        $this->assertSame(200, $status);
        $this->assertSame("hello\n", file_get_contents($this->sink));
        $request = $this->http->requests()[0];
        $this->assertSame('miraqceed/laravel-foundation', $request['ua']);
        $this->assertSame('k', $request['h']['X-Api-Key'] ?? null);
    }

    public function test_follows_redirects_only_when_allowed_within_the_same_origin_and_at_most_three_times(): void
    {
        $this->http->route('/r1', ['status' => 302, 'headers' => ['Location' => '/r2'], 'body' => '']);
        $this->http->route('/r2', ['status' => 302, 'headers' => ['Location' => '/r3'], 'body' => '']);
        $this->http->route('/r3', ['status' => 302, 'headers' => ['Location' => '/final'], 'body' => '']);
        $this->http->route('/r0', ['status' => 302, 'headers' => ['Location' => '/r1'], 'body' => '']);
        $this->http->route('/final', ['body' => "done\n"]);

        // 既定で従わない
        $this->assertStringContainsString('HTTP 302。リダイレクトには従いません', $this->fetchFailure(
            fn () => (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/r3')), $this->sink, 1024),
        ));

        // 許せば3回まで従う
        $this->assertSame(200, (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/r1'))->followRedirects(), $this->sink, 1024));
        $this->assertSame("done\n", file_get_contents($this->sink));

        // 4回目で失敗
        $this->assertStringContainsString('を取れません', $this->fetchFailure(
            fn () => (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/r0'))->followRedirects(), $this->sink, 1024),
        ));

        // ほかの元（別の番号の口）へは従わない
        $other = new LocalHttpServer(1);
        try {
            $other->route('/x', ['body' => "other\n"]);
            $this->http->route('/away', ['status' => 302, 'headers' => ['Location' => $other->url('/x')], 'body' => '']);
            $this->assertStringContainsString('許さない先へのリダイレクトです', $this->fetchFailure(
                fn () => (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/away'))->followRedirects(), $this->sink, 1024),
            ));
            $this->assertSame([], $other->requests());
        } finally {
            $other->stop();
        }
    }

    /** 設計書 10.1 の3つ目：sink と進みの知らせで、受けている途中に止まる */
    public function test_stops_an_undeclared_large_body_while_receiving(): void
    {
        $limit = 256 * 1024;
        $this->http->route('/big', ['file' => $this->bigFile(20), 'undeclared' => true, 'trickle' => [256 * 1024, 20_000]]);

        $message = $this->fetchFailure(fn () => (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/big')), $this->sink, $limit));

        $this->assertStringContainsString('大きすぎます', $message);
        clearstatcache();
        $received = is_file($this->sink) ? filesize($this->sink) : 0;
        // 上限の少し上で止まる（20MB を受け切らない）
        $this->assertLessThan(4 * 1024 * 1024, $received, "止まった時に {$received} バイト受けていた");
    }

    public function test_declared_size_beyond_the_limit_fails_before_reading_the_body(): void
    {
        $this->http->route('/big', ['file' => $this->bigFile(4), 'trickle' => [256 * 1024, 20_000]]);

        $message = $this->fetchFailure(fn () => (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/big')), $this->sink, 1024));

        $this->assertStringContainsString('大きすぎます', $message);
        clearstatcache();
        $this->assertLessThan(512 * 1024, is_file($this->sink) ? filesize($this->sink) : 0);
    }

    /** 設計書 10.1 の4つ目：名乗った長さより短い本文は例外になる */
    public function test_a_body_cut_before_its_declared_length_fails(): void
    {
        $this->http->route('/cut', ['body' => str_repeat('a', 10_000), 'truncate' => 1000]);

        $message = $this->fetchFailure(fn () => (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/cut')), $this->sink, 1024 * 1024));

        $this->assertStringContainsString('を取れません', $message);
    }

    public function test_a_not_found_answer_is_returned_only_for_downloads_that_wait_for_it(): void
    {
        $this->assertSame(404, (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/none'))->notFoundMeansNotYet(), $this->sink, 1024));
        $this->assertStringContainsString('HTTP 404', $this->fetchFailure(
            fn () => (new Fetcher)->fetch('sample', LoopbackDownload::url($this->http->url('/none')), $this->sink, 1024),
        ));
    }
}
