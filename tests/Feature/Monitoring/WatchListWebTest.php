<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Monitoring\StallCheck;
use Miraqceed\Foundation\Monitoring\WatchList;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * Web の要求（HTTP Kernel だけで立ち上がる）からも、schedule の watched() が見えること。
 *
 * アプリの schedule（routes/console.php）は、Console Kernel が命令を読むとき（bootstrap）にだけ読まれる。
 * testbench はテストの前に Console Kernel を立ち上げるので、そのままでは Web の要求の状態にならない。
 * そこで、まだ命令を読んでいない素の Console Kernel に置き換え、schedule のファイルをその Kernel の読む道に足す
 * （Laravel 11 以降のアプリの Kernel と同じ形。命令を探すのは素の Kernel のときだけ）。
 */
class WatchListWebTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEDULE_FILE = __DIR__.'/../../Support/console/watched-schedule.php';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log']);
        $app['config']->set('foundation.monitoring.external', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Web の要求の状態: Console Kernel はまだ命令（schedule のファイル）を読んでいない
        $this->app->singleton(ConsoleKernelContract::class, fn ($app) => new ConsoleKernel($app, $app['events']));
        $this->app->make(ConsoleKernelContract::class)->addCommandRoutePaths([self::SCHEDULE_FILE]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixtureEvents(): int
    {
        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'fixture:task'))
            ->count();
    }

    public function test_watch_list_sees_watched_schedule_before_the_console_kernel_loaded_it(): void
    {
        $this->assertSame(0, $this->fixtureEvents());

        $this->assertSame(['fixture-task' => ['max' => 60, 'label' => 'fixture-task']], $this->app->make(WatchList::class)->all());
    }

    public function test_reading_twice_does_not_load_the_schedule_twice(): void
    {
        $list = $this->app->make(WatchList::class);
        $list->all();
        $list->all();
        // Console Kernel を別に立ち上げても（命令を読むのは 1 回だけ）、2 重にならない
        $this->app->make(ConsoleKernelContract::class)->bootstrap();

        $this->assertSame(1, $this->fixtureEvents());
        $this->assertSame(['fixture-task'], array_keys($list->all()));
    }

    public function test_stall_check_reports_a_stalled_task_from_a_web_request(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        DB::table('foundation_task_runs')->insert([
            'name' => 'fixture-task',
            'first_seen_at' => '2026-11-15 07:00:00',
            'updated_at' => '2026-11-15 07:00:00',
        ]);

        $stalled = $this->app->make(StallCheck::class)->stalled();

        $this->assertCount(1, $stalled);
        $this->assertSame('fixture-task：一度も成功していません', $stalled[0]->message());
    }
}
