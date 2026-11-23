<?php

namespace Miraqceed\Foundation\Tests\Integration\DataFiles;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Miraqceed\Foundation\DataFiles\Refresher;
use Miraqceed\Foundation\DataFiles\RefreshStatus;
use Miraqceed\Foundation\DataFiles\Store;
use Miraqceed\Foundation\Tests\Support\DataFiles\LocalHttpServer;
use Miraqceed\Foundation\Tests\Support\DataFiles\LoopbackDownload;
use Miraqceed\Foundation\Tests\Support\DataFiles\TestSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\UsesDataFileDirectories;
use Miraqceed\Foundation\Tests\TestCase;
use Throwable;

/**
 * 本物の同時の実行（別々のプロセス。pcntl_fork）：重ねない・ロックを握ったまま殺されても次が取れる・
 * 古い版を後から入れない・読む側が途中のファイルを読まない。
 * DB を使わないので、SQLite・MySQL・PostgreSQL のすべての組で流す（飛ばすのは pcntl・posix のない環境だけ）。
 * 置き場所は storage の下（Docker の bind mount の上）。DATA_FILES_TEST_ROOT でコンテナの中のディレクトリにも向けられる
 */
class ParallelRefreshTest extends TestCase
{
    use UsesDataFileDirectories;

    private LocalHttpServer $http;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl と posix が要る');
        }
        $this->setUpDataFileDirectories();
        $this->http = $this->server(8);
    }

    protected function tearDown(): void
    {
        $this->tearDownDataFileDirectories();
        parent::tearDown();
    }

    private function source(string $path, ?Closure $verifier = null): TestSource
    {
        $source = new TestSource('sample');
        $source->downloads = [LoopbackDownload::url($this->http->url($path))];
        $source->verifier = $verifier;

        return $source;
    }

    /** 置き場所に版を入れる（親が、一時のファイル + rename で） */
    private function placeVersion(int $version): void
    {
        $store = Store::for('sample');
        $this->assertTrue($store->lock());
        $temporary = $store->temporaryPath();
        file_put_contents($temporary, TestSource::data($version));
        $store->install($temporary, 'sample');
        $store->unlock();
    }

    private function installedVersion(): int
    {
        return TestSource::versionOf((string) file_get_contents(Store::for('sample')->dataPath()));
    }

    public function test_two_runs_at_once_download_only_once(): void
    {
        for ($round = 1; $round <= 5; $round++) {
            $this->http->route('/slow.txt', ['body' => TestSource::data($round), 'delay' => 1.5]);
            $before = count($this->http->requests());
            $source = $this->source('/slow.txt');

            $lines = $this->fork(2, fn () => app(Refresher::class)->refresh($source)->status->value);

            sort($lines);
            $this->assertSame([RefreshStatus::Busy->value, RefreshStatus::Updated->value], $lines, "round {$round}");
            $this->assertCount($before + 1, $this->http->requests(), "round {$round}：取りに行ったのが1回でない");
            $this->assertSame($round, $this->installedVersion());
        }
    }

    /** 設計書 10.1 の2つ目：flock は、握ったプロセスが SIGKILL で死ねば放される */
    public function test_a_killed_run_releases_the_lock(): void
    {
        $this->http->route('/hang.txt', ['body' => TestSource::data(1), 'delay' => 20]);
        $source = $this->source('/hang.txt');

        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                app(Refresher::class)->refresh($source);
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        // 子がロックを握るまで待つ
        $held = false;
        for ($i = 0; $i < 100 && ! $held; $i++) {
            usleep(50_000);
            $probe = Store::for('sample');
            if ($probe->lock()) {
                $probe->unlock();
            } else {
                $held = true;
            }
        }
        $this->assertTrue($held, '子がロックを握らなかった');

        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);

        $this->http->route('/hang.txt', ['body' => TestSource::data(2)]);
        $result = app(Refresher::class)->refresh($source);
        $this->assertSame(RefreshStatus::Updated, $result->status, $result->message);
        $this->assertSame(2, $this->installedVersion());
    }

    /**
     * 古い版を後から入れない：A（版 7）がロックを握っている間に、B（版 6。入っている版を読むのに時間がかかる）が始まる。
     * 比べと rename がロックの中なので、B は何もしない。比べをロックの外に出す・ロックを取らない形では、B が版 6 で戻す
     */
    public function test_a_late_older_download_never_takes_back_a_newer_one(): void
    {
        $this->http->route('/v7.txt', ['body' => TestSource::data(7), 'delay' => 0.6]);
        $this->http->route('/v6.txt', ['body' => TestSource::data(6), 'delay' => 0.6]);
        $dataPath = Store::for('sample')->dataPath();
        $newer = $this->source('/v7.txt');
        $older = $this->source('/v6.txt', function (string $path) use ($dataPath): int {
            if ($path === $dataPath) {
                // 入っている版を読むのが遅い
                usleep(1_200_000);
            }

            return TestSource::versionOf((string) file_get_contents($path));
        });

        for ($round = 1; $round <= 10; $round++) {
            $this->placeVersion(5);

            $lines = $this->fork(2, function (int $process) use ($newer, $older): string {
                if ($process === 1) {
                    usleep(200_000);

                    return 'older '.app(Refresher::class)->refresh($older)->status->value;
                }

                return 'newer '.app(Refresher::class)->refresh($newer)->status->value;
            });

            sort($lines);
            $this->assertSame(['newer updated', 'older busy'], $lines, "round {$round}");
            $this->assertSame(7, $this->installedVersion(), "round {$round}");
        }
    }

    /**
     * 設計書 10.1 の1つ目：同じディレクトリの rename の最中・後に、読む側は途中のファイルを読まない。
     * 読む側から見て rename が原子的なのは、Linux のふつうのファイルシステム（ext4・xfs・overlayfs など）だけ。
     * Docker Desktop の bind mount（Windows・macOS のドライブを写したもの）では、入れ替えの最中に空のファイルを読むことがある
     * （確かめた：9p の bind mount で読みの約1%）。なので、この試しはコンテナの中のふつうのファイルシステム（sys_get_temp_dir()）に置き場所を置く。
     * 判定は緩めない（悪い読みが1回でもあれば落ちる）
     */
    public function test_readers_never_see_a_partial_file(): void
    {
        File::deleteDirectory($this->dataRoot);
        $this->dataRoot = sys_get_temp_dir().'/data-files-readers-'.bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0777, true);
        config(['foundation.data_files.path' => $this->dataRoot.'/files', 'foundation.data_files.cache_path' => $this->dataRoot.'/cache']);
        $this->assertNotOnSharedDrive($this->dataRoot);

        $this->placeVersion(0);
        $dataPath = Store::for('sample')->dataPath();
        $stop = $this->dataRoot.'/stop';
        $log = $this->dataRoot.'/reader.log';

        $pid = pcntl_fork();
        if ($pid === 0) {
            $reads = 0;
            $bad = 0;
            $versions = [];
            while (! file_exists($stop)) {
                $text = (string) @file_get_contents($dataPath);
                try {
                    $version = TestSource::versionOf($text);
                    // 版ごとの大きさと合う（別の版の中身が混ざらない）
                    if ($text !== TestSource::data($version, self::lines($version))) {
                        $bad++;
                    }
                    $versions[$version] = true;
                } catch (Throwable) {
                    $bad++;
                }
                $reads++;
            }
            file_put_contents($log, json_encode(['reads' => $reads, 'bad' => $bad, 'versions' => count($versions)]));
            posix_kill(posix_getpid(), SIGKILL);
        }

        // 版 0 は placeVersion の形（20行）。そろえる
        $this->placeVersion(0);
        // 大きさを行き来させる（減りの止めは --force で通す）
        for ($version = 1; $version <= 50; $version++) {
            $this->http->route('/sample.txt', ['body' => TestSource::data($version, self::lines($version))]);
            $result = app(Refresher::class)->refresh($this->source('/sample.txt'), force: true);
            $this->assertSame(RefreshStatus::Updated, $result->status, $result->message);
        }
        touch($stop);
        pcntl_waitpid($pid, $status);

        $report = json_decode((string) file_get_contents($log), true);
        $this->assertSame(0, $report['bad'], json_encode($report));
        $this->assertGreaterThan(50, $report['reads'], json_encode($report));
        $this->assertGreaterThan(10, $report['versions'], json_encode($report));
    }

    /** 置き場所が、ホストのドライブを写した所（9p・fuse・ネットワーク）でないこと。そうなら飛ばさずに落とす */
    private function assertNotOnSharedDrive(string $path): void
    {
        $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [];
        $best = null;
        foreach ($mounts as $line) {
            [, $point, $type] = array_pad(explode(' ', $line), 3, '');
            if (str_starts_with(rtrim($path, '/').'/', rtrim($point, '/').'/') && ($best === null || strlen($point) > strlen($best[0]))) {
                $best = [$point, $type];
            }
        }
        if ($best !== null) {
            $this->assertNotContains($best[1], ['9p', 'fuse', 'fuse.grpcfuse', 'virtiofs', 'nfs', 'nfs4', 'cifs', 'smb3'], "置き場所 {$path} が {$best[1]} の上にある（rename が読む側に原子的でない）");
        }
    }

    /** 版ごとに大きさの違う中身（版 0 は placeVersion の20行） */
    private static function lines(int $version): int
    {
        return $version === 0 ? 20 : 200 + ($version % 7) * 300;
    }

    /**
     * 別々のプロセスを同じ時刻に始めさせ、それぞれの結果を1行で返させる（例外は "e 種類: 文"）
     *
     * @param  Closure(int): string  $work
     * @return list<string>
     */
    private function fork(int $processes, Closure $work, float $startAfter = 0.5): array
    {
        $log = tempnam(sys_get_temp_dir(), 'df-race');
        if (DB::getDriverName() !== 'sqlite') {
            // 子は親の接続を受け継がず、使うならつなぎ直す
            DB::disconnect();
        }
        $start = microtime(true) + $startAfter;
        $pids = [];

        for ($i = 0; $i < $processes; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork できなかった');
            }
            if ($pid === 0) {
                while (microtime(true) < $start) {
                    usleep(200);
                }
                try {
                    $line = $work($i);
                } catch (Throwable $e) {
                    $line = 'e '.get_class($e).': '.str_replace("\n", ' ', mb_substr($e->getMessage(), 0, 160));
                }
                file_put_contents($log, $line."\n", FILE_APPEND | LOCK_EX);
                // 子の後始末（PHPUnit・接続の片付け）を走らせずに終える
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        @unlink($log);

        return $lines;
    }
}
