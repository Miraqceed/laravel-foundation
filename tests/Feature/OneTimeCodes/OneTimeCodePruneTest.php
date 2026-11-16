<?php

namespace Miraqceed\Foundation\Tests\Feature\OneTimeCodes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCode;
use Miraqceed\Foundation\Tests\Support\RefreshesOneTimeCodeDatabase;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 古い行を model:prune で消す。期限・使った時刻・失効の時刻のどれかが prune_days 日より前の行だけ
 */
class OneTimeCodePruneTest extends TestCase
{
    use RefreshesOneTimeCodeDatabase;

    private string $defaultTimezone;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.one_time_codes.enabled', true);
        $app['config']->set('foundation.one_time_codes.prune_days', 7);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultTimezone = date_default_timezone_get();
        // 時刻は UTC で持つ。アプリの時間帯（9時間東）に寄せて比べると、境目の近くの行を誤って消す
        config(['app.timezone' => 'Asia/Tokyo']);
        date_default_timezone_set('Asia/Tokyo');
        $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);

        parent::tearDown();
    }

    /** UTC で、今からの時間の差（時間）の時刻 */
    private static function hoursAgo(int $hours): string
    {
        return CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC')->subHours($hours)->format('Y-m-d H:i:s');
    }

    /** @param  array<string, ?string>  $times */
    private function row(string $name, array $times): void
    {
        DB::table('foundation_one_time_codes')->insert(array_merge([
            'kind' => 'token-claim',
            'code_hash' => hash('sha256', $name),
            'subject' => $name,
            'data' => null,
            'claimed_at' => null,
            'revoked_at' => null,
            'created_at' => self::hoursAgo(30 * 24),
        ], $times));
    }

    public function test_prune_removes_only_old_rows(): void
    {
        $this->row('expired-8-days', ['expires_at' => self::hoursAgo(8 * 24)]);
        $this->row('claimed-8-days', ['expires_at' => self::hoursAgo(1), 'claimed_at' => self::hoursAgo(8 * 24)]);
        $this->row('revoked-8-days', ['expires_at' => self::hoursAgo(1), 'revoked_at' => self::hoursAgo(8 * 24)]);
        $this->row('expired-6-days', ['expires_at' => self::hoursAgo(6 * 24)]);
        // 7日の境目から4時間あと（アプリの時間帯の9時間に寄せて比べると、誤って消える）
        $this->row('expired-6-days-20-hours', ['expires_at' => self::hoursAgo(7 * 24 - 4)]);
        $this->row('live', ['expires_at' => self::hoursAgo(-1)]);

        Artisan::call('model:prune', ['--model' => [OneTimeCode::class]]);

        $this->assertSame(
            ['expired-6-days', 'expired-6-days-20-hours', 'live'],
            DB::table('foundation_one_time_codes')->orderBy('subject')->pluck('subject')->all(),
        );
    }

    public function test_prune_days_below_one_is_treated_as_one(): void
    {
        config(['foundation.one_time_codes.prune_days' => 0]);
        $this->row('expired-2-days', ['expires_at' => self::hoursAgo(2 * 24)]);
        $this->row('expired-12-hours', ['expires_at' => self::hoursAgo(12)]);

        Artisan::call('model:prune', ['--model' => [OneTimeCode::class]]);

        $this->assertSame(['expired-12-hours'], DB::table('foundation_one_time_codes')->pluck('subject')->all());
    }
}
