<?php

namespace Miraqceed\Foundation\Tests\Support;

use Miraqceed\Foundation\TwoFactor\Totp;

/**
 * 試しの「間違ったコード」。乱数の鍵に決め打ちのコード（000001 など）を当てると、
 * まれに（1 つのコードにつき 100 万分の 3 ほど）それが受けられる刻みのコードと重なり、試しがたまにだけ落ちる。
 * 受けられる刻み（今の刻みの前後 window 刻み）に、時刻が進む分のゆとりを前後 1 刻み足し、そのどのコードとも違うコードを、
 * 000000 から順に選ぶ（鍵と時刻が同じなら、同じコードになる）
 */
final class WrongTotpCodes
{
    /**
     * @param  int|null  $unixTime  null なら now()
     * @return list<string>
     */
    public static function for(string $secret, int $count, int $window = 1, ?int $unixTime = null): array
    {
        $current = intdiv($unixTime ?? now()->getTimestamp(), Totp::PERIOD_SECONDS);
        $accepted = [];
        for ($step = $current - $window - 1; $step <= $current + $window + 1; $step++) {
            $accepted[Totp::codeAt($secret, $step * Totp::PERIOD_SECONDS)] = true;
        }

        $codes = [];
        for ($n = 0; count($codes) < $count; $n++) {
            $code = sprintf('%0'.Totp::DIGITS.'d', $n);
            if (! isset($accepted[$code])) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public static function one(string $secret, int $window = 1, ?int $unixTime = null): string
    {
        return self::for($secret, 1, $window, $unixTime)[0];
    }
}
