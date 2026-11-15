<?php

namespace Miraqceed\Foundation\Monitoring\Commands;

use Illuminate\Console\Command;
use Miraqceed\Foundation\Monitoring\TaskRuns;

class RecordCommand extends Command
{
    protected $signature = 'foundation:record {name : 処理の名前} {--failed : 失敗として記録する} {--detail= : 結果のメモ}';

    protected $description = 'schedule の外の処理の終わりを記録する（見張りが読む）';

    public function handle(): int
    {
        if (! config('foundation.monitoring.enabled')) {
            return self::SUCCESS;
        }

        $detail = $this->option('detail');
        TaskRuns::finished((string) $this->argument('name'), ! $this->option('failed'), $detail === '' ? null : $detail);

        return self::SUCCESS;
    }
}
