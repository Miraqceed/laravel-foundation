<?php

namespace Miraqceed\Foundation\RequestId;

use Illuminate\Support\Facades\Context;

/**
 * 今の要求の id と相関の id（AssignRequestId が Context に入れたもの）。ジョブの中では、Context から戻った値を返す。
 * 中間を通っていない（コンソール・中間を足していない）ときは null。
 */
final class RequestId
{
    public const REQUEST_ID = 'request_id';

    public const CORRELATION_ID = 'correlation_id';

    public static function current(): ?string
    {
        return self::read(self::REQUEST_ID);
    }

    public static function correlation(): ?string
    {
        return self::read(self::CORRELATION_ID);
    }

    /**
     * 自分のサービスへ送る要求に付ける見出し（外の事業者には送らない）。id がなければ空
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return array_filter([
            'X-Request-Id' => self::current(),
            'X-Correlation-Id' => self::correlation(),
        ], fn (?string $value) => $value !== null);
    }

    private static function read(string $key): ?string
    {
        $value = Context::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
