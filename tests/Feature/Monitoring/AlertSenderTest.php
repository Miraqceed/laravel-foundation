<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Carbon\Carbon;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\AlertDelivery;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\AlertMail;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\LogChannel;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\WebhookChannel;
use Miraqceed\Foundation\Tests\TestCase;
use Mockery;

class AlertSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.mail.to', 'ops@example.test');
        $app['config']->set('foundation.monitoring.alerts.webhook.url', 'https://hooks.example.test/in');
        $app['config']->set('foundation.monitoring.alerts.webhook.secret', 'secret');
        $app['config']->set('foundation.monitoring.alerts.chatwork.token', 'cw-token');
        $app['config']->set('foundation.monitoring.alerts.chatwork.room_id', '123');
        $app['config']->set('foundation.monitoring.alerts.slack.webhook_url', 'https://hooks.slack.test/T/B/X');
        $app['config']->set('app.name', 'TestApp');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function channels(array $names): void
    {
        config()->set('foundation.monitoring.alerts.channels', $names);
    }

    private function sender(): AlertSender
    {
        return $this->app->make(AlertSender::class);
    }

    /** 送れていない送り先の行を作る */
    private function pendingRows(int $count, string $channel = 'webhook', int $attempts = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $alert = Alert::create(['key' => "k{$i}", 'subject' => 's', 'body' => 'b', 'created_at' => now()]);
            AlertDelivery::create(['alert_id' => $alert->id, 'channel' => $channel, 'attempts' => $attempts]);
        }
    }

    public function test_writes_rows_before_delivering(): void
    {
        $this->channels(['webhook']);
        $seen = null;
        Http::fake(function () use (&$seen) {
            $seen = [Alert::count(), AlertDelivery::count()];

            return Http::response('', 200);
        });

        $alert = $this->sender()->send('k', '件名', '本文');

        $this->assertSame([1, 1], $seen);
        $this->assertNotNull($alert);
        $this->assertNotNull(AlertDelivery::first()->sent_at);
        $this->assertSame(1, AlertDelivery::first()->attempts);
    }

    public function test_failed_channel_is_retried_alone(): void
    {
        $this->channels(['mail', 'webhook']);
        Mail::fake();
        Http::fake(['hooks.example.test/*' => Http::sequence()->push('', 500)->push('', 200)]);

        $this->sender()->send('k', '件名', '本文');

        $webhook = AlertDelivery::where('channel', 'webhook')->first();
        $this->assertNull($webhook->sent_at);
        $this->assertNotNull(AlertDelivery::where('channel', 'mail')->first()->sent_at);

        $this->assertSame(1, $this->sender()->retry());

        $this->assertNotNull($webhook->fresh()->sent_at);
        Http::assertSentCount(2);
        Mail::assertSent(AlertMail::class, 1);
    }

    public function test_timeout_does_not_throw(): void
    {
        $this->channels(['webhook']);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://hooks.example.test/in'));

        $alert = $this->sender()->send('k', '件名', '本文');

        $this->assertNotNull($alert);
        $delivery = AlertDelivery::first();
        $this->assertNull($delivery->sent_at);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringNotContainsString('hooks.example.test', $delivery->last_error);
    }

    public function test_retry_is_capped_per_run(): void
    {
        $this->channels(['webhook']);
        Http::fake(['*' => Http::response('', 500)]);
        $this->pendingRows(30);

        $this->assertSame(20, $this->sender()->retry());

        Http::assertSentCount(20);
    }

    public function test_retry_rotates_by_last_attempt(): void
    {
        $this->channels(['webhook']);
        Http::fake(['*' => Http::response('', 500)]);
        $this->pendingRows(30);

        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->sender()->retry();
        Carbon::setTestNow('2026-11-15 10:01:00');
        $this->sender()->retry();

        $this->assertSame(0, AlertDelivery::where('attempts', 0)->count());
        $this->assertSame(10, AlertDelivery::where('attempts', 2)->count());
        $this->assertSame(20, AlertDelivery::where('attempts', 1)->count());
    }

    public function test_gives_up_after_max_attempts(): void
    {
        $this->channels(['webhook']);
        Http::fake();
        $this->pendingRows(1, 'webhook', 10);
        AlertDelivery::first()->update(['last_error' => 'HTTP 500']);

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once();
        $this->app->instance(ExceptionHandler::class, $handler);
        Log::spy();

        $this->assertSame(0, $this->sender()->retry());

        $this->assertNotNull(AlertDelivery::first()->abandoned_at);
        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn ($message, $context = []) => $message === 'foundation.alert.abandoned'
                && $context['key'] === 'k0' && $context['channel'] === 'webhook' && $context['error'] === 'HTTP 500'
        );

        // 閉じた行は二度と送らない
        $this->assertSame(0, $this->sender()->retry());
    }

    public function test_removed_channel_rows_are_abandoned(): void
    {
        $this->channels(['log']);
        Http::fake();
        $this->pendingRows(1, 'webhook');

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once();
        $this->app->instance(ExceptionHandler::class, $handler);

        $this->sender()->retry();

        $this->assertNotNull(AlertDelivery::first()->abandoned_at);
        Http::assertNothingSent();
    }

    public function test_log_line_is_written_once_even_after_retries(): void
    {
        $this->channels(['log', 'webhook']);
        Http::fake(['*' => Http::response('', 500)]);
        Log::spy();

        $this->sender()->send('k', '件名', '本文');
        $this->sender()->retry();
        $this->sender()->retry();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message) => str_starts_with($message, 'foundation.alert {')
        );
    }

    public function test_same_key_is_throttled_by_created_at(): void
    {
        $this->channels(['webhook']);
        Http::fake(['*' => Http::response('', 500)]);

        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->assertNotNull($this->sender()->send('k', 'a', 'b'));
        // 送れていなくても作らない
        $this->assertNull($this->sender()->send('k', 'a', 'b'));
        $this->assertNotNull($this->sender()->send('other', 'a', 'b'));
        $this->assertSame(2, Alert::count());

        Carbon::setTestNow('2026-11-15 11:01:00');
        $this->assertNotNull($this->sender()->send('k', 'a', 'b'));
        $this->assertSame(3, Alert::count());
    }

    public function test_log_channel_writes_single_line_with_marker(): void
    {
        $captured = null;
        Log::shouldReceive('warning')->once()->with(Mockery::on(function ($message) use (&$captured) {
            $captured = $message;

            return true;
        }));

        $alert = new Alert(['key' => 'k', 'subject' => '件名', 'body' => "1行目\n2行目\r\n3行目", 'created_at' => Carbon::parse('2026-11-15 10:00:00')]);
        (new LogChannel)->deliver($alert);

        $this->assertStringStartsWith('foundation.alert {', $captured);
        $this->assertStringNotContainsString("\n", $captured);
        $this->assertStringNotContainsString("\r", $captured);
        $data = json_decode(substr($captured, strlen('foundation.alert ')), true);
        $this->assertSame(['v', 'key', 'subject', 'body', 'app', 'at'], array_keys($data));
        $this->assertSame(1, $data['v']);
        $this->assertSame("1行目\n2行目\r\n3行目", $data['body']);
        $this->assertSame('TestApp', $data['app']);
        $this->assertSame('2026-11-15T10:00:00+00:00', $data['at']);
    }

    public function test_mail_is_sent_synchronously(): void
    {
        $this->channels(['mail']);
        Mail::fake();

        $this->sender()->send('k', '件名', '本文です');

        Mail::assertSent(AlertMail::class, function (AlertMail $mail) {
            return $mail->hasTo('ops@example.test') && $mail->hasSubject('件名')
                && str_contains($mail->render(), '本文です');
        });
        Mail::assertNothingQueued();
        $this->assertNotNull(AlertDelivery::first()->sent_at);
    }

    public function test_webhook_signature_matches_documented_format(): void
    {
        $this->channels(['webhook']);
        Carbon::setTestNow(Carbon::createFromTimestamp(1700000000));
        Http::fake();

        $this->sender()->send('k', '件名', '本文');

        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-Foundation-Timestamp')[0];
            $signature = $request->header('X-Foundation-Signature')[0];

            return $timestamp === '1700000000'
                && $signature === WebhookChannel::sign('secret', '1700000000', $request->body())
                && $signature === 'v1='.hash_hmac('sha256', '1700000000.'.$request->body(), 'secret')
                && json_decode($request->body(), true)['key'] === 'k';
        });
    }

    public function test_chatwork_and_slack_payloads(): void
    {
        $this->channels(['chatwork', 'slack']);
        Http::fake();

        $this->sender()->send('k', '件名[toall]', '本文');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.chatwork.com/v2/rooms/123/messages'
                && $request->header('X-ChatWorkToken')[0] === 'cw-token'
                && str_contains($request['body'], '件名')
                && str_contains($request['body'], '本文')
                && ! str_contains($request['body'], '[toall]');
        });
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://hooks.slack.test/T/B/X'
                && str_contains($request->data()['text'], '件名')
                && str_contains($request->data()['text'], '本文');
        });
        $this->assertSame(2, AlertDelivery::whereNotNull('sent_at')->count());
    }

    public function test_body_is_truncated_to_4000_chars(): void
    {
        $this->channels(['log']);
        Log::spy();

        $alert = $this->sender()->send('k', 's', str_repeat('あ', 5000));

        $this->assertSame(4000, mb_strlen($alert->body));
        $this->assertSame(4000, mb_strlen(Alert::first()->body));
    }

    public function test_write_failure_does_not_abort_outer_transaction(): void
    {
        // 意味を持つのは PostgreSQL だけ（MySQL は DDL が暗黙に確定してトランザクションの記録が崩れる）
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('savepoint の確かめは PostgreSQL の組み合わせだけ');
        }

        $this->channels(['log']);
        Log::spy();
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report');
        $this->app->instance(ExceptionHandler::class, $handler);
        Schema::dropIfExists('foundation_alert_deliveries');
        Schema::dropIfExists('foundation_alerts');

        DB::beginTransaction();
        $this->assertNull($this->sender()->send('k', 's', 'b'));
        // PostgreSQL では savepoint がないと、ここで current transaction is aborted になる
        $this->assertSame(1, (int) DB::selectOne('select 1 as one')->one);
        DB::rollBack();
    }
}
