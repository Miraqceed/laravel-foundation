<?php

namespace Miraqceed\Foundation\Tests\Feature\OneTimeCodes;

use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\OneTimeCodes\CodeKind;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCodes;
use Miraqceed\Foundation\Tests\Support\RefreshesOneTimeCodeDatabase;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 並びを決めた同時の受け取り：受け取り A の最初の問い合わせの直後に、同じ合言葉の受け取り B を割り込ませる。
 * 「読んで判断してから、条件なしで印を書く」形だと、A も B も通る。SQLite でも流れる
 */
class ClaimRaceTest extends TestCase
{
    use RefreshesOneTimeCodeDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.one_time_codes.enabled', true);
    }

    public function test_a_claim_interleaved_after_the_first_query_does_not_succeed_twice(): void
    {
        $codes = $this->app->make(OneTimeCodes::class);
        $kind = new CodeKind('token-claim', 'apc_');
        $issued = $codes->issue($kind);

        $interleaved = null;
        $armed = true;
        DB::listen(function () use (&$armed, &$interleaved, $codes, $kind, $issued): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            $interleaved = $codes->claim($kind, $issued->code);
        });

        $first = $codes->claim($kind, $issued->code);

        $this->assertFalse($armed, '割り込ませられなかった');
        $this->assertSame(1, (int) ($first !== null) + (int) ($interleaved !== null));
    }
}
