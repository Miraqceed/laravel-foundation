<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\DataFiles\Limits;
use Miraqceed\Foundation\DeployCheck\Checks\DataFiles;
use Miraqceed\Foundation\DeployCheck\Runner;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\Support\DataFiles\SecondTestSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\TestSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\UsesDataFileDirectories;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * data_files：種類がなければ skip、置き場所になければ FAIL（写しなら later）、最後に成功した更新から staleAfterDays を過ぎれば later
 */
class DataFilesCheckTest extends TestCase
{
    use UsesDataFileDirectories;

    private TestSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDataFileDirectories();
        $this->source = new TestSource;
    }

    protected function tearDown(): void
    {
        $this->tearDownDataFileDirectories();
        parent::tearDown();
    }

    private function place(string $name = 'sample', ?int $checkedAt = null): void
    {
        $store = $this->store($name);
        $store->lock();
        $temporary = $store->temporaryPath();
        file_put_contents($temporary, TestSource::data(1));
        $store->install($temporary, $name);
        $store->unlock();
        if ($checkedAt !== null) {
            touch($store->directory().'/.checked', $checkedAt);
        }
    }

    public function test_skips_without_sources(): void
    {
        $this->assertSame(Status::Skip, (new DataFiles)->run()->status);
    }

    public function test_fails_when_the_data_is_missing_and_waits_when_it_reads_the_fallback(): void
    {
        $this->useSources($this->source);
        $missing = (new DataFiles)->run();
        $this->assertSame(Status::Fail, $missing->status);
        $this->assertStringContainsString('sample：置き場所にない', (string) $missing->detail);

        $fallback = $this->dataRoot.'/bundled.txt';
        file_put_contents($fallback, TestSource::data(1));
        $this->source->fallback = $fallback;
        $this->assertSame(Status::Later, (new DataFiles)->run()->status);
    }

    public function test_later_when_the_last_successful_refresh_is_too_old(): void
    {
        $this->useSources($this->source);
        $this->place(checkedAt: time() - 6 * 86400);
        $this->assertSame(Status::Ok, (new DataFiles)->run()->status);

        touch($this->store()->directory().'/.checked', time() - 8 * 86400);
        $later = (new DataFiles)->run();
        $this->assertSame(Status::Later, $later->status);
        $this->assertStringContainsString('最後に成功した更新から7日を過ぎた', (string) $later->detail);

        // 一度も成功していない（置いただけ）も later
        @unlink($this->store()->directory().'/.checked');
        $this->assertStringContainsString('成功したことがない', (string) (new DataFiles)->run()->detail);

        // 古さを見ない種類
        $this->source->limits = new Limits(maxDownloadBytes: 1024, maxBytes: 1024, minBytes: 1, maxShrink: null, staleAfterDays: null);
        $this->assertSame(Status::Ok, (new DataFiles)->run()->status);
    }

    public function test_the_worst_source_decides(): void
    {
        $second = new SecondTestSource('second');
        $this->useSources($this->source, $second);
        $this->place('sample', time());

        $result = (new DataFiles)->run();
        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString('second：置き場所にない', (string) $result->detail);
        $this->assertStringNotContainsString('sample：', (string) $result->detail);
    }

    public function test_a_broken_source_list_fails_without_throwing(): void
    {
        config(['foundation.data_files.sources' => ['Not\\A\\Class']]);

        $result = (new DataFiles)->run();
        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringNotContainsString('Not\\A\\Class', (string) $result->detail);
    }

    public function test_later_fails_only_with_strict(): void
    {
        $this->useSources($this->source);
        $this->place(checkedAt: time() - 30 * 86400);
        $others = array_values(array_filter(
            array_map(fn (string $check) => (new $check)->id(), Runner::DEFAULTS),
            fn (string $id) => $id !== 'data_files',
        ));
        config(['foundation.deploy_check.skip' => $others]);

        $this->assertSame(0, Artisan::call('foundation:deploy-check'));
        $this->assertMatchesRegularExpression('/^\[later\]\s+data_files\s+/m', Artisan::output());
        $this->assertSame(1, Artisan::call('foundation:deploy-check', ['--strict' => true]));
    }
}
