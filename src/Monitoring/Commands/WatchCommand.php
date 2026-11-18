<?php

namespace Miraqceed\Foundation\Monitoring\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Failures\FailureReporter;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
use Miraqceed\Foundation\Monitoring\DatabaseSize;
use Miraqceed\Foundation\Monitoring\FailedJobCheck;
use Miraqceed\Foundation\Monitoring\QueueCheck;
use Miraqceed\Foundation\Monitoring\StallCheck;
use Miraqceed\Foundation\Monitoring\StalledTask;
use Miraqceed\Foundation\Monitoring\TaskRuns;
use Miraqceed\Foundation\Monitoring\WatchList;
use Throwable;

class WatchCommand extends Command
{
    private const BEAT_TRIES = 3;

    private const BEAT_RETRY_MS = 2000;

    protected $signature = 'foundation:watch';

    protected $description = '定期の処理・キューの溜まり・新しい失敗のジョブ・DB の大きさを確かめ、問題があれば運営へ知らせる';

    public function handle(AlertSender $sender, WatchList $list, StallCheck $check, QueueCheck $queues, FailedJobCheck $failedJobs, DatabaseSize $size): int
    {
        if (! config('foundation.monitoring.enabled')) {
            return self::SUCCESS;
        }

        // まず送れていない知らせを送り直す
        $sender->retry();

        // 初めて見た印を付けてから、止まっているかを読む（判断は StallCheck の1か所）。
        // 見張りが記録できないのは気付くべきことなので、ここは投げる（TaskRuns::seen()）
        foreach (array_keys($list->all()) as $name) {
            TaskRuns::seen((string) $name);
        }
        $stalled = $check->stalled();

        $healthy = true;
        if ($stalled !== []) {
            $problems = array_map(fn (StalledTask $task) => $task->message(), $stalled);
            foreach ($problems as $problem) {
                $this->warn($problem);
            }
            $names = array_map(fn (StalledTask $task) => $task->name, $stalled);
            $sender->send(self::alertKey($names), '定期の処理が止まっています', "定期の処理が止まっているか、動いていません。\n\n・".implode("\n・", $problems));
            $healthy = false;
        }

        // 1つの読みが投げても、ほかを読む。読めなかったことも健全でない扱い（心拍を送らない）
        $healthy = $this->queues($sender, $queues) && $healthy;
        $healthy = $this->failedJobs($sender, $failedJobs) && $healthy;
        $healthy = $this->databaseSize($sender, $size) && $healthy;

        if (! $healthy) {
            return self::FAILURE;
        }

        $this->beat();
        $this->info('定期の処理はすべて動いています');

        return self::SUCCESS;
    }

    /** 溜まったキュー（読めないキューも）を、キューごとの鍵で知らせる */
    private function queues(AlertSender $sender, QueueCheck $queues): bool
    {
        try {
            $stalled = $queues->stalled();
        } catch (Throwable $e) {
            app(FailureReporter::class)->report($e);
            $this->warn('キューの溜まりを読めませんでした');

            return false;
        }

        foreach ($stalled as $state) {
            $this->warn($state->message());
            $sender->send("foundation.watch.queue:{$state->connection}:{$state->queue}", 'キューが溜まっています', $state->message());
        }

        return $stalled === [];
    }

    /** 新しく失敗したジョブを知らせる。しおりは、知らせを渡せた後（か、数が0のとき）にだけ進める */
    private function failedJobs(AlertSender $sender, FailedJobCheck $failedJobs): bool
    {
        try {
            $failed = $failedJobs->read();
            if ($failed === null) {
                return true;
            }

            if ($failed->total() === 0) {
                $failedJobs->advance($failed->until);

                return true;
            }

            $this->warn("新しく失敗したジョブが {$failed->total()}件あります");
            $key = 'foundation.watch.failed-jobs';
            // 間引きの間（失敗のジョブの知らせを少し前に作った）なら、しおりを進める（進めないと、間引きが明けるまで毎回
            // 健全でない扱いになり、心拍が止まる）。send() の null は書き込みの失敗のときもあるので、間引きは先に見分ける。
            // 書き込みに失敗したときだけ進めず、次の回に同じ範囲をもう一度読む
            if ($sender->throttled($key) || $sender->send($key, '失敗したジョブがあります', $failed->message()) !== null) {
                $failedJobs->advance($failed->until);
            }
        } catch (Throwable $e) {
            app(FailureReporter::class)->report($e);
            $this->warn('失敗したジョブを数えられませんでした');
        }

        return false;
    }

    /** 容量（monitoring.database.capacity_bytes）を決めたときだけ、DB の大きさを割合と比べる */
    private function databaseSize(AlertSender $sender, DatabaseSize $size): bool
    {
        $capacity = (int) config('foundation.monitoring.database.capacity_bytes');
        if ($capacity <= 0) {
            return true;
        }
        $ratio = (float) config('foundation.monitoring.database.alert_ratio', 0.7);

        try {
            $bytes = $size->bytes();
        } catch (Throwable $e) {
            app(FailureReporter::class)->report($e);
            $this->warn('DB の大きさを読めませんでした');

            return false;
        }

        // MySQL・MariaDB・PostgreSQL のほか（SQLite など）は見ない
        if ($bytes === null || $bytes < $capacity * $ratio) {
            return true;
        }

        $message = sprintf('DB の大きさが容量の %.1f%% です（%.2f GB / %.2f GB。知らせる割合 %.0f%%）', $bytes / $capacity * 100, $bytes / 1e9, $capacity / 1e9, $ratio * 100);
        $this->warn($message);
        $sender->send('foundation.watch.database-size', 'DB の大きさが容量に近づいています', $message);

        return false;
    }

    /**
     * 知らせの鍵は止まっている処理の名前の組ごと（並べ替えて ',' でつないだものの sha1）。
     * 新しく止まった処理は間引きの間でもすぐ知らせ、同じ組は間引く。
     *
     * @param  list<string>  $names
     */
    public static function alertKey(array $names): string
    {
        sort($names, SORT_STRING);

        return 'foundation.watch:'.sha1(implode(',', $names));
    }

    /** 外の死活監視へ合図を送る。送れなくても失敗にしない（送り先の URL は鍵にあたるので出さない） */
    private function beat(): void
    {
        $url = config('foundation.monitoring.heartbeat_url');
        if (blank($url)) {
            return;
        }

        try {
            // 一瞬の通信の失敗で心拍が抜けると、外の監視が「止まった」と知らせうる。合わせて3回、2秒あけて送る
            // （最悪でも約34秒で、見張りの重なり止めの10分に収まる）。送り直すのはつながらない・5xx・429 だけで、
            // 4xx（URL の書き損じ・鍵の誤り）は送り直しても直らないので1回で諦める
            Http::timeout(10)
                ->retry(self::BEAT_TRIES, self::BEAT_RETRY_MS, fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)))
                ->get($url);
        } catch (Throwable $e) {
            $this->warn('外部の死活監視へ合図を送れませんでした（'.class_basename($e).'）');
        }
    }
}
