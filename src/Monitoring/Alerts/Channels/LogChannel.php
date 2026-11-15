<?php

namespace Miraqceed\Foundation\Monitoring\Alerts\Channels;

use Illuminate\Support\Facades\Log;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\Channel;

/**
 * アプリのログへ `foundation.alert ` ＋ 1行の JSON を書く（docs/monitoring.md「ログの1行の形」。v1 の契約）。
 *
 * チャネルは monitoring.alerts.log.channel（null ならアプリの既定）、重さは monitoring.alerts.log.level（既定 error）。
 * context と extra はいつも空で渡す（読む側は行末の空の飾りを落とすだけで済む。{…} の置き換えも起きない）。
 * チャネルの level より低い重さだと Monolog が捨て、送れた扱いになる。
 */
class LogChannel implements Channel
{
    public function deliver(Alert $alert): void
    {
        $channel = config('foundation.monitoring.alerts.log.channel');
        $level = (string) (config('foundation.monitoring.alerts.log.level') ?: 'error');

        Log::channel(is_string($channel) && $channel !== '' ? $channel : null)
            ->log($level, 'foundation.alert '.self::payload($alert));
    }

    /** ログと Webhook で共通の1行の JSON（v1。改行は \n に直る。不正な UTF-8 は U+FFFD に置き換える） */
    public static function payload(Alert $alert): string
    {
        return json_encode([
            'v' => 1,
            // 送り直しのたびに Webhook の時刻と署名は変わるので、受け取る側はこの id で同じ知らせを見分ける
            'id' => $alert->id,
            'key' => $alert->key,
            'subject' => $alert->subject,
            'body' => $alert->body,
            'app' => config('app.name'),
            'at' => $alert->created_at->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
