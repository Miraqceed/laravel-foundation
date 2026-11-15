<?php

namespace Miraqceed\Foundation\Monitoring;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use WeakMap;

/** 見張る名前の一覧：schedule の watched() と、設定の external */
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

        foreach (app(Schedule::class)->events() as $event) {
            if (isset($this->marks[$event])) {
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
