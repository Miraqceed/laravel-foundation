<?php

namespace Miraqceed\Foundation\Integrity;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use LogicException;

/**
 * 毎日の改ざんの検知を schedule に入れる助け（入れるのはアプリ。routes/console.php で呼んだときだけ）。
 *
 * - evenInMaintenanceMode()：storage/framework/down を1つ置くだけで、schedule のほかの処理は止まる。この調べは止めない
 * - evenWhenPaused()（Laravel 13）：schedule:pause でも止めない（12.x にはない）
 * - watched()：部品5の見張りが「最後に成功した時刻」を見る。違いが残る間は 1 で終わるので、26時間後から「止まっています」も出る
 * - withoutOverlapping(10)：期限（既定 240 秒）より長い
 */
final class Integrity
{
    public const COMMAND = 'foundation:integrity-check';

    public const WATCH_NAME = 'integrity-check';

    public const WATCH_MINUTES = 26 * 60;

    private const LOCK_MINUTES = 10;

    public static function schedule(string $at): Event
    {
        $seconds = (int) config('foundation.integrity.seconds', 240);
        if ($seconds > self::LOCK_MINUTES * 60 - 60 && ! app()->environment('production')) {
            throw new LogicException('Integrity::schedule()：foundation.integrity.seconds は 540 秒までにしてください（重なり止めのロックより短く）');
        }

        $event = app(Schedule::class)->command(self::COMMAND)
            ->dailyAt($at)
            ->withoutOverlapping(self::LOCK_MINUTES)
            ->runInBackground()
            ->evenInMaintenanceMode();
        if (method_exists($event, 'evenWhenPaused')) {
            $event->evenWhenPaused();
        }

        return $event->watched(self::WATCH_NAME, self::WATCH_MINUTES);
    }

    /** schedule の中の、この調べの事象（なければ null） */
    public static function scheduledEvent(): ?Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, self::COMMAND) && ! str_contains((string) $event->command, 'integrity-views')) {
                return $event;
            }
        }

        return null;
    }
}
