<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Console\Scheduling\Schedule;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\Monitoring\WatchList;
use Miraqceed\Foundation\Queue\CronWorkers;

/**
 * キューの既定の接続で、ジョブが回るか。値（接続先）は出さない。
 *
 * - sync：->delay() が無視され、ジョブが要求の中で動き、失敗が利用者に返る。キューを使わないアプリもあるので later
 * - database：CronWorker::schedule() の worker か、コマンドが queue:work の schedule の処理がなければ fail（ジョブが溜まるだけ）
 * - ほかの driver（redis など）：常駐の worker が別にある前提で skip
 */
final class QueueWorker implements Check
{
    public function id(): string
    {
        return 'queue_worker';
    }

    public function label(): string
    {
        return 'キューのジョブを回す worker がある（database のキューを schedule から回す）';
    }

    public function run(): Result
    {
        $default = config('queue.default');
        $driver = is_string($default) ? config("queue.connections.{$default}.driver") : null;

        if ($driver === 'sync') {
            return Result::later('キューの接続が sync：->delay() が無視され、ジョブが要求の中で動く');
        }

        if ($driver !== 'database') {
            return Result::skip('キューの driver が database でない（'.(is_string($driver) ? $driver : '不明').'）');
        }

        $workers = count(app(CronWorkers::class)->all());
        if ($workers > 0) {
            return Result::ok("CronWorker::schedule() の worker が {$workers}つ");
        }

        if (self::scheduledQueueWork()) {
            return Result::ok('schedule に queue:work の処理がある');
        }

        return Result::fail('database のキューを回す worker が schedule にない（CronWorker::schedule()。docs/queue.md）');
    }

    /** コマンドが queue:work の schedule の処理があるか（CronWorker を使わずに書いたもの） */
    public static function scheduledQueueWork(): bool
    {
        app(WatchList::class)->loadConsoleSchedule();

        $environment = app()->environment();
        foreach (app(Schedule::class)->events() as $event) {
            if (is_string($event->command) && str_contains($event->command, 'queue:work') && $event->runsInEnvironment($environment)) {
                return true;
            }
        }

        return false;
    }
}
