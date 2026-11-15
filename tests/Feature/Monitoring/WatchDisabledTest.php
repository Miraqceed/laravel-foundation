<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Tests\TestCase;

class WatchDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
    }

    public function test_disabled_macro_does_not_fail(): void
    {
        $ran = false;
        $schedule = $this->app->make(Schedule::class);
        $schedule->call(function () use (&$ran) {
            $ran = true;
        })->watched('job-a', 60);

        $this->assertSame(0, Artisan::call('schedule:run'));
        $this->assertTrue($ran);
        // 永続の DB（CI の pgsql・mysql）には前の試しの表が残っていることがあるので、表の有無でなく中身で見る
        $this->assertSame(0, Schema::hasTable('foundation_task_runs') ? DB::table('foundation_task_runs')->count() : 0);
    }

    public function test_commands_do_nothing_when_disabled(): void
    {
        $this->assertSame(0, Artisan::call('foundation:record', ['name' => 'x']));
        $this->assertSame(0, Artisan::call('foundation:watch'));
    }

    public function test_watch_is_not_scheduled_when_disabled(): void
    {
        $found = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'foundation:watch'));

        $this->assertCount(0, $found);
    }
}
