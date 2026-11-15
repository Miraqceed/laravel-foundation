<?php

namespace Miraqceed\Foundation\Monitoring\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
use Miraqceed\Foundation\Monitoring\TaskRuns;
use Miraqceed\Foundation\Monitoring\WatchList;
use Throwable;

class WatchCommand extends Command
{
    protected $signature = 'foundation:watch';

    protected $description = '定期の処理が止まっていないかを確かめ、止まっていれば運営へ知らせる';

    public function handle(AlertSender $sender, WatchList $list): int
    {
        if (! config('foundation.monitoring.enabled')) {
            return self::SUCCESS;
        }

        // まず送れていない知らせを送り直す
        $sender->retry();

        $problems = [];
        foreach ($list->all() as $name => $task) {
            TaskRuns::seen($name);
            $row = DB::table('foundation_task_runs')->where('name', $name)->first();
            $allowed = now()->subMinutes($task['max']);

            if ($row->last_succeeded_at !== null) {
                $last = Carbon::parse($row->last_succeeded_at);
                if ($last->lt($allowed)) {
                    $problems[] = "{$task['label']}：最後の成功が{$task['max']}分より前です（{$last->toIso8601String()}）";
                }

                continue;
            }

            // 一度も成功していなくても、初めて見てから許す古さがたっていなければ、まだ予定の時刻を迎えていないだけ
            if (Carbon::parse($row->first_seen_at)->lt($allowed)) {
                $problems[] = "{$task['label']}：一度も成功していません";
            }
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->warn($problem);
            }
            $sender->send('foundation.watch', '定期の処理が止まっています', "定期の処理が止まっているか、動いていません。\n\n・".implode("\n・", $problems));

            return self::FAILURE;
        }

        $this->beat();
        $this->info('定期の処理はすべて動いています');

        return self::SUCCESS;
    }

    /** 外の死活監視へ合図を送る。送れなくても失敗にしない（送り先の URL は鍵にあたるので出さない） */
    private function beat(): void
    {
        $url = config('foundation.monitoring.heartbeat_url');
        if (blank($url)) {
            return;
        }

        try {
            Http::timeout(10)->get($url)->throw();
        } catch (Throwable $e) {
            $this->warn('外部の死活監視へ合図を送れませんでした（'.class_basename($e).'）');
        }
    }
}
