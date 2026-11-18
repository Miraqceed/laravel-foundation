<?php

namespace Miraqceed\Foundation\Mail;

use Illuminate\Mail\Events\MessageSending;

/**
 * 送る直前に、すべてのメールへ Auto-Submitted: auto-generated を足す（既にあれば変えない）。
 *
 * foundation.mail.auto_submitted が真のときだけ働く。聞き手はいつも登録し、送るときに設定を読む（DropReservedRecipients と同じ）。
 * 人が書いて人に送るメール（問い合わせの転送・利用者どうしのメッセージ）を送るアプリは、偽のまま AutomatedMessage::mark() を
 * 自動のメールにだけ使う。
 * いつも null を返す（Laravel は MessageSending を until で出すので、null でないと後の聞き手が呼ばれない・送信が止まる）
 */
final class MarkAutoSubmitted
{
    public function handle(MessageSending $event): null
    {
        if (! config('foundation.mail.auto_submitted')) {
            return null;
        }

        $headers = $event->message->getHeaders();
        if (! $headers->has('Auto-Submitted')) {
            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
        }

        return null;
    }
}
