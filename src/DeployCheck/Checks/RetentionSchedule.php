<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\Retention\ResolvedMap;
use Miraqceed\Foundation\Retention\ScheduledPruning;
use Throwable;

/**
 * 消す決まりの台帳（foundation.retention.map）の期限の消し方が、本番の schedule にあるか。
 * DB の表は見ない（本番の DB の作りを読まない）。パッケージの表は、部品が有効なときだけ数える
 */
final class RetentionSchedule implements Check
{
    public function id(): string
    {
        return 'retention_schedule';
    }

    public function label(): string
    {
        return '消す決まりの台帳の、期限で消すモデルとコマンドが schedule にある';
    }

    public function run(): Result
    {
        try {
            $map = ResolvedMap::fromConfig();
        } catch (Throwable $e) {
            return Result::fail('台帳を読めない（'.$e::class.'）。試しの DataMapAssertions で確かめる（docs/retention.md）');
        }

        if ($map === null) {
            return Result::skip('foundation.retention.map を書いていない');
        }

        $problems = ScheduledPruning::missing($map, lookAtDatabase: false, currentEnvironmentOnly: true);
        if ($problems !== []) {
            return Result::fail(implode("\n", $problems));
        }

        return Result::ok();
    }
}
