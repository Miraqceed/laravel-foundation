<?php

namespace Miraqceed\Foundation\Tests\Feature\Queue;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use InvalidArgumentException;
use LogicException;
use Miraqceed\Foundation\Monitoring\WatchList;
use Miraqceed\Foundation\Queue\CronWorker;
use Miraqceed\Foundation\Queue\CronWorkers;
use Miraqceed\Foundation\Tests\TestCase;

class CronWorkerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.retry_after', 90);
    }

    /** @return list<Event> */
    private function events(): array
    {
        return $this->app->make(Schedule::class)->events();
    }

    public function test_毎分_重なり止め_裏で動かす_queue_work_を登録する(): void
    {
        $event = CronWorker::schedule(queues: ['mail', 'default'], maxTime: 20, timeout: 30, tries: 2);

        $this->assertSame([$event], $this->events());
        $this->assertStringEndsWith("queue:work 'database' --queue=mail,default --stop-when-empty --max-time=20 --timeout=30 --tries=2 --sleep=1", str_replace('"', "'", $event->command));
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        // (20 + 30 + 30) / 60 の切り上げ
        $this->assertSame(2, $event->expiresAt);
        $this->assertTrue($event->runInBackground);
    }

    public function test_ロックは予算とジョブ1つより長い(): void
    {
        $event = CronWorker::schedule(queues: ['heavy'], maxTime: 40, timeout: 75);

        // (40 + 75 + 30) / 60 = 2.4… の切り上げ
        $this->assertSame(3, $event->expiresAt);
    }

    public function test_見張りに載り_一覧に覚える(): void
    {
        CronWorker::schedule(queues: ['mail', 'default'], maxTime: 20, timeout: 30);

        $this->assertSame(['queue-worker:mail,default' => ['max' => 7, 'label' => 'queue-worker:mail,default']], $this->app->make(WatchList::class)->all());

        $workers = $this->app->make(CronWorkers::class)->all();
        $this->assertCount(1, $workers);
        $this->assertSame('database', $workers[0]->connection);
        $this->assertSame(['mail', 'default'], $workers[0]->queues);
        $this->assertSame(90, $workers[0]->retryAfter());
        $this->assertNull($workers[0]->problem());
    }

    public function test_この環境の外に絞った_worker_は一覧に出さない(): void
    {
        CronWorker::schedule()->environments('production');

        $this->assertSame([], $this->app->make(CronWorkers::class)->all());
    }

    public function test_呼ばなければ何も登録しない(): void
    {
        $this->assertSame([], $this->events());
        $this->assertSame([], $this->app->make(CronWorkers::class)->all());
    }

    public function test_timeout_と余白が_retry_after_を超えると本番でない環境では止める(): void
    {
        // 80 + 10 = 90 はちょうどなので通す
        CronWorker::schedule(queues: ['a'], timeout: 80);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('retry_after');

        CronWorker::schedule(queues: ['b'], timeout: 81);
    }

    public function test_本番では止めずに登録する(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        CronWorker::schedule(queues: ['a'], timeout: 120);

        $workers = $this->app->make(CronWorkers::class)->all();
        $this->assertCount(1, $workers);
        $this->assertStringContainsString('retry_after', (string) $workers[0]->problem());
    }

    public function test_database_でない接続は本番でない環境では止める(): void
    {
        config(['queue.connections.sync' => ['driver' => 'sync']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('database でありません');

        CronWorker::schedule(connection: 'sync');
    }

    public function test_キューの名前と数を確かめる(): void
    {
        foreach ([[], ['a,b'], ['a b'], [''], [1]] as $queues) {
            try {
                CronWorker::schedule(queues: $queues);
                $this->fail('止まるはず');
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(InvalidArgumentException::class);
        CronWorker::schedule(maxTime: 0);
    }
}
