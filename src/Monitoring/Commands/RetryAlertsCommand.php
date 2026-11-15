<?php

namespace Miraqceed\Foundation\Monitoring\Commands;

use Illuminate\Console\Command;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;

class RetryAlertsCommand extends Command
{
    protected $signature = 'foundation:alerts:retry {--limit=20 : 1回に送り直す行の上限}';

    protected $description = '送れていない運営への知らせを送り直す';

    public function handle(AlertSender $sender): int
    {
        if (! config('foundation.monitoring.enabled')) {
            return self::SUCCESS;
        }

        $this->info('送り直した行: '.$sender->retry(max(1, (int) $this->option('limit'))));

        return self::SUCCESS;
    }
}
