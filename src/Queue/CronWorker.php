<?php

namespace Miraqceed\Foundation\Queue;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use InvalidArgumentException;
use LogicException;

/**
 * 常駐の worker を置けない共用サーバーで、毎分の cron（schedule:run）から database のキューを回す worker を登録する。
 * アプリが routes/console.php で呼んだときだけ登録する。
 *
 * 守ること（数がつながっているので、ここで計算して確かめる）：
 * - --max-time はジョブとジョブの間でしか見ない。1回の worker は「予算＋ジョブ1つ」まで動く。重なり止めのロックはそれより長くする
 * - database のキューは、取ってから retry_after 秒たったジョブを、処理中でも取り直す。timeout ＋ 余白 ≦ retry_after にする
 *   （timeout で殺された後、失敗の記録・解放までに時間が掛かる）
 * - 1つの遅い処理がほかの毎分の処理を遅らせないよう、裏で動かす
 * - 部品5の見張り（watched()）で、worker が回っているかを見る
 */
final class CronWorker
{
    /** timeout と retry_after の間に置く余白（秒） */
    public const RETRY_MARGIN_SECONDS = 10;

    /** ロックの長さに足す余白（秒） */
    private const LOCK_MARGIN_SECONDS = 30;

    /** 許す古さ（watched()）に、ロックの分の上に足す分 */
    private const WATCH_SLACK_MINUTES = 5;

    /**
     * @param  list<string>  $queues  取る順のキューの並び（前ほど先に取る。メールを先に、重い仕事は別の worker に）
     * @param  int  $maxTime  1回の worker の予算（秒）。ジョブの間でだけ見る
     * @param  int  $timeout  ジョブ1つの上限（秒）。pcntl がないと効かない（docs/queue.md）
     * @param  string|null  $connection  キューの接続（null は queue.default）
     */
    public static function schedule(array $queues = ['default'], int $maxTime = 40, int $timeout = 30, int $tries = 3, ?string $connection = null): Event
    {
        $queues = self::queues($queues);
        if ($maxTime < 1 || $timeout < 1 || $tries < 1) {
            throw new InvalidArgumentException('CronWorker::schedule() の maxTime・timeout・tries は1以上にしてください');
        }

        $connection ??= (string) config('queue.default');
        $lockMinutes = (int) ceil(($maxTime + $timeout + self::LOCK_MARGIN_SECONDS) / 60);
        $worker = new Worker($connection, $queues, $maxTime, $timeout, $tries, $lockMinutes);

        // 本番では起動を落とさない（deploy-check の queue_timeouts が fail にする）。ほかの環境では、手元で気付けるよう止める
        $problem = $worker->problem();
        if ($problem !== null && ! app()->environment('production')) {
            throw new LogicException("CronWorker::schedule()：{$problem}");
        }

        $event = app(Schedule::class)->command('queue:work', [
            $connection,
            '--queue='.implode(',', $queues),
            '--stop-when-empty',
            '--max-time='.$maxTime,
            '--timeout='.$timeout,
            '--tries='.$tries,
            '--sleep=1',
        ])
            ->everyMinute()
            // 落ちたとき（kill・OOM）は、この分だけキューが止まる
            ->withoutOverlapping($lockMinutes)
            ->runInBackground()
            ->watched($worker->name(), $lockMinutes + self::WATCH_SLACK_MINUTES);

        app(CronWorkers::class)->add($event, $worker);

        return $event;
    }

    /**
     * @param  array<mixed>  $queues
     * @return list<string>
     */
    private static function queues(array $queues): array
    {
        $names = array_values($queues);
        if ($names === []) {
            throw new InvalidArgumentException('CronWorker::schedule() の queues が空です');
        }

        foreach ($names as $name) {
            // 並びは , でつなぐので、名前に , や空白・制御の字を入れない
            if (! is_string($name) || preg_match('/\A[A-Za-z0-9._:\-]{1,64}\z/', $name) !== 1) {
                throw new InvalidArgumentException('CronWorker::schedule() のキューの名前は、英数字と . _ : - の64字以内にしてください');
            }
        }

        return $names;
    }
}
