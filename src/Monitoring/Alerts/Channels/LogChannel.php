<?php

namespace Miraqceed\Foundation\Monitoring\Alerts\Channels;

use Illuminate\Support\Facades\Log;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\Channel;

class LogChannel implements Channel
{
    public function name(): string
    {
        return 'log';
    }

    public function deliver(Alert $alert): void
    {
        Log::warning('foundation.alert '.self::payload($alert));
    }

    /** ログと Webhook で共通の1行の JSON（改行は \n に直る） */
    public static function payload(Alert $alert): string
    {
        return json_encode([
            'v' => 1,
            'key' => $alert->key,
            'subject' => $alert->subject,
            'body' => $alert->body,
            'app' => config('app.name'),
            'at' => $alert->created_at->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
