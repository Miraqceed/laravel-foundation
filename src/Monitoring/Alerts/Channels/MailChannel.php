<?php

namespace Miraqceed\Foundation\Monitoring\Alerts\Channels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Miraqceed\Foundation\Mail\ReservedEmailDomains;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\Channel;
use RuntimeException;

class MailChannel implements Channel
{
    public function deliver(Alert $alert): void
    {
        $to = config('foundation.monitoring.alerts.mail.to');
        if (empty($to)) {
            throw new RuntimeException('mail.to が設定されていません');
        }

        // 実在しないことが決まっている宛先は外す。送っても届かないので、送れたと記録しない。
        // drop_reserved_recipients によらず確かめる（偽でも、送れば宛先不明の戻りが残る）
        // 判定するのは文字列の宛先だけ。名前付きの宛先（['email' => …, 'name' => …]）や物は、判定せずにそのまま残す
        $reachable = [];
        $dropped = [];
        foreach ((array) $to as $address) {
            if (is_string($address) && ReservedEmailDomains::isReserved($address)) {
                $dropped[] = $address;
            } else {
                $reachable[] = $address;
            }
        }

        if ($reachable === []) {
            throw new RuntimeException('宛先 '.implode(', ', $dropped).' にはメールを届けられません（実在しないことが決まっているドメイン）');
        }

        // 一部だけ外したときは、設定の書き損じに気付けるよう1行残す（運営が書いた設定の値で、利用者の個人情報ではない）
        if ($dropped !== []) {
            Log::warning('foundation.monitoring.alerts.mail.to のうち、メールを届けられない宛先を外して送りました: '.implode(', ', $dropped));
        }

        // sendNow() の戻り値は見ない（偽のメーラーは null を返す）
        Mail::to($reachable)->sendNow(new AlertMail($alert->subject, $alert->body));
    }
}
