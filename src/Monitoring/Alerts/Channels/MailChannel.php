<?php

namespace Miraqceed\Foundation\Monitoring\Alerts\Channels;

use Illuminate\Support\Facades\Mail;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\Channel;
use RuntimeException;

class MailChannel implements Channel
{
    public function name(): string
    {
        return 'mail';
    }

    public function deliver(Alert $alert): void
    {
        $to = config('foundation.monitoring.alerts.mail.to');
        if (empty($to)) {
            throw new RuntimeException('mail.to が設定されていません');
        }

        Mail::to($to)->sendNow(new AlertMail($alert->subject, $alert->body));
    }
}
