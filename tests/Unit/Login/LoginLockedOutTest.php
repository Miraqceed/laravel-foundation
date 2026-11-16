<?php

namespace Miraqceed\Foundation\Tests\Unit\Login;

use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Login\LoginLockedOut;
use PHPUnit\Framework\TestCase;

/**
 * LoginLockedOut の公開の形（new LoginLockedOut(int)・$e->retryAfter・文）は、試みの数えの例外の子になっても変わらない。
 * retryAfter は親（TooManyAttempts）の readonly の昇格した引数で、子の構築子から親の構築子で初めて入れる
 */
class LoginLockedOutTest extends TestCase
{
    public function test_public_shape_is_unchanged(): void
    {
        $e = new LoginLockedOut(30);

        $this->assertSame(30, $e->retryAfter);
        $this->assertSame('ログインの失敗が上限に達しました。', $e->getMessage());
        $this->assertInstanceOf(TooManyAttempts::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function test_retry_after_is_readonly(): void
    {
        $e = new LoginLockedOut(30);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('readonly');

        (function () use ($e) {
            $e->retryAfter = 1;
        })->call($e);
    }
}
