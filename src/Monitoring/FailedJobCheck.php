<?php

namespace Miraqceed\Foundation\Monitoring;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 新しく失敗したジョブ（failed_jobs）を数える。foundation:watch が使う。
 *
 * 失敗のジョブ専用のしおり（foundation_task_runs の行 BOOKMARK の last_succeeded_at）より後の分だけを数える。
 * - しおりは、知らせを渡せた後（か、数が0のとき）にだけ進める（advance()）。渡せなければ、次の回に同じ範囲をもう一度読む
 * - 上の端は「今を秒に切り捨てて1秒引いた時刻」。今の秒の中で後から書かれる失敗を次の回に回す（failed_at は秒まで）
 * - 時刻はアプリの時計とアプリの時間帯：Laravel は failed_at を Date::now() で書く。DB の NOW() は使わない
 * - 初めての回はしおりだけを作り、数えない（入れた直後に、過去の失敗を全部知らせない）
 * 件数を接続とキューごとに読むだけで、本文・例外の文は読まない。
 */
class FailedJobCheck
{
    public const BOOKMARK = 'foundation:failed-jobs';

    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * 新しい失敗の数。数えない（失敗のジョブを database に置いていない・初めての回）ときは null。読めなければ投げる
     */
    public function read(?CarbonInterface $now = null): ?FailedJobs
    {
        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            return null;
        }

        $table = config('queue.failed.table', 'failed_jobs');
        if (! is_string($table) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) !== 1) {
            throw new RuntimeException('queue.failed.table の名前が正しくありません');
        }

        $until = ($now ?? now())->copy()->startOfSecond()->subSecond()->format(self::FORMAT);
        $since = DB::table('foundation_task_runs')->where('name', self::BOOKMARK)->value('last_succeeded_at');

        if ($since === null) {
            $this->advance($until);

            return null;
        }

        $database = config('queue.failed.database');
        $db = DB::connection(is_string($database) && $database !== '' ? $database : null);
        // savepoint で包む（外のトランザクションがあっても、読めなかったときに PostgreSQL のトランザクションを壊さない）
        $rows = $db->transaction(fn () => $db->table($table)
            ->where('failed_at', '>', (string) $since)
            ->where('failed_at', '<=', $until)
            ->groupBy('connection', 'queue')
            ->select('connection', 'queue')
            ->selectRaw('count(*) as failed_count')
            ->get());

        $counts = [];
        foreach ($rows as $row) {
            $counts["{$row->connection}:{$row->queue}"] = (int) $row->failed_count;
        }

        return new FailedJobs($counts, $until);
    }

    /** しおりを進める（知らせを渡せた後か、数が0のときだけ呼ぶ） */
    public function advance(string $until): void
    {
        DB::table('foundation_task_runs')->updateOrInsert(
            ['name' => self::BOOKMARK],
            ['last_succeeded_at' => $until, 'updated_at' => now()->format(self::FORMAT)],
        );
    }
}
