<?php

namespace Miraqceed\Foundation\Tests\Feature\OneTimeCodes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\OneTimeCodes\CodeKind;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCodes;
use Miraqceed\Foundation\Tests\Support\RefreshesOneTimeCodeDatabase;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 時刻は UTC で書いて UTC で比べる。アプリの時間帯を替えても、期限は動かない
 */
class OneTimeCodesTimeTest extends TestCase
{
    use RefreshesOneTimeCodeDatabase;

    private string $defaultTimezone;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.one_time_codes.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);

        parent::tearDown();
    }

    /** アプリの時間帯を替える（配置で APP_TIMEZONE を替えたときと同じく、PHP の既定の時間帯も替わる） */
    private function useAppTimezone(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }

    private function travelToUtc(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, 'UTC'));
    }

    public function test_expiry_is_kept_in_utc_when_the_app_timezone_changes(): void
    {
        $codes = $this->app->make(OneTimeCodes::class);
        $kind = new CodeKind('token-claim', 'apc_');

        $this->useAppTimezone('Asia/Tokyo');
        $this->travelToUtc('2026-03-01 12:00:00');
        $before = $codes->issue($kind);
        $atExpiry = $codes->issue($kind);

        // 表には UTC で書く
        $this->assertSame('2026-03-01 12:10:00', substr((string) DB::table('foundation_one_time_codes')->value('expires_at'), 0, 19));

        $this->useAppTimezone('America/New_York');
        $this->travelToUtc('2026-03-01 12:09:59');
        $this->assertNotNull($codes->claim($kind, $before->code));
        $this->travelToUtc('2026-03-01 12:10:00');
        $this->assertNull($codes->claim($kind, $atExpiry->code));
    }

    public function test_returned_times_are_in_the_app_timezone(): void
    {
        $codes = $this->app->make(OneTimeCodes::class);
        $kind = new CodeKind('token-claim', 'apc_');

        $this->useAppTimezone('Asia/Tokyo');
        $this->travelToUtc('2026-03-01 12:00:00');
        $issued = $codes->issue($kind);
        $claimed = $codes->claim($kind, $issued->code);

        $this->assertSame('2026-03-01 21:10:00 +09:00', $issued->expiresAt->format('Y-m-d H:i:s P'));
        $this->assertSame('2026-03-01 21:00:00 +09:00', $claimed?->issuedAt->format('Y-m-d H:i:s P'));
    }
}
