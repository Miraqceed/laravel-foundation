<?php

namespace Miraqceed\Foundation\Monitoring\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
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

    protected $description = '定期の処理が止まっていないかを確かめ、止まっていれば運営へ知らせる';

    public function handle(AlertSender $sender, WatchList $list, StallCheck $check): int
    {
        if (! config('foundation.monitoring.enabled')) {
            return self::SUCCESS;
        }

        // まず送れていない知らせを送り直す
        $sender->retry();

        // 初めて見た印を付けてから、止まっているかを読む（判断は StallCheck の1か所）
        foreach (array_keys($list->all()) as $name) {
            TaskRuns::seen((string) $name);
        }
        $stalled = $check->stalled();

        if ($stalled !== []) {
            $problems = array_map(fn (StalledTask $task) => $task->message(), $stalled);
            foreach ($problems as $problem) {
                $this->warn($problem);
            }
            $names = array_map(fn (StalledTask $task) => $task->name, $stalled);
            $sender->send(self::alertKey($names), '定期の処理が止まっています', "定期の処理が止まっているか、動いていません。\n\n・".implode("\n・", $problems));

            return self::FAILURE;
        }

        $this->beat();
        $this->info('定期の処理はすべて動いています');

        return self::SUCCESS;
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
