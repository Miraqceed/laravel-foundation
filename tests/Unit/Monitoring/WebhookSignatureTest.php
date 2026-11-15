<?php

namespace Miraqceed\Foundation\Tests\Unit\Monitoring;

use Miraqceed\Foundation\Monitoring\Alerts\Channels\WebhookChannel;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    /** docs に載せる例の値。受け取る側の実装はこの例で同じ署名になることを確かめる */
    public function test_signature_matches_documented_example(): void
    {
        $signature = WebhookChannel::sign('secret', '1700000000', '{"v":1}');

        $this->assertSame('v1=8a547bfd2adcd07f78c08448d60b727dd145789fe26971db4ead66af709bb87e', $signature);
        $this->assertSame('v1='.hash_hmac('sha256', '1700000000.{"v":1}', 'secret'), $signature);
    }
}
