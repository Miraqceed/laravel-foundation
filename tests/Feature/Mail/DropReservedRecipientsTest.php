<?php

namespace Miraqceed\Foundation\Tests\Feature\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Miraqceed\Foundation\Tests\Support\PlainMailable;
use Miraqceed\Foundation\Tests\Support\PlainMailNotification;
use Miraqceed\Foundation\Tests\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class DropReservedRecipientsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'app@mail.foundation-test.jp', 'name' => 'App']);
        // キューに入れたメールも、このプロセスの中で送る（CI は database のキューで流すため）
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('foundation.mail.drop_reserved_recipients', true);
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    /** @param  list<Address>  $addresses
     *  @return list<string> */
    private static function addresses(array $addresses): array
    {
        return array_map(fn (Address $address) => $address->getAddress(), $addresses);
    }

    public function test_宛先がすべて予約なら送らない(): void
    {
        $sent = Mail::raw('本文', fn ($m) => $m->to(['e2e+1@example.invalid', 'taro@example.com'])->subject('確認'));

        $this->assertNull($sent);
        $this->assertCount(0, $this->sent());
    }

    public function test_実在する宛先には予約を除いて送る(): void
    {
        Mail::raw('本文', fn ($m) => $m->to(['someone@gmail.com', 'e2e+1@example.invalid'])->subject('確認'));

        $this->assertCount(1, $this->sent());
        $this->assertSame(['someone@gmail.com'], self::addresses($this->sent()->first()->getTo()));
    }

    public function test_Cc_と_Bcc_からも外す(): void
    {
        Mail::raw('本文', fn ($m) => $m->to('someone@gmail.com')
            ->cc(['cc@gmail.com', 'cc@example.net'])
            ->bcc(['bcc@foo.test', 'bcc@gmail.com'])
            ->subject('確認'));

        $email = $this->sent()->first();
        $this->assertSame(['someone@gmail.com'], self::addresses($email->getTo()));
        $this->assertSame(['cc@gmail.com'], self::addresses($email->getCc()));
        $this->assertSame(['bcc@gmail.com'], self::addresses($email->getBcc()));
    }

    public function test_To_が空になっても_Cc_に残れば送る(): void
    {
        Mail::raw('本文', fn ($m) => $m->to('taro@example.com')->cc('cc@gmail.com')->subject('確認'));

        $email = $this->sent()->first();
        $this->assertNotNull($email);
        $this->assertFalse($email->getHeaders()->has('To'));
        $this->assertSame(['cc@gmail.com'], self::addresses($email->getCc()));
    }

    public function test_外すものがなければ見出しを触らない(): void
    {
        Mail::raw('本文', fn ($m) => $m->to('someone@gmail.com', '山田')->subject('確認'));

        $this->assertSame('山田', $this->sent()->first()->getTo()[0]->getName());
    }

    public function test_無効なら予約にも送る(): void
    {
        config(['foundation.mail.drop_reserved_recipients' => false]);

        Mail::raw('本文', fn ($m) => $m->to('taro@example.com')->subject('確認'));

        $this->assertSame(['taro@example.com'], self::addresses($this->sent()->first()->getTo()));
    }

    public function test_実行中に有効にしても効く(): void
    {
        config(['foundation.mail.drop_reserved_recipients' => false]);
        Mail::raw('本文', fn ($m) => $m->to('taro@example.com')->subject('確認'));

        config(['foundation.mail.drop_reserved_recipients' => true]);
        Mail::raw('本文', fn ($m) => $m->to('taro@example.com')->subject('確認'));

        $this->assertCount(1, $this->sent());
    }

    public function test_Mailable・通知・キューでも外す(): void
    {
        Mail::to('taro@example.com')->send(new PlainMailable);
        Notification::route('mail', 'taro@example.com')->notifyNow(new PlainMailNotification);
        Mail::to('taro@example.com')->queue(new PlainMailable);

        $this->assertCount(0, $this->sent());

        // 実在する宛先へは、同じ経路で届く
        Mail::to('someone@gmail.com')->send(new PlainMailable);
        Notification::route('mail', 'someone@gmail.com')->notifyNow(new PlainMailNotification);
        Mail::to('someone@gmail.com')->queue(new PlainMailable);

        $this->assertCount(3, $this->sent());
    }

    public function test_後に続く聞き手も呼ばれる(): void
    {
        $seen = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$seen) {
            $seen[] = self::addresses($event->message->getTo());
        });

        Mail::raw('本文', fn ($m) => $m->to(['someone@gmail.com', 'taro@example.com'])->subject('確認'));

        $this->assertSame([['someone@gmail.com']], $seen);
        $this->assertCount(1, $this->sent());
    }

    public function test_偽のメーラーの下では差し込みは呼ばれない(): void
    {
        Mail::fake();

        Mail::to('taro@example.com')->send(new PlainMailable);

        Mail::assertSent(PlainMailable::class, fn (PlainMailable $mail) => $mail->hasTo('taro@example.com'));
    }
}
