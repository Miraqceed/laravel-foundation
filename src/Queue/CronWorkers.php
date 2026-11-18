<?php

namespace Miraqceed\Foundation\Queue;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Miraqceed\Foundation\Monitoring\WatchList;
use WeakMap;

/** CronWorker::schedule() で登録した worker の一覧（容器に1つ）。この環境で動くものだけを返す */
class CronWorkers
{
    /** @var WeakMap<Event, Worker> */
    private WeakMap $workers;

    public function __construct()
    {
        $this->workers = new WeakMap;
    }

    public function add(Event $event, Worker $worker): void
    {
        $this->workers[$event] = $worker;
    }

    /** @return list<Worker> */
    public function all(): array
    {
        // Web の要求の中でも、アプリの schedule（routes/console.php）を読ませる
        app(WatchList::class)->loadConsoleSchedule();

        $environment = app()->environment();
        $workers = [];
        foreach (app(Schedule::class)->events() as $event) {
            if (isset($this->workers[$event]) && $event->runsInEnvironment($environment)) {
                $workers[] = $this->workers[$event];
            }
        }

        return $workers;
    }
}
