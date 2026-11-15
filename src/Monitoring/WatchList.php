<?php

namespace Miraqceed\Foundation\Monitoring;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use WeakMap;

/** 見張る名前の一覧：schedule の watched()（この環境で動く処理だけ）と、設定の external */
class WatchList
{
    /** @var WeakMap<Event, array{0: string, 1: int}> */
    private WeakMap $marks;

    public function __construct()
    {
        $this->marks = new WeakMap;
    }

    public function mark(Event $event, string $name, int $maxAgeMinutes): void
    {
        $this->marks[$event] = [$name, $maxAgeMinutes];
    }

    /** @return array<string, array{max: int, label: string}> 名前ごとの許す古さ（分）と表示名 */
    public function all(): array
    {
        $tasks = [];

        $environment = app()->environment();
        foreach (app(Schedule::class)->events() as $event) {
            // ->environments() でこの環境の外に絞った処理は動かないので見張らない。
            // ->when()・->skip() の条件は見張る時点で評価できないので見張る（docs/monitoring.md）
            if (isset($this->marks[$event]) && $event->runsInEnvironment($environment)) {
                [$name, $max] = $this->marks[$event];
                $tasks[$name] = ['max' => $max, 'label' => $name];
            }
        }

        foreach ((array) config('foundation.monitoring.external', []) as $name => $task) {
            $tasks[(string) $name] = [
                'max' => (int) ($task['max_age_minutes'] ?? 1440),
                'label' => (string) ($task['label'] ?? $name),
            ];
        }

        return $tasks;
    }
}
