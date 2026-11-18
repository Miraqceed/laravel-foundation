<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Closure;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\Queue\CronWorker;
use Miraqceed\Foundation\Queue\CronWorkers;

/**
 * CronWorker::schedule() の worker ごとに、ジョブが二重に取られないか。
 *
 * - timeout ＋ 余白（CronWorker::RETRY_MARGIN_SECONDS）＞ retry_after：処理中のジョブを次の worker が取り直す → fail
 * - CLI に pcntl がない：worker の --timeout が効かない（止められない）→ later
 * 見られるのは CronWorker で登録した worker だけ。自前の queue:work はオプションを読まない（info）。
 */
final class QueueTimeouts implements Check
{
    /** @param  (Closure(): bool)|null  $pcntl  pcntl があるか（試しで差し替える） */
    public function __construct(private readonly ?Closure $pcntl = null) {}

    public function id(): string
    {
        return 'queue_timeouts';
    }

    public function label(): string
    {
        return 'キューの worker の timeout が retry_after より'.CronWorker::RETRY_MARGIN_SECONDS.'秒以上短く、timeout が効く（pcntl）';
    }

    public function run(): Result
    {
        $workers = app(CronWorkers::class)->all();
        if ($workers === []) {
            return QueueWorker::scheduledQueueWork()
                ? Result::info('CronWorker::schedule() でない queue:work は、オプションを読まないので確かめない')
                : Result::skip('CronWorker::schedule() の worker がない');
        }

        $problems = [];
        foreach ($workers as $worker) {
            $problem = $worker->problem();
            if ($problem !== null) {
                $problems[] = "{$worker->name()}：{$problem}";
            }
        }
        if ($problems !== []) {
            return Result::fail(implode(' / ', $problems));
        }

        if (! ($this->pcntl ?? fn (): bool => extension_loaded('pcntl'))()) {
            return Result::later('CLI に pcntl がない：timeout が効かない。ジョブの中の外への通信に短い timeout を付け、retry_after を予算＋一番長いジョブより長くする（docs/queue.md）');
        }

        return Result::ok();
    }
}
