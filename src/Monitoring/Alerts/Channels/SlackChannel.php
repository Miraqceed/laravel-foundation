<?php

namespace Miraqceed\Foundation\Monitoring\Alerts\Channels;

use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\Channel;
use RuntimeException;

class SlackChannel implements Channel
{
    public function name(): string
    {
        return 'slack';
    }

    public function deliver(Alert $alert): void
    {
        $url = (string) config('foundation.monitoring.alerts.slack.webhook_url');
        if ($url === '') {
            throw new RuntimeException('slack.webhook_url が設定されていません');
        }

        $response = Http::connectTimeout(10)->timeout(10)
            ->asJson()
            ->post($url, ['text' => '【'.config('app.name')."】{$alert->subject}\n{$alert->body}"]);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }
    }
}
