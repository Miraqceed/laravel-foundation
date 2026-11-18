<?php

namespace Miraqceed\Foundation\Monitoring;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Failures\FailureReporter;
use Miraqceed\Foundation\Queue\CronWorkers;
use RuntimeException;
use Throwable;

/**
 * database のキューの溜まりを読む。foundation:watch と同じ判断で、画面や状態の表示からも呼べる。読むだけで、何も書かない。
 *
 * 見るキュー：CronWorker::schedule() で登録した worker のキュー（許す待ちは既定の10分）と、設定 monitoring.queues
 * （接続 => [キュー => 許す待ち（分）]。worker のキューの許す待ちも、ここで変えられる）。database でない接続は見ない。
 * 1つのキューを1回の集計で読む。ジョブの本文（payload）・id は読まない。
 */
class QueueCheck
{
    public const DEFAULT_ALLOWED_MINUTES = 10;

    public function __construct(private readonly CronWorkers $workers) {}

    /** @return list<QueueState> 見るキューの全部 */
    public function states(?CarbonInterface $now = null): array
    {
        if (! config('foundation.monitoring.enabled')) {
            return [];
        }

        $now ??= now();
        $states = [];
        foreach ($this->targets() as [$connection, $queue, $allowed]) {
            $states[] = $this->read($connection, $queue, $allowed, $now);
        }

        return $states;
    }

    /** @return list<QueueState> 止まっている（読めない・溜まった）キュー */
    public function stalled(?CarbonInterface $now = null): array
    {
        return array_values(array_filter($this->states($now), fn (QueueState $state) => $state->stalled()));
    }

    /** @return list<array{string, string, int}> 接続・キュー・許す待ち（分） */
    private function targets(): array
    {
        $targets = [];
        foreach ($this->workers->all() as $worker) {
            foreach ($worker->queues as $queue) {
                $targets["{$worker->connection}\0{$queue}"] = [$worker->connection, $queue, self::DEFAULT_ALLOWED_MINUTES];
            }
        }

        foreach ((array) config('foundation.monitoring.queues', []) as $connection => $queues) {
            foreach ((array) $queues as $queue => $minutes) {
                $targets["{$connection}\0{$queue}"] = [(string) $connection, (string) $queue, max(1, (int) $minutes)];
            }
        }

        // database でない接続（sync・redis など）は見ない
        return array_values(array_filter($targets, fn (array $target) => config("queue.connections.{$target[0]}.driver") === 'database'));
    }

    private function read(string $connection, string $queue, int $allowed, CarbonInterface $now): QueueState
    {
        try {
            $settings = (array) config("queue.connections.{$connection}");
            $table = $settings['table'] ?? 'jobs';
            if (! is_string($table) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) !== 1) {
                throw new RuntimeException("キューの接続 {$connection} の表の名前が正しくありません");
            }
            $database = $settings['connection'] ?? null;
            $retryAfter = (int) ($settings['retry_after'] ?? 60);
            $time = $now->getTimestamp();
            $expiredBefore = $time - $retryAfter;

            // Laravel の DatabaseQueue と同じ境目：取れる = 予約なしで available_at <= 今。期限切れの予約 = reserved_at <= 今 - retry_after
            $db = DB::connection(is_string($database) && $database !== '' ? $database : null);
            // savepoint で包む：外のトランザクション（画面から呼んだとき）があっても、読めなかったときに
            // PostgreSQL の current transaction is aborted を起こさない（TaskRuns と同じ）
            $row = $db->transaction(fn () => $db->table($table)
                ->where('queue', $queue)
                ->selectRaw('coalesce(sum(case when reserved_at is null and available_at <= ? then 1 else 0 end), 0) as ready_count', [$time])
                ->selectRaw('min(case when reserved_at is null and available_at <= ? then available_at else null end) as oldest_ready_at', [$time])
                ->selectRaw('coalesce(sum(case when reserved_at is not null and reserved_at <= ? then 1 else 0 end), 0) as expired_count', [$expiredBefore])
                ->selectRaw('min(case when reserved_at is not null and reserved_at <= ? then reserved_at else null end) as oldest_expired_at', [$expiredBefore])
                ->first());

            $waits = [];
            if ($row?->oldest_ready_at !== null) {
                $waits[] = $time - (int) $row->oldest_ready_at;
            }
            if ($row?->oldest_expired_at !== null) {
                // 期限切れになってからの待ち（取り直せるようになってから、まだ取られていない）
                $waits[] = $expiredBefore - (int) $row->oldest_expired_at;
            }

            return new QueueState(
                $connection,
                $queue,
                $allowed,
                true,
                (int) ($row->ready_count ?? 0),
                (int) ($row->expired_count ?? 0),
                $waits === [] ? null : max(0, max($waits)),
            );
        } catch (Throwable $e) {
            app(FailureReporter::class)->report($e);

            return new QueueState($connection, $queue, $allowed, false);
        }
    }
}
