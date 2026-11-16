<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Monitoring\StallCheck;
use Miraqceed\Foundation\Monitoring\StalledTask;
use Miraqceed\Foundation\Tests\TestCase;

/** 止まっている処理を読むだけの口。判断は foundation:watch と同じで、何も書かない */
class StallCheckTest extends TestCase
{
    use RefreshDatabase;

    private bool $enabled = true;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('foundation.monitoring.enabled', $this->enabled);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log']);
        $app['config']->set('foundation.monitoring.external', []);
        $app['config']->set('foundation.monitoring.heartbeat_url', null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function check(): StallCheck
    {
        return $this->app->make(StallCheck::class);
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(fn (StalledTask $task) => $task->name, $this->check()->stalled());
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return DB::table('foundation_task_runs')->orderBy('name')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function test_task_not_yet_seen_by_the_watch_is_not_stalled_and_nothing_is_written(): void
    {
        $this->app->make(Schedule::class)->call(fn () => null)->watched('job-new', 60);

        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->assertSame([], $this->names());

        // 何日たっても、見張りが一度も見ていなければ止まっていない扱い（見張りの初めの1回と同じ）
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->assertSame([], $this->names());
        $this->assertSame([], $this->rows());
    }

    public function test_never_succeeded_task_is_stalled_only_after_max_age_since_first_seen(): void
    {
        $this->app->make(Schedule::class)->call(fn () => null)->watched('job-new', 60);

        Carbon::setTestNow('2026-11-15 10:00:00');
        Artisan::call('foundation:watch');

        Carbon::setTestNow('2026-11-15 10:59:00');
        $this->assertSame([], $this->names());

        Carbon::setTestNow('2026-11-15 11:01:00');
        $stalled = $this->check()->stalled();
        $this->assertCount(1, $stalled);
        $this->assertSame('job-new', $stalled[0]->name);
        $this->assertSame('job-new', $stalled[0]->label);
        $this->assertSame(60, $stalled[0]->maxAgeMinutes);
        $this->assertNull($stalled[0]->lastSucceededAt);
        $this->assertSame('job-new：一度も成功していません', $stalled[0]->message());
    }

    public function test_task_whose_last_success_is_older_than_max_age_is_stalled(): void
    {
        config()->set('foundation.monitoring.external', ['backup' => ['max_age_minutes' => 60, 'label' => '日次のバックアップ']]);

        Carbon::setTestNow('2026-11-15 09:00:00');
        Artisan::call('foundation:record', ['name' => 'backup']);

        Carbon::setTestNow('2026-11-15 09:59:00');
        $this->assertSame([], $this->names());

        Carbon::setTestNow('2026-11-15 10:01:00');
        $stalled = $this->check()->stalled();
        $this->assertCount(1, $stalled);
        $this->assertSame('backup', $stalled[0]->name);
        $this->assertSame('日次のバックアップ', $stalled[0]->label);
        $this->assertSame('2026-11-15 09:00:00', $stalled[0]->lastSucceededAt?->format('Y-m-d H:i:s'));
        $this->assertSame(
            '日次のバックアップ：最後の成功が60分より前です（'.Carbon::parse('2026-11-15 09:00:00')->toIso8601String().'）',
            $stalled[0]->message(),
        );
    }

    public function test_failed_runs_before_the_first_watch_do_not_make_a_task_stalled(): void
    {
        config()->set('foundation.monitoring.external', ['backup' => ['max_age_minutes' => 60]]);

        Carbon::setTestNow('2026-11-15 09:00:00');
        Artisan::call('foundation:record', ['name' => 'backup', '--failed' => true]);

        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->assertSame([], $this->names());
        // first_seen_at は見張りだけが付ける。読むだけの口は付けない
        $this->assertNull(DB::table('foundation_task_runs')->where('name', 'backup')->value('first_seen_at'));
    }

    public function test_reading_does_not_write_and_keeps_the_order_of_the_watch_list(): void
    {
        config()->set('foundation.monitoring.external', [
            'job-b' => ['max_age_minutes' => 60],
            'job-a' => ['max_age_minutes' => 60],
        ]);

        Carbon::setTestNow('2026-11-15 09:00:00');
        Artisan::call('foundation:watch');
        $before = $this->rows();

        Carbon::setTestNow('2026-11-15 10:01:00');
        $this->assertSame(['job-b', 'job-a'], $this->names());
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, DB::table('foundation_alerts')->count());
    }

    public function test_watch_reports_exactly_the_tasks_the_check_reads(): void
    {
        config()->set('foundation.monitoring.external', [
            'job-a' => ['max_age_minutes' => 60],
            'job-b' => ['max_age_minutes' => 600],
        ]);

        Carbon::setTestNow('2026-11-15 09:00:00');
        Artisan::call('foundation:watch');

        Carbon::setTestNow('2026-11-15 10:01:00');
        $read = $this->check()->stalled();
        $this->assertSame(['job-a'], array_map(fn (StalledTask $task) => $task->name, $read));

        $this->assertSame(1, Artisan::call('foundation:watch'));
        $alert = DB::table('foundation_alerts')->first();
        $this->assertSame('foundation.watch:'.sha1('job-a'), $alert->key);
        $this->assertSame("定期の処理が止まっているか、動いていません。\n\n・".$read[0]->message(), $alert->body);
    }

    public function test_nothing_is_stalled_while_monitoring_is_disabled(): void
    {
        config()->set('foundation.monitoring.external', ['job-a' => ['max_age_minutes' => 60]]);

        Carbon::setTestNow('2026-11-15 09:00:00');
        Artisan::call('foundation:watch');

        config()->set('foundation.monitoring.enabled', false);
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->assertSame([], $this->names());
    }
}
