<?php

namespace Miraqceed\Foundation\Tests\Feature\Mail;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as Notifications;
use InvalidArgumentException;
use Miraqceed\Foundation\Mail\AutomatedMessage;
use Miraqceed\Foundation\Tests\Support\PlainMailable;
use Miraqceed\Foundation\Tests\TestCase;
use Symfony\Component\Mime\Email;

/**
 * 自動のメールの見出し：mark() と、すべての送るメールに Auto-Submitted を足す設定（既定は偽）
 */
class AutomatedMessageTest extends TestCase
{
    /** @var list<Email> 送ったメール */
    private array $sent = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.url', 'https://app.example.com');
        $app['config']->set('mail.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event->message);
    }

    private function headerOf(Email $email, string $name): ?string
    {
        return $email->getHeaders()->get($name)?->getBodyAsString();
    }

    public function test_mark_は_Auto_Submitted_と_APP_URL_のホストの_Message_ID_を付ける(): void
    {
        $email = AutomatedMessage::mark(new Email, 'notification.01JD7Z');

        $this->assertSame('auto-generated', $this->headerOf($email, 'Auto-Submitted'));
        $this->assertSame('<notification.01JD7Z@app.example.com>', $this->headerOf($email, 'Message-ID'));
    }

    public function test_id_を渡さなければ_Message_ID_は付けない_既にある_Auto_Submitted_は変えない(): void
    {
        $email = new Email;
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');

        AutomatedMessage::mark($email);

        $this->assertSame('auto-replied', $this->headerOf($email, 'Auto-Submitted'));
        $this->assertNull($email->getHeaders()->get('Message-ID'));
    }

    public function test_送り直しても同じ_Message_ID(): void
    {
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject('お知らせ')->line('本文')
                    ->withSymfonyMessage(fn (Email $email) => AutomatedMessage::mark($email, 'notification.42'));
            }
        };

        Notifications::route('mail', 'taro@example-recipient.zz')->notifyNow($notification);
        Notifications::route('mail', 'taro@example-recipient.zz')->notifyNow($notification);

        $this->assertCount(2, $this->sent);
        $this->assertSame('<notification.42@app.example.com>', $this->headerOf($this->sent[0], 'Message-ID'));
        $this->assertSame($this->headerOf($this->sent[0], 'Message-ID'), $this->headerOf($this->sent[1], 'Message-ID'));
        $this->assertSame('auto-generated', $this->headerOf($this->sent[1], 'Auto-Submitted'));
    }

    public function test_ホストは要求の_Host_でなく_APP_URL_から(): void
    {
        $this->app['request']->headers->set('Host', 'evil.example.net');

        $this->assertSame('<n.1@app.example.com>', $this->headerOf(AutomatedMessage::mark(new Email, 'n.1'), 'Message-ID'));
    }

    public function test_日本語のホストは_xn_の形(): void
    {
        config(['app.url' => 'https://例え.example']);

        $this->assertSame('<n.1@xn--r8jz45g.example>', $this->headerOf(AutomatedMessage::mark(new Email, 'n.1'), 'Message-ID'));
    }

    public function test_id_の形が違えば投げる(): void
    {
        foreach (['', "a\r\nBcc: x@y", 'a@b', 'a b', '.a', str_repeat('a', 129), 'あ'] as $id) {
            try {
                AutomatedMessage::mark(new Email, $id);
                $this->fail('投げなかった: '.json_encode($id));
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('Bcc', $e->getMessage());
            }
        }
    }

    public function test_APP_URL_のホストが取れなければ投げる(): void
    {
        config(['app.url' => '']);

        $this->expectException(InvalidArgumentException::class);
        AutomatedMessage::mark(new Email, 'n.1');
    }

    public function test_設定が偽なら_Auto_Submitted_を足さない(): void
    {
        Mail::raw('本文', fn ($message) => $message->to('taro@example-recipient.zz')->subject('件名'));

        $this->assertNull($this->sent[0]->getHeaders()->get('Auto-Submitted'));
    }

    public function test_設定が真なら_すべての送るメールに足す(): void
    {
        config(['foundation.mail.auto_submitted' => true]);

        Mail::raw('本文', fn ($message) => $message->to('taro@example-recipient.zz')->subject('件名'));
        Mail::to('taro@example-recipient.zz')->send(new PlainMailable);
        Notifications::route('mail', 'taro@example-recipient.zz')->notifyNow(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject('お知らせ')->line('本文');
            }
        });

        $this->assertCount(3, $this->sent);
        foreach ($this->sent as $email) {
            $this->assertSame('auto-generated', $this->headerOf($email, 'Auto-Submitted'));
        }
    }

    public function test_設定が真でも_既にある_Auto_Submitted_は変えず_ほかの聞き手と送信を止めない(): void
    {
        config(['foundation.mail.auto_submitted' => true, 'foundation.mail.drop_reserved_recipients' => true]);

        Mail::raw('本文', function ($message) {
            $message->to('taro@example-recipient.zz')->subject('件名');
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
        });
        // 予約の宛先だけのメールは、差し込みが止める（聞き手の並びで止まらない）
        Mail::raw('本文', fn ($message) => $message->to('taro@example.com')->subject('件名'));

        $this->assertCount(1, $this->sent);
        $this->assertSame('auto-replied', $this->headerOf($this->sent[0], 'Auto-Submitted'));
    }
}
