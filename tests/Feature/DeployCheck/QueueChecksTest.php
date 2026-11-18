<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Console\Scheduling\Schedule;
use Miraqceed\Foundation\DeployCheck\Checks\QueueTimeouts;
use Miraqceed\Foundation\DeployCheck\Checks\QueueWorker;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Queue\CronWorker;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * queue_worker：sync は later、database で worker がなければ fail、ほかの driver は skip
 * queue_timeouts：timeout ＋ 余白 ＞ retry_after は fail、pcntl がなければ later、自前の queue:work は info
 */
class QueueChecksTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.retry_after', 90);
        $app['config']->set('queue.connections.database.host', 'db.internal.example');
    }

    private function timeouts(bool $pcntl = true): QueueTimeouts
    {
        return new QueueTimeouts(fn (): bool => $pcntl);
    }

    public function test_queue_worker_は_sync_なら_later(): void
    {
        config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);

        $result = (new QueueWorker)->run();

        $this->assertSame(Status::Later, $result->status);
        $this->assertStringContainsString('->delay() が無視', (string) $result->detail);
    }

    public function test_queue_worker_は_database_で_worker_がなければ_fail(): void
    {
        $result = (new QueueWorker)->run();

        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringNotContainsString('db.internal.example', (string) $result->detail);
    }

    public function test_queue_worker_は_CronWorker_か自前の_queue_work_があれば_ok(): void
    {
        $this->app->make(Schedule::class)->command('queue:work --stop-when-empty')->everyMinute();
        $this->assertSame(Status::Ok, (new QueueWorker)->run()->status);

        CronWorker::schedule();
        $result = (new QueueWorker)->run();
        $this->assertSame(Status::Ok, $result->status);
        $this->assertStringContainsString('worker が 1つ', (string) $result->detail);
    }

    public function test_queue_worker_はほかの_driver_では_skip(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis' => ['driver' => 'redis']]);

        $this->assertSame(Status::Skip, (new QueueWorker)->run()->status);
    }

    public function test_queue_timeouts_は余白を含めて_retry_after_と比べる(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // 80 + 10 = 90 はちょうどなので通す
        CronWorker::schedule(queues: ['a'], timeout: 80);
        $this->assertSame(Status::Ok, $this->timeouts()->run()->status);

        CronWorker::schedule(queues: ['b'], timeout: 81);
        $result = $this->timeouts()->run();
        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString('queue-worker:b：', (string) $result->detail);
        $this->assertStringNotContainsString('queue-worker:a', (string) $result->detail);
    }

    public function test_queue_timeouts_は_pcntl_がなければ_later(): void
    {
        CronWorker::schedule();

        $result = $this->timeouts(pcntl: false)->run();

        $this->assertSame(Status::Later, $result->status);
        $this->assertStringContainsString('pcntl がない', (string) $result->detail);
    }

    public function test_queue_timeouts_は自前の_queue_work_なら_info_なければ_skip(): void
    {
        $this->assertSame(Status::Skip, $this->timeouts()->run()->status);

        $this->app->make(Schedule::class)->command('queue:work --stop-when-empty')->everyMinute();
        $this->assertSame(Status::Info, $this->timeouts()->run()->status);
    }
}
