<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\FailedJobCheck;
use Miraqceed\Foundation\Queue\CronWorker;
use Miraqceed\Foundation\Tests\Support\RefreshesQueueDatabase;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

/** foundation:watch がキューの溜まり・新しい失敗のジョブ・DB の大きさを見て知らせ、問題があれば心拍を送らない */
class WatchQueueTest extends TestCase
{
    use RefreshesQueueDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log']);
        $app['config']->set('foundation.monitoring.heartbeat_url', 'https://heartbeat.example.test/ping');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.retry_after', 90);
        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', null);
        $app['config']->set('queue.failed.table', 'failed_jobs');
    }

    /** 失敗のジョブの時刻を、アプリの時間帯で書く（Laravel の書き方とそろえる） */
    protected function getApplicationTimezone($app)
    {
        return 'Asia/Tokyo';
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function watch(): int
    {
        return Artisan::call('foundation:watch');
    }

    private function failJob(string $queue = 'default'): void
    {
        // Laravel が失敗のジョブを書くのと同じ道（failed_at は Date::now()）
        $this->app['queue.failer']->log('database', $queue, json_encode(['uuid' => (string) Str::uuid(), 'secret' => '本文']), new RuntimeException('例外の文'));
    }

    private function bookmark(): ?string
    {
        $value = DB::table('foundation_task_runs')->where('name', FailedJobCheck::BOOKMARK)->value('last_succeeded_at');

        return $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    public function test_すべて健全なら心拍を送る(): void
    {
        CronWorker::schedule(queues: ['default']);

        $this->assertSame(0, $this->watch());

        Http::assertSentCount(1);
        $this->assertSame(0, Alert::count());
    }

    public function test_溜まったキューを知らせ_心拍を送らない(): void
    {
        CronWorker::schedule(queues: ['mail', 'default']);
        DB::table('jobs')->insert(['queue' => 'mail', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(11)->getTimestamp(), 'created_at' => now()->subMinutes(11)->getTimestamp()]);

        $this->assertSame(1, $this->watch());

        $alert = Alert::sole();
        $this->assertSame('foundation.watch.queue:database:mail', $alert->key);
        $this->assertStringContainsString('11分待っているジョブがあります', $alert->body);
        Http::assertNothingSent();
    }

    public function test_失敗のジョブは初めての回は数えず_新しい分だけを1回知らせる(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 09:30:00'));
        $this->failJob();
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));

        // 初めての回：しおりだけを作る（入れる前の失敗を知らせない）
        $this->assertSame(0, $this->watch());
        $this->assertSame('2026-11-18 09:59:59', $this->bookmark());
        $this->assertSame(0, Alert::count());

        Carbon::setTestNow(Carbon::parse('2026-11-18 10:30:00'));
        $this->failJob('mail');
        $this->failJob('mail');
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:00:00'));

        $this->assertSame(1, $this->watch());
        $alert = Alert::sole();
        $this->assertSame('foundation.watch.failed-jobs', $alert->key);
        $this->assertStringContainsString('新しく失敗したジョブが 2件', $alert->body);
        $this->assertStringContainsString('database:mail：2件', $alert->body);
        $this->assertStringNotContainsString('例外の文', $alert->body);
        $this->assertStringNotContainsString('本文', $alert->body);
        $this->assertSame('2026-11-18 10:59:59', $this->bookmark());

        // 同じ失敗を数え直さない
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));
        $this->assertSame(0, $this->watch());
        $this->assertSame(1, Alert::count());
    }

    public function test_今の秒の失敗は次の回に数える(): void
    {
        $this->watch();
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:00:00'));
        $this->failJob();

        // 読む秒と同じ秒に書かれた失敗は、この回の範囲（10:59:59 まで）に入らない
        $this->assertSame(0, $this->watch());
        $this->assertSame(0, Alert::count());

        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));
        $this->assertSame(1, $this->watch());
        $this->assertStringContainsString('1件', Alert::sole()->body);
    }

    public function test_知らせを渡せなければしおりを進めない(): void
    {
        $this->watch();
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:30:00'));
        $this->failJob();
        // 同じ鍵の知らせを間引きの間に作ってあると、send() は null を返す
        Alert::create(['key' => 'foundation.watch.failed-jobs', 'subject' => 's', 'body' => 'b', 'created_at' => now()]);
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:00:00'));

        $this->assertSame(1, $this->watch());
        $this->assertSame('2026-11-18 09:59:59', $this->bookmark());

        // 間引きが明けたら、同じ範囲をもう一度読んで知らせる
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:40:00'));
        $this->assertSame(1, $this->watch());
        $this->assertStringContainsString('1件', Alert::where('body', '!=', 'b')->sole()->body);
        $this->assertSame('2026-11-18 11:39:59', $this->bookmark());
    }

    public function test_キューを読めなくても失敗のジョブを読む(): void
    {
        Exceptions::fake();
        config(['foundation.monitoring.queues' => ['database' => ['default' => 5]], 'queue.connections.database.table' => 'missing_jobs']);
        $this->watch();
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:30:00'));
        $this->failJob();
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:00:00'));

        $this->assertSame(1, $this->watch());

        // キューは1回目と2回目の両方で知らせる（間引きの55分を過ぎている）。失敗のジョブは2回目に
        $this->assertSame(['foundation.watch.failed-jobs', 'foundation.watch.queue:database:default'], Alert::distinct()->orderBy('key')->pluck('key')->all());
        $this->assertSame(1, Alert::where('key', 'foundation.watch.failed-jobs')->count());
        Http::assertNothingSent();
    }

    public function test_容量を決めなければ_DB_の大きさを読まない(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->watch();

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('information_schema', $sql);
            $this->assertStringNotContainsString('pg_database_size', $sql);
        }
    }

    public function test_DB_の大きさが割合を超えたら知らせる(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite は見ない（健全のまま）
            config(['foundation.monitoring.database.capacity_bytes' => 1]);
            $this->assertSame(0, $this->watch());
            $this->assertSame(0, Alert::count());

            return;
        }

        config(['foundation.monitoring.database' => ['capacity_bytes' => 10_000_000_000_000, 'alert_ratio' => 0.7]]);
        $this->assertSame(0, $this->watch());

        config(['foundation.monitoring.database' => ['capacity_bytes' => 1000, 'alert_ratio' => 0.7]]);
        $this->assertSame(1, $this->watch());
        $alert = Alert::sole();
        $this->assertSame('foundation.watch.database-size', $alert->key);
        $this->assertStringContainsString('DB の大きさが容量の', $alert->body);
        Http::assertSentCount(1);
    }
}
