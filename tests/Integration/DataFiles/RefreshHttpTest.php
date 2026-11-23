<?php

namespace Miraqceed\Foundation\Tests\Integration\DataFiles;

use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\DataFiles\Limits;
use Miraqceed\Foundation\Testing\ReportFailure;
use Miraqceed\Foundation\Tests\Support\DataFiles\LocalHttpServer;
use Miraqceed\Foundation\Tests\Support\DataFiles\LoopbackDownload;
use Miraqceed\Foundation\Tests\Support\DataFiles\SecondTestSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\TestSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\UsesDataFileDirectories;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * foundation:data-files を、手元の HTTP の口（php -S）から一通り流す（本物の HTTP の経路）。
 * どの失敗でも、置き場所は前のまま・一時のファイルが残らない・知らせが1回・終わりの値 1
 */
class RefreshHttpTest extends TestCase
{
    use UsesDataFileDirectories;

    private LocalHttpServer $http;

    private TestSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDataFileDirectories();
        $this->http = $this->server();
        $this->source = new TestSource('sample');
        $this->source->downloads = [LoopbackDownload::url($this->http->url('/sample.txt'))];
        $this->useSources($this->source);
    }

    protected function tearDown(): void
    {
        $this->tearDownDataFileDirectories();
        parent::tearDown();
    }

    /** 置き場所に版 $version を入れておく（前の版） */
    private function install(int $version, int $lines = 20): void
    {
        $this->http->route('/sample.txt', ['body' => TestSource::data($version, $lines)]);
        $this->assertSame(0, Artisan::call('foundation:data-files'), Artisan::output());
        $this->assertSame(TestSource::data($version, $lines), file_get_contents($this->store()->dataPath()));
    }

    private function leftovers(): array
    {
        return glob($this->store()->directory().'/.tmp-*') ?: [];
    }

    /** @return \ArrayObject<int, string> 記録しようとした例外の文 */
    private function reports(): \ArrayObject
    {
        return ReportFailure::simulate();
    }

    public function test_installs_a_newer_version(): void
    {
        $this->install(5);
        $this->http->route('/sample.txt', ['body' => TestSource::data(6)]);

        $this->assertSame(0, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString('新しい版（6）を入れました', Artisan::output());
        $this->assertSame(TestSource::data(6), file_get_contents($this->store()->dataPath()));
        $this->assertSame([], $this->leftovers());
        $this->assertNotNull($this->store()->checkedAt());
        // パッケージの名前だけを名乗る
        $this->assertSame('miraqceed/laravel-foundation', $this->http->requests()[0]['ua']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function brokenAnswers(): array
    {
        return [
            '500' => [['status' => 500, 'body' => 'oops'], 'HTTP 500'],
            '403' => [['status' => 403, 'body' => 'no'], 'HTTP 403'],
            '404（まだ出ていないと見ない取り方）' => [['status' => 404, 'body' => 'no'], 'HTTP 404'],
            '200 の誤りのページ' => [['body' => "<!doctype html><html><body>Service Unavailable</body></html>\n"], '確かめで断りました'],
            '空の本文' => [['body' => ''], '小さすぎます'],
            '終わりの行のない切れた中身' => [['body' => substr(TestSource::data(6), 0, -4)], '確かめで断りました'],
            '名乗った長さより短い本文（つなぎが切れる）' => [['body' => TestSource::data(6), 'truncate' => 40], 'を取れません'],
            'リダイレクト（既定で従わない）' => [['status' => 302, 'headers' => ['Location' => '/elsewhere.txt'], 'body' => ''], 'リダイレクトには従いません'],
        ];
    }

    /** @param  array<string, mixed>  $answer */
    #[DataProvider('brokenAnswers')]
    public function test_never_replaces_with_a_broken_answer(array $answer, string $reason): void
    {
        $this->install(5);
        $this->http->route('/elsewhere.txt', ['body' => TestSource::data(9)]);
        $this->http->route('/sample.txt', $answer);
        $reports = $this->reports();

        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString($reason, Artisan::output());
        $this->assertSame(TestSource::data(5), file_get_contents($this->store()->dataPath()));
        $this->assertSame([], $this->leftovers());
        $this->assertCount(1, $reports);
        $this->assertStringContainsString($reason, $reports[0]);
    }

    public function test_never_replaces_with_a_cut_or_broken_gzip(): void
    {
        $this->install(5);
        $this->source->downloads = [LoopbackDownload::url($this->http->url('/sample.gz'))->gzip()];

        foreach ([
            '切れた gzip' => [substr(gzencode(TestSource::data(6)), 0, -12), 'gzip が途中で切れています'],
            'gzip でない本文' => [TestSource::data(6), 'gzip として解けません'],
            'gzip の後ろにごみ' => [gzencode(TestSource::data(6)).'garbage', 'gzip として解けません'],
        ] as $label => [$body, $reason]) {
            $this->http->route('/sample.gz', ['body' => $body]);
            $this->assertSame(1, Artisan::call('foundation:data-files'), $label);
            $this->assertStringContainsString($reason, Artisan::output(), $label);
            $this->assertSame(TestSource::data(5), file_get_contents($this->store()->dataPath()), $label);
            $this->assertSame([], $this->leftovers(), $label);
        }

        // 正しい gzip（つないだ2つの member も）なら入る
        $data = TestSource::data(7);
        $this->http->route('/sample.gz', ['body' => gzencode(substr($data, 0, 50)).gzencode(substr($data, 50))]);
        $this->assertSame(0, Artisan::call('foundation:data-files'), Artisan::output());
        $this->assertSame($data, file_get_contents($this->store()->dataPath()));
    }

    public function test_stops_a_gzip_that_expands_beyond_the_limit_while_decompressing(): void
    {
        $this->install(5);
        $this->source->limits = new Limits(maxDownloadBytes: 1024 * 1024, maxBytes: 64 * 1024, minBytes: 16, maxShrink: null, staleAfterDays: 7);
        $this->source->downloads = [LoopbackDownload::url($this->http->url('/bomb.gz'))->gzip()];
        // 小さな gzip が、上限の少し上まで広がる
        $this->http->route('/bomb.gz', ['body' => gzencode("VERSION: 6\n".str_repeat("x\n", 40 * 1024)."END\n", 9)]);

        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString('解いた大きさが上限 65536 バイトを超えました', Artisan::output());
        $this->assertSame(TestSource::data(5), file_get_contents($this->store()->dataPath()));
        $this->assertSame([], $this->leftovers());
    }

    public function test_size_limits_of_what_it_downloads(): void
    {
        $this->install(5);
        $this->source->limits = new Limits(maxDownloadBytes: 200 * 1024, maxBytes: 1024 * 1024, minBytes: 16, maxShrink: null, staleAfterDays: 7);
        $big = "VERSION: 6\n".str_repeat("y\n", 300 * 1024)."END\n";

        foreach ([
            '名乗る大きさが上限を超える' => ['body' => $big],
            '名乗らない大きな本文' => ['body' => $big, 'undeclared' => true],
        ] as $label => $answer) {
            $this->http->route('/sample.txt', $answer);
            $this->assertSame(1, Artisan::call('foundation:data-files'), $label);
            $output = Artisan::output();
            $this->assertStringContainsString('大きすぎます', $output, $label);
            $this->assertSame(TestSource::data(5), file_get_contents($this->store()->dataPath()), $label);
            $this->assertSame([], $this->leftovers(), $label);
        }
    }

    public function test_reads_only_the_declared_length_of_a_body_that_declares_less(): void
    {
        $this->install(5);
        $this->source->limits = new Limits(maxDownloadBytes: 200 * 1024, maxBytes: 1024 * 1024, minBytes: 16, maxShrink: null, staleAfterDays: 7);
        // 偽って小さく名乗る本文：名乗った長さだけを受け、残りは読まない（確かめで断る）
        $this->http->route('/sample.txt', ['body' => "VERSION: 6\n".str_repeat("y\n", 300 * 1024)."END\n", 'declare' => 100]);

        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString('確かめで断りました', Artisan::output());
        $this->assertSame(TestSource::data(5), file_get_contents($this->store()->dataPath()));
        $this->assertSame([], $this->leftovers());
    }

    public function test_stops_a_download_that_runs_past_the_whole_time_limit(): void
    {
        $this->install(5);
        $this->useTimeout(2);
        // 少しずつ流し、2秒を超える
        $this->http->route('/sample.txt', ['body' => TestSource::data(6, 200), 'trickle' => [64, 50_000]]);

        $started = microtime(true);
        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $elapsed = microtime(true) - $started;

        // 時間の上限で止まった：進みの知らせで止めた形か、cURL の時間の上限で止まった形
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/sample：http:\/\/127\.0\.0\.1:\d+\/sample\.txt (を取り終えられません（全体 2 秒を超えた）|を取れません（.*Exception）)/u', $output);
        $this->assertGreaterThan(1.5, $elapsed, "打ち切りが早すぎる（{$elapsed} 秒）");
        $this->assertLessThan(6.0, $elapsed, "打ち切りに {$elapsed} 秒かかった");
        $this->assertSame(TestSource::data(5), file_get_contents($this->store()->dataPath()));
    }

    public function test_keeps_what_is_installed_when_the_download_is_not_newer(): void
    {
        $this->install(5);
        $before = stat($this->store()->dataPath());

        foreach ([5, 4] as $version) {
            $this->http->route('/sample.txt', ['body' => TestSource::data($version, 30)]);
            // --force でも、同じ版・古い版は入れない
            $this->assertSame(0, Artisan::call('foundation:data-files', ['--force' => true]));
            $this->assertStringContainsString('より新しくありません', Artisan::output());
        }

        clearstatcache();
        $after = stat($this->store()->dataPath());
        $this->assertSame([$before['ino'], $before['mtime'], $before['size']], [$after['ino'], $after['mtime'], $after['size']]);
        $this->assertSame(TestSource::data(5), file_get_contents($this->store()->dataPath()));
    }

    public function test_stops_a_large_shrink_unless_forced(): void
    {
        $this->install(5, 40);
        $this->http->route('/sample.txt', ['body' => TestSource::data(6, 5)]);

        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString('50% を超えて小さくなりました', Artisan::output());
        $this->assertSame(TestSource::data(5, 40), file_get_contents($this->store()->dataPath()));

        $this->assertSame(0, Artisan::call('foundation:data-files', ['--force' => true]));
        $this->assertSame(TestSource::data(6, 5), file_get_contents($this->store()->dataPath()));
    }

    public function test_tries_the_next_download_while_this_one_is_not_out_yet(): void
    {
        $this->source->downloads = [
            LoopbackDownload::url($this->http->url('/2026-11.txt'))->notFoundMeansNotYet(),
            LoopbackDownload::url($this->http->url('/2026-10.txt'))->notFoundMeansNotYet(),
        ];

        // どれもまだで、入っているものもない：失敗
        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString('まだ出ていません（入っているものもありません）', Artisan::output());

        // 先月の分はある
        $this->http->route('/2026-10.txt', ['body' => TestSource::data(10)]);
        $this->assertSame(0, Artisan::call('foundation:data-files'));
        $this->assertSame(TestSource::data(10), file_get_contents($this->store()->dataPath()));
        $this->assertSame(['/2026-11.txt', '/2026-10.txt'], array_slice(array_column($this->http->requests(), 'p'), -2));

        // どれもまだ（先月の分も消えた）でも、入っているものがあれば成功
        $this->http->route('/2026-10.txt', ['status' => 404]);
        touch($this->store()->directory().'/.checked', time() - 100);
        $this->assertSame(0, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString('まだ出ていません', Artisan::output());
        $this->assertGreaterThan(time() - 50, $this->store()->checkedAt());
    }

    public function test_one_failing_source_does_not_stop_the_others(): void
    {
        $second = new SecondTestSource('second');
        $second->downloads = [LoopbackDownload::url($this->http->url('/second.txt'))];
        $this->useSources($this->source, $second);
        $this->http->route('/sample.txt', ['status' => 500]);
        $this->http->route('/second.txt', ['body' => TestSource::data(3)]);

        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $this->assertSame(TestSource::data(3), file_get_contents($this->store('second')->dataPath()));
        $this->assertFalse($this->store()->hasData());

        // 1つだけ
        $this->http->route('/sample.txt', ['body' => TestSource::data(1)]);
        $this->assertSame(0, Artisan::call('foundation:data-files', ['name' => 'sample']));
        $this->assertTrue($this->store()->hasData());
    }

    public function test_the_checked_time_moves_only_on_success(): void
    {
        $this->install(5);
        touch($this->store()->directory().'/.checked', time() - 1000);

        $this->http->route('/sample.txt', ['status' => 500]);
        Artisan::call('foundation:data-files');
        $this->assertLessThan(time() - 900, $this->store()->checkedAt());

        $this->http->route('/sample.txt', ['body' => TestSource::data(5)]);
        Artisan::call('foundation:data-files');
        $this->assertGreaterThan(time() - 50, $this->store()->checkedAt());
    }

    public function test_status_never_downloads(): void
    {
        $this->install(5);
        $count = count($this->http->requests());

        $this->assertSame(0, Artisan::call('foundation:data-files', ['--status' => true]));
        $this->assertStringContainsString('sample', Artisan::output());
        $this->assertCount($count, $this->http->requests());
    }

    public function test_data_without_versions_compares_contents(): void
    {
        $this->source->verifier = function (string $path): ?int {
            TestSource::versionOf((string) file_get_contents($path));

            return null;
        };
        $this->install(5);
        $before = stat($this->store()->dataPath());

        $this->http->route('/sample.txt', ['body' => TestSource::data(5)]);
        $this->assertSame(0, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString('より新しくありません', Artisan::output());
        clearstatcache();
        $this->assertSame($before['ino'], stat($this->store()->dataPath())['ino']);

        $this->http->route('/sample.txt', ['body' => TestSource::data(4)]);
        $this->assertSame(0, Artisan::call('foundation:data-files'));
        $this->assertSame(TestSource::data(4), file_get_contents($this->store()->dataPath()));
    }

    public function test_keeps_its_exit_code_when_reporting_throws(): void
    {
        $this->install(5);
        $this->http->route('/sample.txt', ['status' => 500]);
        $attempts = ReportFailure::simulate();

        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $this->assertCount(1, $attempts);
    }

    public function test_never_shows_the_query_or_the_header_values(): void
    {
        $this->install(5);
        $this->source->downloads = [
            LoopbackDownload::url($this->http->url('/sample.txt?key=x-secret-x'))->withHeaders(['Authorization' => 'Bearer x-secret-x']),
        ];
        $this->http->route('/sample.txt', ['status' => 500]);
        $reports = $this->reports();

        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $output = Artisan::output();

        $this->assertStringNotContainsString('x-secret-x', $output);
        $this->assertStringContainsString('/sample.txt', $output);
        $this->assertCount(1, $reports);
        $this->assertStringNotContainsString('x-secret-x', $reports[0]);
        // 見出しは届いている
        $requests = $this->http->requests();
        $this->assertSame('Bearer x-secret-x', end($requests)['h']['Authorization'] ?? null);
    }
}
