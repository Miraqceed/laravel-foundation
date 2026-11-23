<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\Integrity\Checks\TreeCheck;
use Miraqceed\Foundation\Integrity\Context;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Integrity as IntegritySchedule;
use Miraqceed\Foundation\Integrity\Settings;
use Miraqceed\Foundation\Integrity\Snapshot;
use Miraqceed\Foundation\Integrity\SnapshotUnavailable;
use Miraqceed\Foundation\Integrity\ViewsMark;
use Miraqceed\Foundation\Integrity\ViewsProcess;
use Miraqceed\Foundation\Monitoring\WatchList;
use Throwable;

/**
 * 改ざんの検知を使っているなら、配った後に一覧があり、毎日の調べが schedule にあり、知らせが人に届くか。
 * 一覧の頭の行と最後の行だけを読む（ハッシュは取らない。速い）。一覧を作り直した後に流す
 * （見る場所の設定を変えて配ると、作り直す前は「設定が一覧と違う」で必ず失敗になる）
 */
final class Integrity implements Check
{
    public function id(): string
    {
        return 'integrity';
    }

    public function label(): string
    {
        return '改ざんの検知の一覧があり、毎日の調べが schedule にあり、知らせが届く（php artisan foundation:integrity-snapshot）';
    }

    public function run(): Result
    {
        try {
            app(WatchList::class)->loadConsoleSchedule();
            $event = IntegritySchedule::scheduledEvent();
            $root = Context::root();
            $snapshotExists = is_file(Snapshot::path()) || is_link(Snapshot::path());

            if ($event === null && ! $snapshotExists) {
                return Result::skip('改ざんの検知を使っていない（schedule にも一覧もない）');
            }

            $failed = [];
            $later = [];
            $problem = Snapshot::quickCheck($root);
            if ($problem !== null) {
                $failed[] = $problem->label().'（配った後に php artisan foundation:integrity-snapshot を流す）';
            } else {
                try {
                    $reader = Snapshot::reader($root);
                    $snapshot = $reader->settings;
                    $reader->close();
                    if ($snapshot !== null && ! $snapshot->sameAs(Settings::current($root))) {
                        $failed[] = '見る場所の設定が一覧と違う。一覧を作り直してから流し直す';
                    }
                } catch (SnapshotUnavailable $e) {
                    $failed[] = $e->gap->label();
                }
            }
            if ($event === null) {
                $failed[] = '一覧があるのに、毎日の調べが schedule にない（Integrity::schedule() を routes/console.php に書く）';
            }
            $current = Settings::current($root);
            if ($current->coveringLoadPaths() !== []) {
                $failed[] = '外すもの・公開される場所の設定が、調べが読み込む場所（app・config・vendor など）に掛かっている';
            }
            if ($current->refused['generated_views'] !== []) {
                $failed[] = Gap::GeneratedViewsRefused->label();
            }
            if ($current->refused['sqlite'] !== []) {
                $failed[] = Gap::SqliteNotExcluded->label();
            }
            if (TreeCheck::publicOutside($root)) {
                $failed[] = Gap::PublicOutside->label();
            }
            if (ViewsMark::crashedLeft()) {
                $failed[] = '画面の調べが落ちた。大きすぎる画面がないか見る';
            }

            if ($event !== null) {
                if (! $event->runsInMaintenanceMode()) {
                    $later[] = 'メンテナンス中に動かない（evenInMaintenanceMode()。Integrity::schedule() を使う）';
                }
                if (method_exists($event, 'evenWhenPaused') && ! $event->runsWhenPaused()) {
                    $later[] = 'schedule:pause の間に動かない（evenWhenPaused()。Integrity::schedule() を使う）';
                }
            }
            $channels = (array) config('foundation.monitoring.alerts.channels', []);
            if (! config('foundation.monitoring.enabled') || array_diff($channels, ['log']) === []) {
                $later[] = '知らせが人に届かない（foundation.monitoring を有効にし、log のほかの送り先を足す）';
            }
            if (! app(ViewsProcess::class)->available()) {
                $later[] = '画面の調べの子プロセスを使えない（proc_open が無効など。画面は控えめに流す）';
            }
        } catch (Throwable $e) {
            return Result::fail('調べられない（'.$e::class.'）');
        }

        if ($failed !== []) {
            return Result::fail(implode('。', [...$failed, ...$later]));
        }
        if ($later !== []) {
            return Result::later(implode('。', $later));
        }

        return Result::ok();
    }
}
