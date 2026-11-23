<?php

namespace Miraqceed\Foundation\Tests\Feature\DataFiles;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\DataFiles\DataFiles;
use Miraqceed\Foundation\DeployCheck\Checks\DataFiles as DataFilesCheck;
use Miraqceed\Foundation\DeployCheck\Runner;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\Support\DataFiles\ThrowingSource;
use Miraqceed\Foundation\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * 入れただけでは変わらない：種類を並べなければ、命令は何も書かず、storage に何も作らず、schedule に何も足さず、点検は skip。
 * 種類を並べても、プロバイダーの起動では種類のクラスを作らない
 */
class DataFilesDisabledTest extends TestCase
{
    private function defaultDirectories(): array
    {
        return [storage_path('app/foundation/data-files'), storage_path('framework/foundation/data-files-cache')];
    }

    public function test_the_defaults_do_nothing(): void
    {
        $this->assertSame([], config('foundation.data_files.sources'));
        foreach ($this->defaultDirectories() as $directory) {
            $this->assertDirectoryDoesNotExist($directory, '試しの前から既定の置き場所がある');
        }

        $this->assertSame(0, Artisan::call('foundation:data-files'));
        $this->assertStringContainsString('データのファイルの種類がありません', Artisan::output());
        $this->assertSame(0, Artisan::call('foundation:data-files', ['--status' => true]));

        foreach ($this->defaultDirectories() as $directory) {
            $this->assertDirectoryDoesNotExist($directory);
        }
    }

    public function test_adds_nothing_to_the_schedule(): void
    {
        $commands = array_map(fn ($event) => (string) $event->command, app(Schedule::class)->events());

        $this->assertSame([], array_values(array_filter($commands, fn (string $command) => str_contains($command, 'data-files'))));
    }

    public function test_the_deploy_check_skips_without_sources(): void
    {
        $this->assertContains(DataFilesCheck::class, Runner::DEFAULTS);
        $this->assertSame('data_files', (new DataFilesCheck)->id());
        $this->assertSame(Status::Skip, (new DataFilesCheck)->run()->status);
    }

    /** @param  Application  $app */
    protected function usesThrowingSource($app): void
    {
        $app['config']->set('foundation.data_files.sources', [ThrowingSource::class]);
    }

    #[DefineEnvironment('usesThrowingSource')]
    public function test_the_provider_never_makes_the_sources_while_booting(): void
    {
        // 起動できた（作ると投げる種類を並べても）。読む側の助けを取り出しても作らない
        $this->assertInstanceOf(DataFiles::class, app(DataFiles::class));
        $this->assertSame(0, ThrowingSource::$made);

        // 命令を流したときに初めて作る
        $made = ThrowingSource::$made;
        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $this->assertSame($made + 1, ThrowingSource::$made);
    }
}
