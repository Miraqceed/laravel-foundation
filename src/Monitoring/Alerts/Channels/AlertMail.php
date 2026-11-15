<?php

namespace Miraqceed\Foundation\Monitoring\Alerts\Channels;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** キューに入れない（ShouldQueue を持たない）。失敗が例外にならないのを避ける */
class AlertMail extends Mailable
{
    public function __construct(private readonly string $alertSubject, private readonly string $alertBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div style="white-space:pre-wrap">'.e($this->alertBody).'</div>');
    }
}
