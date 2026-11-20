<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\Privacy\IpHash;

/**
 * DB に残す IP のハッシュ（Privacy\IpHash）の鍵 foundation.privacy.ip_hash_key。値は出さない。
 *
 * - 空なら skip（IpHash を使っていない。使えば呼んだところで止まる）
 * - 32字より短ければ fail（IpHash が投げる）
 */
final class IpHashKey implements Check
{
    public function id(): string
    {
        return 'ip_hash_key';
    }

    public function label(): string
    {
        return 'IP のハッシュの鍵が '.IpHash::MIN_KEY_LENGTH.' 字以上（使うときだけ）';
    }

    public function run(): Result
    {
        $key = config('foundation.privacy.ip_hash_key');

        if ($key === null || $key === '') {
            return Result::skip('鍵がない（IpHash を使っていない）');
        }

        if (! is_string($key) || strlen($key) < IpHash::MIN_KEY_LENGTH) {
            return Result::fail('foundation.privacy.ip_hash_key（FOUNDATION_IP_HASH_KEY）が '.IpHash::MIN_KEY_LENGTH.' 字より短い');
        }

        return Result::ok();
    }
}
