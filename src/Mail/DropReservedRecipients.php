<?php

namespace Miraqceed\Foundation\Mail;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * 送る直前に、実在しないことが決まっている宛先（ReservedEmailDomains）を To・Cc・Bcc から外す。
 * 宛先が1つも残らなければ、送信そのものを取りやめる（Mail::send() は null を返す）。
 *
 * foundation.mail.drop_reserved_recipients が真のときだけ働く。聞き手はいつも登録し、送るときに設定を読む
 * （試しやミドルウェアで config() を変えても効く）。
 * 外した宛先はログに書かない（E2E の利用者などで毎回出て、ほかのログを埋もれさせるため）。
 */
final class DropReservedRecipients
{
    private const HEADERS = ['To' => 'to', 'Cc' => 'cc', 'Bcc' => 'bcc'];

    public function handle(MessageSending $event): false|null
    {
        if (! config('foundation.mail.drop_reserved_recipients')) {
            return null;
        }

        $email = $event->message;
        // 宛先の API を持たない Message の直の形は触らない
        if (! $email instanceof Email) {
            return null;
        }

        $remaining = 0;
        foreach (self::HEADERS as $header => $setter) {
            $addresses = $email->{'get'.$header}();
            $kept = array_values(array_filter(
                $addresses,
                fn (Address $address) => ! ReservedEmailDomains::isReserved($address->getAddress()),
            ));
            $remaining += count($kept);

            // 何も外さなければ見出しを書き直さない
            if (count($kept) === count($addresses)) {
                continue;
            }

            if ($kept === []) {
                // 空の見出しを残さない（To だけが空で Cc が残るときなど）
                $email->getHeaders()->remove($header);
            } else {
                $email->{$setter}(...$kept);
            }
        }

        // null を返して、後に続くほかの聞き手も動かす。false は送る相手がいないときだけ
        return $remaining > 0 ? null : false;
    }
}
