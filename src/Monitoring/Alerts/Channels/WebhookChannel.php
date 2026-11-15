<?php

namespace Miraqceed\Foundation\Monitoring\Alerts\Channels;

use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\Channel;
use RuntimeException;

class WebhookChannel implements Channel
{
    public function name(): string
    {
        return 'webhook';
    }

    public function deliver(Alert $alert): void
    {
        $url = (string) config('foundation.monitoring.alerts.webhook.url');
        if ($url === '') {
            throw new RuntimeException('webhook.url が設定されていません');
        }

        $body = LogChannel::payload($alert);
        // 署名なしの知らせは受け取る側で偽物と見分けられない。秘密がなければ送らない
        $secret = (string) config('foundation.monitoring.alerts.webhook.secret');
        if ($secret === '') {
            throw new RuntimeException('webhook.secret が設定されていません');
        }
        $timestamp = (string) now()->timestamp;
        $headers = [
            'X-Foundation-Timestamp' => $timestamp,
            'X-Foundation-Signature' => self::sign($secret, $timestamp, $body),
        ];

        $response = Http::withHeaders($headers)
            ->connectTimeout(10)->timeout(10)
            ->withBody($body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }
    }

    /** `v1=` + hex(hmac_sha256(secret, timestamp + "." + body)) */
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
