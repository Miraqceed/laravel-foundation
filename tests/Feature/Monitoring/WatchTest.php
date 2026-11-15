<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\AlertDelivery;
use Miraqceed\Foundation\Tests\TestCase;
use Mockery;

class WatchTest extends TestCase
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

    private function schedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    private function row(string $name): ?object
    {
        return DB::table('foundation_task_runs')->where('name', $name)->first();
    }

    public function test_watched_schedule_records_start_and_success(): void
    {
        Carbon::setTestNow('2026-11-15 10:00:00');
        $seen = null;
        $this->schedule()->call(function () use (&$seen) {
            $seen = $this->row('job-a')?->last_started_at;
        })->watched('job-a', 60);

        Artisan::call('schedule:run');

        $this->assertNotNull($seen, '実行中に開始が記録されている');
        $row = $this->row('job-a');
        $this->assertNotNull($row->last_succeeded_at);
        $this->assertNull($row->last_failed_at);
        // first_seen_at を書くのは foundation:watch だけ
        $this->assertNull($row->first_seen_at);
    }

    public function test_failed_schedule_records_failure(): void
    {
        $this->schedule()->call(function () {
            throw new \RuntimeException('boom');
        })->watched('job-f', 60);

        Artisan::call('schedule:run');

        $row = $this->row('job-f');
        $this->assertNotNull($row->last_failed_at);
        $this->assertNull($row->last_succeeded_at);
    }

    public function test_stale_task_is_reported(): void
    {
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->schedule()->call(fn () => null)->watched('job-a', 60);
        Artisan::call('schedule:run');

        Carbon::setTestNow('2026-11-15 10:50:00');
        $this->assertSame(0, Artisan::call('foundation:watch'));
        $this->assertSame(0, Alert::count());

        Carbon::setTestNow('2026-11-15 11:10:00');
        $this->assertSame(1, Artisan::call('foundation:watch'));
        $alert = Alert::first();
        $this->assertSame('foundation.watch', $alert->key);
        $this->assertStringContainsString('job-a', $alert->body);
    }

    public function test_new_task_is_not_reported_until_max_age_since_first_seen(): void
    {
        $this->schedule()->call(fn () => null)->watched('job-new', 60);

        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->assertSame(0, Artisan::call('foundation:watch'));
        $this->assertSame('2026-11-15 10:00:00', Carbon::parse($this->row('job-new')->first_seen_at)->format('Y-m-d H:i:s'));

        // 2回目では first_seen_at を書き換えない
        Carbon::setTestNow('2026-11-15 10:59:00');
        $this->assertSame(0, Artisan::call('foundation:watch'));
        $this->assertSame('2026-11-15 10:00:00', Carbon::parse($this->row('job-new')->first_seen_at)->format('Y-m-d H:i:s'));
        $this->assertSame(0, Alert::count());

        Carbon::setTestNow('2026-11-15 11:01:00');
        $this->assertSame(1, Artisan::call('foundation:watch'));
        $this->assertSame(1, Alert::count());
    }

    public function test_external_task_recorded_by_command(): void
    {
        config()->set('foundation.monitoring.external', ['backup' => ['max_age_minutes' => 60, 'label' => '日次のバックアップ']]);

        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->assertSame(0, Artisan::call('foundation:record', ['name' => 'backup', '--detail' => 'ok']));
        $this->assertSame('ok', $this->row('backup')->last_result);

        Carbon::setTestNow('2026-11-15 10:30:00');
        $this->assertSame(0, Artisan::call('foundation:watch'));

        Carbon::setTestNow('2026-11-15 11:30:00');
        $this->assertSame(1, Artisan::call('foundation:watch'));
        $this->assertStringContainsString('日次のバックアップ', Alert::first()->body);
    }

    public function test_record_failed_does_not_count_as_success(): void
    {
        Artisan::call('foundation:record', ['name' => 'backup', '--failed' => true, '--detail' => 'disk full']);

        $row = $this->row('backup');
        $this->assertNotNull($row->last_failed_at);
        $this->assertNull($row->last_succeeded_at);
        $this->assertSame('disk full', $row->last_result);
    }

    public function test_heartbeat_is_sent_only_when_all_healthy(): void
    {
        config()->set('foundation.monitoring.heartbeat_url', 'https://beat.example.test/x');
        Http::fake(['https://beat.example.test/*' => Http::response('', 200)]);

        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->schedule()->call(fn () => null)->watched('job-a', 60);
        Artisan::call('schedule:run');

        Carbon::setTestNow('2026-11-15 10:10:00');
        $this->assertSame(0, Artisan::call('foundation:watch'));
        Http::assertSentCount(1);

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->assertSame(1, Artisan::call('foundation:watch'));
        Http::assertSentCount(1);
    }

    public function test_heartbeat_failure_does_not_throw(): void
    {
        config()->set('foundation.monitoring.heartbeat_url', 'https://beat.example.test/x');
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        $this->assertSame(0, Artisan::call('foundation:watch'));
    }

    public function test_recording_failure_does_not_break_the_task(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('savepoint の確かめは PostgreSQL の組み合わせだけ');
        }

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report');
        $this->app->instance(ExceptionHandler::class, $handler);
        Schema::dropIfExists('foundation_task_runs');

        $ran = false;
        DB::beginTransaction();
        $this->schedule()->call(function () use (&$ran) {
            $ran = true;
        })->watched('job-a', 60);
        $status = Artisan::call('schedule:run');

        $this->assertTrue($ran);
        $this->assertSame(0, $status);
        // savepoint がないと、ここで current transaction is aborted になる
        $this->assertSame(1, (int) DB::selectOne('select 1 as one')->one);
        DB::rollBack();
    }

    public function test_watch_is_scheduled_only_when_enabled(): void
    {
        $commands = fn () => collect($this->schedule()->events())->filter(fn ($e) => str_contains((string) $e->command, 'foundation:watch'));

        $this->assertCount(1, $commands());
        $this->assertSame('0 * * * *', $commands()->first()->expression);
        $this->assertTrue($commands()->first()->withoutOverlapping);
    }

    public function test_watch_retries_unsent_alerts_first(): void
    {
        config()->set('foundation.monitoring.alerts.channels', ['webhook']);
        config()->set('foundation.monitoring.alerts.webhook.url', 'https://hooks.example.test/in');
        config()->set('foundation.monitoring.alerts.webhook.secret', 'secret');
        Http::fake(['https://hooks.example.test/*' => Http::response('', 200)]);
        $alert = Alert::create(['key' => 'old', 'subject' => 's', 'body' => 'b', 'created_at' => now()]);
        AlertDelivery::create(['alert_id' => $alert->id, 'channel' => 'webhook', 'attempts' => 0]);

        $this->assertSame(0, Artisan::call('foundation:watch'));

        $this->assertNotNull(AlertDelivery::first()->sent_at);
    }
}
