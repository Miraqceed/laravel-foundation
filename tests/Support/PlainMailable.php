<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Mail\Mailable;

class PlainMailable extends Mailable
{
    public function build(): static
    {
        return $this->subject('確認')->html('<p>本文</p>');
    }
}
