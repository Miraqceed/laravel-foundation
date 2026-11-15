<?php

namespace Miraqceed\Foundation\Monitoring\Alerts;

interface Channel
{
    /** 設定 monitoring.alerts.channels に書く名前 */
    public function name(): string;

    /** 失敗は例外で知らせる。外への要求は10秒で打ち切る */
    public function deliver(Alert $alert): void;
}
