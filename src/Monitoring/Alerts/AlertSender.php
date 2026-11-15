<?php

namespace Miraqceed\Foundation\Monitoring\Alerts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\ChatworkChannel;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\LogChannel;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\MailChannel;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\SlackChannel;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\WebhookChannel;
use RuntimeException;
use Throwable;

/**
 * 運営への知らせ。先に知らせと送り先ごとの行を書いてから送り、送り先ごとに送れたかを残す。
 * 呼び出し元の処理の中で使うので、送り先の失敗も書き込みの失敗も投げない。
 * DB への書き込みは savepoint で包み、失敗しても外のトランザクションを中断させない。
 */
class AlertSender
{
    public const BODY_LIMIT = 4000;

    private const ERROR_LIMIT = 500;

    /** @var array<string, class-string<Channel>> */
    private const CHANNELS = [
        'log' => LogChannel::class,
        'mail' => MailChannel::class,
        'webhook' => WebhookChannel::class,
        'chatwork' => ChatworkChannel::class,
        'slack' => SlackChannel::class,
    ];

    /** 同じ鍵の知らせを throttle_minutes 以内に作っていれば（送れたかは問わない）作らず null */
    public function send(string $key, string $subject, string $body): ?Alert
    {
        if (! config('foundation.monitoring.enabled')) {
            return null;
        }

        try {
            $alert = DB::transaction(function () use ($key, $subject, $body) {
                $throttle = (int) config('foundation.monitoring.alerts.throttle_minutes', 60);
                if (Alert::where('key', $key)->where('created_at', '>', now()->subMinutes($throttle))->exists()) {
                    return null;
                }

                $alert = Alert::create([
                    'key' => mb_substr($key, 0, 255),
                    'subject' => mb_substr($subject, 0, 255),
                    'body' => mb_substr($body, 0, self::BODY_LIMIT),
                    'created_at' => now(),
                ]);
                foreach (array_keys($this->channels()) as $name) {
                    $alert->deliveries()->create(['channel' => $name]);
                }

                return $alert;
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($alert === null) {
            return null;
        }

        foreach ($alert->deliveries()->get() as $delivery) {
            $delivery->setRelation('alert', $alert);
            $this->attempt($delivery);
        }

        return $alert;
    }

    /**
     * 送れていない送り先の行を、最後に試した時刻の古い順（まだ試していない行が先）に最大 $limit 行だけ送り直す。
     *
     * @return int 送り直した行の数
     */
    public function retry(int $limit = 20): int
    {
        if (! config('foundation.monitoring.enabled')) {
            return 0;
        }

        try {
            $rows = AlertDelivery::with('alert')
                ->whereNull('sent_at')->whereNull('abandoned_at')
                ->orderByRaw('last_attempted_at is not null')
                ->orderBy('last_attempted_at')->orderBy('id')
                ->limit(max(0, $limit))->get();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        $retried = 0;
        foreach ($rows as $delivery) {
            $max = (int) config('foundation.monitoring.alerts.max_attempts', 10);
            if ($delivery->attempts >= $max || ! isset($this->channels()[$delivery->channel])) {
                $this->abandon($delivery);

                continue;
            }
            $this->attempt($delivery);
            $retried++;
        }

        return $retried;
    }

    private function attempt(AlertDelivery $delivery): void
    {
        $channel = $this->channels()[$delivery->channel] ?? null;
        if ($channel === null) {
            $this->abandon($delivery);

            return;
        }

        $error = null;
        try {
            $channel->deliver($delivery->alert);
        } catch (Throwable $e) {
            $error = $this->describe($e);
        }

        $this->write(function () use ($delivery, $error) {
            $delivery->attempts++;
            $delivery->last_attempted_at = now();
            $delivery->last_error = $error;
            if ($error === null) {
                $delivery->sent_at = now();
            }
            $delivery->save();
        });

        if ($error !== null && $delivery->attempts >= (int) config('foundation.monitoring.alerts.max_attempts', 10)) {
            $this->abandon($delivery);
        }
    }

    /** 諦めたことを report() とアプリのログに1行残す（知らせが誰の目にも触れずに消えないため） */
    private function abandon(AlertDelivery $delivery): void
    {
        $this->write(function () use ($delivery) {
            $delivery->abandoned_at = now();
            $delivery->save();
        });

        $key = $delivery->alert->key;
        $error = $delivery->last_error ?? '送り先が設定から外れています';

        try {
            Log::error('foundation.alert.abandoned', [
                'key' => $key,
                'channel' => $delivery->channel,
                'error' => $error,
            ]);
            report(new RuntimeException("知らせを諦めました: {$key} → {$delivery->channel}（{$error}）"));
        } catch (Throwable) {
            // 知らせの後始末で呼び出し元を落とさない
        }
    }

    private function write(callable $write): void
    {
        try {
            DB::transaction($write);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** 送り先の URL に秘密が入りうる（Slack など）ので、誤りの文からは URL を伏せる */
    private function describe(Throwable $e): string
    {
        $message = preg_replace('#https?://\S+#', '[url]', $e->getMessage()) ?? '';

        return mb_substr(get_class($e).': '.$message, 0, self::ERROR_LIMIT);
    }

    /** @return array<string, Channel> */
    private function channels(): array
    {
        $channels = [];
        foreach ((array) config('foundation.monitoring.alerts.channels', ['log']) as $name) {
            if (isset(self::CHANNELS[$name])) {
                $channels[$name] = new (self::CHANNELS[$name]);
            }
        }

        return $channels;
    }
}
