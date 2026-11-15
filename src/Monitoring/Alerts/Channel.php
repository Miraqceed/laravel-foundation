<?php

namespace Miraqceed\Foundation\Monitoring\Alerts;

/**
 * 知らせの送り先。
 *
 * @internal 第1段の送り先は用意した5つ（log・mail・webhook・chatwork・slack）で固定で、アプリが送り先を足す口はない。
 *           この interface は 0.x のうちに予告なく変わりうる（docs/monitoring.md）。
 */
interface Channel
{
    /** 失敗は例外で知らせる。外への要求は10秒で打ち切る */
    public function deliver(Alert $alert): void;
}
