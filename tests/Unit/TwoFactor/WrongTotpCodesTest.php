<?php

namespace Miraqceed\Foundation\Tests\Unit\TwoFactor;

use Miraqceed\Foundation\Tests\Support\WrongTotpCodes;
use Miraqceed\Foundation\TwoFactor\Totp;
use PHPUnit\Framework\TestCase;

/**
 * 試しの「間違ったコード」を作る助け。受けられる刻み（窓と、時刻が進む分のゆとり 1 刻み）のどのコードとも重ならない
 */
class WrongTotpCodesTest extends TestCase
{
    private const TIME = 1795136415;

    /** 作り物の鍵。TIME の刻みの正しいコードが 000001 になる */
    private const SECRET_WHOSE_CODE_IS_000001 = 'HE6PXP5OFXRB5SLQZBFMUIQCKXARTVNF';

    public function test_skips_a_code_that_is_accepted_now(): void
    {
        // 前提：決め打ちの 000001 が、この鍵では正しいコード
        $this->assertSame('000001', Totp::codeAt(self::SECRET_WHOSE_CODE_IS_000001, self::TIME));

        $codes = WrongTotpCodes::for(self::SECRET_WHOSE_CODE_IS_000001, 3, 1, self::TIME);

        $this->assertSame(['000000', '000002', '000003'], $codes);
    }

    /** @return array<string, array{0: int}> */
    public static function windows(): array
    {
        return ['window 0' => [0], 'window 1' => [1], 'window 2' => [2]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('windows')]
    public function test_codes_are_distinct_and_never_accepted_within_one_step_of_drift(int $window): void
    {
        $secret = Totp::generateSecret();

        $codes = WrongTotpCodes::for($secret, 5, $window, self::TIME);

        $this->assertCount(5, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
            foreach ([-30, 0, 30] as $drift) {
                $this->assertNull(Totp::verify($secret, $code, self::TIME + $drift, null, $window));
            }
        }
    }

    /** 鍵と時刻が同じなら、同じコードを返す（落ちたときに同じ条件で流し直せる） */
    public function test_codes_are_the_same_for_the_same_secret_and_time(): void
    {
        $secret = Totp::generateSecret();

        $this->assertSame(WrongTotpCodes::for($secret, 3, 1, self::TIME), WrongTotpCodes::for($secret, 3, 1, self::TIME));
    }
}
