<?php

namespace Miraqceed\Foundation\Monitoring;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 止まっている処理を読む。foundation:watch と同じ判断で、画面や状態の表示からも呼べる。
 *
 * 読むだけで、何も書かない（初めて見た印 first_seen_at を付けるのは foundation:watch だけ）。
 * そのため、見張りがまだ一度も見ていない処理は止まっていない扱いになる（見張りの初めの1回と同じ）。
 */
class StallCheck
{
    public function __construct(private readonly WatchList $list) {}

    /** @return list<StalledTask> 止まっている処理（WatchList の順） */
    public function stalled(?CarbonInterface $now = null): array
    {
        if (! config('foundation.monitoring.enabled')) {
            return [];
        }

        $tasks = $this->list->all();
        if ($tasks === []) {
            return [];
        }

        $now ??= now();
        $rows = DB::table('foundation_task_runs')->whereIn('name', array_map('strval', array_keys($tasks)))->get()->keyBy('name');

        $stalled = [];
        foreach ($tasks as $name => $task) {
            $name = (string) $name;
            $row = $rows->get($name);
            $allowed = $now->copy()->subMinutes($task['max']);

            if ($row !== null && $row->last_succeeded_at !== null) {
                $last = Carbon::parse($row->last_succeeded_at);
                if ($last->lt($allowed)) {
                    $stalled[] = new StalledTask($name, $task['label'], $task['max'], $last);
                }

                continue;
            }

            // 一度も成功していなくても、初めて見てから許す古さがたっていなければ、まだ予定の時刻を迎えていないだけ
            if ($row !== null && $row->first_seen_at !== null && Carbon::parse($row->first_seen_at)->lt($allowed)) {
                $stalled[] = new StalledTask($name, $task['label'], $task['max'], null);
            }
        }

        return $stalled;
    }
}
