<?php

namespace Miraqceed\Foundation\Health;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * foundation.health.checks に書いた確かめを流す DiagnosingHealth の聞き手。
 * 投げたらそのまま投げる（HealthCheck が受けて report() し、NG にする）。
 * checks が空でないときだけ登録する（入れただけでは何も見ない）。
 */
final class ConfiguredChecks
{
    public const CHECKS = ['database'];

    public function handle(DiagnosingHealth $event): void
    {
        foreach ((array) config('foundation.health.checks', []) as $check) {
            match ($check) {
                'database' => DB::connection()->select('select 1'),
                // 書き損じは起動で止めない（止めると本番の全部の要求が落ちる）。/up の NG で気付ける
                default => throw new InvalidArgumentException(sprintf(
                    'foundation.health.checks に知らない確かめ [%s] があります。使えるのは %s です。',
                    is_scalar($check) ? (string) $check : get_debug_type($check),
                    implode('・', self::CHECKS),
                )),
            };
        }
    }
}
