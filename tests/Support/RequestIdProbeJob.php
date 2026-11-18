<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Miraqceed\Foundation\RequestId\RequestId;

/** 要求の中で入れたジョブが、要求の id を受け取ったかを見る */
class RequestIdProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        Log::info('job ran', ['seen' => RequestId::current()]);
    }
}
