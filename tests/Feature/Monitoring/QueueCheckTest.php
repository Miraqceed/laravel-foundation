<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Miraqceed\Foundation\Monitoring\QueueCheck;
use Miraqceed\Foundation\Monitoring\QueueState;
use Miraqceed\Foundation\Queue\CronWorker;
use Miraqceed\Foundation\Tests\Support\RefreshesQueueDatabase;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

class QueueCheckTest extends TestCase
{
    use RefreshesQueueDatabase;

    private const NOW = 1_800_000_000;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.retry_after', 90);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestamp(self::NOW));
    }

    private function job(string $queue, int $availableAt, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{"secret":"本文"}',
            'attempts' => $reservedAt === null ? 0 : 1,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }

    /** @return array<string, QueueState> */
    private function states(): array
    {
        $states = [];
        foreach ($this->app->make(QueueCheck::class)->states() as $state) {
            $states["{$state->connection}:{$state->queue}"] = $state;
        }

        return $states;
    }

    public function test_取れるジョブ_遅らせたジョブ_予約中_期限切れの予約を見分ける(): void
    {
        CronWorker::schedule(queues: ['default']);
        $this->job('default', self::NOW - 300);                    // 取れる（5分待ち）
        $this->job('default', self::NOW - 60);                     // 取れる
        $this->job('default', self::NOW + 600);                    // 遅らせた（数えない）
        $this->job('default', self::NOW - 1000, self::NOW - 30);   // 予約中（数えない）
        $this->job('default', self::NOW - 1000, self::NOW - 400);  // 期限切れの予約：90秒たって切れ、310秒待っている
        $this->job('other', self::NOW - 3000);                     // 別のキュー（数えない）

        $state = $this->states()['database:default'];

        $this->assertTrue($state->readable);
        $this->assertSame(2, $state->ready);
        $this->assertSame(1, $state->expiredReservations);
        $this->assertSame(310, $state->oldestWaitSeconds);
        $this->assertFalse($state->stalled());
    }

    public function test_許す待ちの境目(): void
    {
        config(['foundation.monitoring.queues' => ['database' => ['mail' => 5]]]);

        $this->job('mail', self::NOW - 300);
        $this->assertFalse($this->states()['database:mail']->stalled(), 'ちょうど5分は止まっていない');

        $this->job('mail', self::NOW - 301);
        $state = $this->states()['database:mail'];
        $this->assertTrue($state->stalled());
        $this->assertStringContainsString('キュー database:mail：5分待っているジョブがあります', $state->message());
    }

    public function test_空のキューは止まっていない(): void
    {
        CronWorker::schedule(queues: ['mail', 'default']);

        $states = $this->states();

        $this->assertSame(['database:mail', 'database:default'], array_keys($states));
        $this->assertNull($states['database:mail']->oldestWaitSeconds);
        $this->assertSame([], $this->app->make(QueueCheck::class)->stalled());
    }

    public function test_設定で_worker_のキューの許す待ちを変えられる(): void
    {
        CronWorker::schedule(queues: ['default']);
        config(['foundation.monitoring.queues' => ['database' => ['default' => 30]]]);

        $this->assertSame(30, $this->states()['database:default']->allowedMinutes);
    }

    public function test_database_でない接続は見ない(): void
    {
        config(['foundation.monitoring.queues' => ['sync' => ['default' => 5]], 'queue.connections.sync' => ['driver' => 'sync']]);

        $this->assertSame([], $this->states());
    }

    public function test_読めなければ投げずに読めないと返し_記録する(): void
    {
        Exceptions::fake();
        config(['foundation.monitoring.queues' => ['database' => ['default' => 5]], 'queue.connections.database.table' => 'missing_jobs']);

        $state = $this->states()['database:default'];

        $this->assertFalse($state->readable);
        $this->assertTrue($state->stalled());
        $this->assertSame('キュー database:default：溜まりを読めません', $state->message());
        Exceptions::assertReportedCount(1);
    }

    public function test_表の名前の形を確かめる(): void
    {
        Exceptions::fake();
        config(['foundation.monitoring.queues' => ['database' => ['default' => 5]], 'queue.connections.database.table' => 'jobs; drop table jobs']);

        $this->assertFalse($this->states()['database:default']->readable);
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), '表の名前'));
    }

    public function test_ジョブの本文を読まない(): void
    {
        CronWorker::schedule(queues: ['default']);
        $this->job('default', self::NOW - 10);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->states();

        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('payload', $queries[0]);
        $this->assertStringNotContainsString('*', $queries[0]);
    }

    public function test_見張りが無効なら読まない(): void
    {
        config(['foundation.monitoring.enabled' => false, 'foundation.monitoring.queues' => ['database' => ['default' => 5]]]);

        $this->assertSame([], $this->states());
    }
}
