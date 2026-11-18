<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Miraqceed\Foundation\Monitoring\Alerts\AlertDelivery;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\AlertMail;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 知らせのメールは、実在しないことが決まっている宛先を外して送る。残りがなければ送れなかったと記録する。
 * 宛先の example-ops.zz は、試しのために届くとみなされる（予約の名前の一覧に入らない）ドメイン（.zz は割り当てのないトップレベル。外へは送らない）。
 */
class MailChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['mail']);
    }

    private function send(string|array $to): AlertDelivery
    {
        config()->set('foundation.monitoring.alerts.mail.to', $to);
        app(AlertSender::class)->send('k', '件名', '本文です');

        return AlertDelivery::sole();
    }

    public function test_届かない宛先は送れなかったと記録する(): void
    {
        Mail::fake();

        $delivery = $this->send('ops@example.com');

        Mail::assertNothingSent();
        $this->assertNull($delivery->sent_at);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('ops@example.com', $delivery->last_error);
    }

    public function test_差し込みが無効でも届かない宛先は送れなかったとする(): void
    {
        config()->set('foundation.mail.drop_reserved_recipients', false);
        Mail::fake();

        $this->assertNull($this->send(['ops@foo.test'])->sent_at);
        Mail::assertNothingSent();
    }

    public function test_実在する宛先は偽のメーラーでも送れたと記録する(): void
    {
        Mail::fake();

        $this->assertNotNull($this->send('ops@example-ops.zz')->sent_at);
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo('ops@example-ops.zz'));
    }

    public function test_一部だけ届かない宛先なら残りへ送って警告する(): void
    {
        Mail::fake();
        Log::spy();

        $delivery = $this->send(['ops@example-ops.zz', 'ops@example.com']);

        $this->assertNotNull($delivery->sent_at);
        Mail::assertSent(AlertMail::class, 1);
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo('ops@example-ops.zz') && ! $mail->hasTo('ops@example.com'));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'ops@example.com'));
    }

    public function test_名前付きの宛先は判定せずに送る(): void
    {
        Mail::fake();
        Log::spy();

        $delivery = $this->send([['email' => 'ops@example-ops.zz', 'name' => '運営'], 'ops@example.com']);

        $this->assertNotNull($delivery->sent_at);
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo('ops@example-ops.zz', '運営') && ! $mail->hasTo('ops@example.com'));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'ops@example.com'));
    }

    public function test_名前付きの宛先だけでも送れたと記録する(): void
    {
        Mail::fake();

        $this->assertNotNull($this->send([['email' => 'ops@example-ops.zz', 'name' => '運営']])->sent_at);
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo('ops@example-ops.zz', '運営'));
    }

    public function test_宛先がすべて届かなければ投げる(): void
    {
        Mail::fake();

        $delivery = $this->send(['ops@example.com', 'ops@foo.test']);

        Mail::assertNothingSent();
        $this->assertNull($delivery->sent_at);
        $this->assertStringContainsString('RuntimeException', $delivery->last_error);
    }
}
