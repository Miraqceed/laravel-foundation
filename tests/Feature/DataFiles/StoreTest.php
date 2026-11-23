<?php

namespace Miraqceed\Foundation\Tests\Feature\DataFiles;

use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\DataFiles\DataFileException;
use Miraqceed\Foundation\DataFiles\Refresher;
use Miraqceed\Foundation\DataFiles\Store;
use Miraqceed\Foundation\Testing\ReportFailure;
use Miraqceed\Foundation\Tests\Support\DataFiles\LoopbackDownload;
use Miraqceed\Foundation\Tests\Support\DataFiles\TestSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\UsesDataFileDirectories;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 置き場所とロック：一時のファイルは置き場所と同じディレクトリにだけ作る、残りの片付け、権限、ロックが取れないとき
 */
class StoreTest extends TestCase
{
    use UsesDataFileDirectories;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDataFileDirectories();
    }

    protected function tearDown(): void
    {
        $this->tearDownDataFileDirectories();
        parent::tearDown();
    }

    /** @return list<string> sys_get_temp_dir() の中の名前（記録の間引きの目印の所を除く） */
    private function systemTemporaryNames(): array
    {
        $throttle = basename((string) config('foundation.failures.throttle_path'));

        return array_values(array_filter(scandir(sys_get_temp_dir()) ?: [], fn (string $name) => $name !== $throttle && ! str_starts_with($name, 'foundation-data-files-http-')));
    }

    /**
     * 一時のファイルは置き場所と同じディレクトリにだけ作る（別のファイルシステムでは rename が写しと消しになる）。
     * 一時のファイルは rename で移るか消えるので、終わった後のディレクトリを比べても分からない。
     * 受けている最中のディレクトリ（取ったままの本文）と、verify() に渡された道（解いたもの）を見る
     */
    public function test_creates_temporary_files_only_beside_the_data(): void
    {
        $http = $this->server();
        $source = new TestSource;
        $source->downloads = [LoopbackDownload::url($http->url('/sample.gz'))->gzip()];
        $this->useSources($source);
        $directory = $this->store()->directory();
        $compressed = gzencode(TestSource::data(3, 400));
        $http->route('/sample.gz', ['body' => $compressed, 'probe' => [$directory, intdiv(strlen($compressed), 2)]]);
        $before = $this->systemTemporaryNames();

        $this->assertSame(0, Artisan::call('foundation:data-files'), Artisan::output());

        // 受けている最中：取ったままの本文は、置き場所のディレクトリの一時のファイル
        $probes = $http->probes();
        $this->assertCount(1, $probes);
        $this->assertCount(1, array_filter($probes[0], fn (string $name) => str_starts_with($name, Store::TEMPORARY_PREFIX)), json_encode($probes[0]));
        // 解いたもの：verify() に渡された道は、置き場所か、置き場所と同じディレクトリの一時のファイル
        $this->assertNotSame([], $source->verified);
        foreach ($source->verified as $path) {
            $this->assertSame($directory, dirname($path), $path);
            $this->assertTrue($path === $this->store()->dataPath() || str_starts_with(basename($path), Store::TEMPORARY_PREFIX), $path);
        }
        $this->assertContains(true, array_map(fn (string $path) => $path !== $this->store()->dataPath(), $source->verified));

        $this->assertSame($before, $this->systemTemporaryNames());
        $this->assertSame(TestSource::data(3, 400), file_get_contents($this->store()->dataPath()));
        $this->assertSame([], glob($directory.'/.tmp-*'));
    }

    public function test_temporary_paths_are_in_the_data_directory(): void
    {
        $store = $this->store();

        $this->assertSame($store->directory(), dirname($store->temporaryPath()));
        $this->assertSame($store->directory().'/data', $store->dataPath());
        $this->assertSame($this->dataRoot.'/files/sample', $store->directory());
    }

    public function test_removes_only_old_leftovers(): void
    {
        $store = $this->store();
        $store->lock();
        $old = $store->directory().'/.tmp-old';
        $fresh = $store->directory().'/.tmp-fresh';
        $other = $store->directory().'/notes.txt';
        foreach ([$old, $fresh, $other] as $file) {
            file_put_contents($file, 'x');
        }
        touch($old, time() - Store::LEFTOVER_SECONDS - 5);
        touch($other, time() - Store::LEFTOVER_SECONDS - 5);

        $store->removeLeftovers();
        $store->unlock();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertFileExists($other);
    }

    public function test_keeps_the_mode_of_the_installed_file(): void
    {
        $store = $this->store();
        $store->lock();
        $first = $store->temporaryPath();
        file_put_contents($first, 'one');
        $store->install($first, 'sample');
        $this->assertSame(0644 & ~umask(), fileperms($store->dataPath()) & 0777);

        chmod($store->dataPath(), 0640);
        $second = $store->temporaryPath();
        file_put_contents($second, 'two');
        $store->install($second, 'sample');
        $store->unlock();

        clearstatcache();
        $this->assertSame(0640, fileperms($store->dataPath()) & 0777);
        $this->assertSame('two', file_get_contents($store->dataPath()));
    }

    public function test_says_another_run_is_going_when_the_lock_is_held(): void
    {
        $source = new TestSource;
        $this->useSources($source);
        $held = Store::for('sample');
        $this->assertTrue($held->lock());

        $this->assertSame(0, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString('ほかの実行が動いています', Artisan::output());
        $held->unlock();

        // 放せば取れる
        $this->assertTrue(Store::for('sample')->lock());
    }

    public function test_keeps_going_when_fsync_cannot_be_used(): void
    {
        // fsync が偽を返す場面は作りにくいので、記録して続ける道だけを、開けない一時のファイルで確かめる
        $store = $this->store();
        $store->lock();
        $reports = ReportFailure::simulate();
        try {
            $store->install($store->directory().'/.tmp-missing', 'sample');
            $this->fail('無いファイルを入れた');
        } catch (DataFileException $e) {
            $this->assertStringContainsString('置き場所を入れ替えられません', $e->getMessage());
        }
        $store->unlock();
        $this->assertCount(1, $reports);
        $this->assertStringContainsString('fsync できませんでした', $reports[0]);
    }

    /** 設定の書き違いも知らせる（schedule の出力は捨てられることが多い）。文に設定の値を入れない */
    public function test_reports_a_broken_source_list(): void
    {
        $reports = ReportFailure::simulate();
        foreach ([['Not\\A\\Class'], [TestSource::class, TestSource::class]] as $i => $sources) {
            $this->useSources(new TestSource);
            config(['foundation.data_files.sources' => $sources]);

            $this->assertSame(1, Artisan::call('foundation:data-files'));
            $this->assertCount($i + 1, $reports);
            $this->assertStringContainsString('データのファイルの設定を読めません：foundation.data_files.sources', $reports[$i]);
            $this->assertStringNotContainsString('Not\\A\\Class', $reports[$i]);
        }
    }

    public function test_the_refresher_comes_from_the_container(): void
    {
        $this->assertInstanceOf(Refresher::class, app(Refresher::class));
    }
}
