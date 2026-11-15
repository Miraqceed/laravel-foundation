<?php

namespace Miraqceed\Foundation\Monitoring\Alerts\Channels;

use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\Channel;
use RuntimeException;

class ChatworkChannel implements Channel
{
    public function name(): string
    {
        return 'chatwork';
    }

    public function deliver(Alert $alert): void
    {
        $token = (string) config('foundation.monitoring.alerts.chatwork.token');
        $room = (string) config('foundation.monitoring.alerts.chatwork.room_id');
        if ($token === '' || $room === '') {
            throw new RuntimeException('chatwork.token・chatwork.room_id が設定されていません');
        }

        // [ を全角にして、Chatwork の記法（[toall] など）を効かせない
        $body = str_replace('[', '［', "【".config('app.name')."】{$alert->subject}\n{$alert->body}");

        $response = Http::withHeaders(['X-ChatWorkToken' => $token])
            ->connectTimeout(10)->timeout(10)
            ->asForm()
            ->post('https://api.chatwork.com/v2/rooms/'.rawurlencode($room).'/messages', ['body' => $body]);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }
    }
}
