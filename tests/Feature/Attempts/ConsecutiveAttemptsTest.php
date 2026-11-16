<?php

namespace Miraqceed\Foundation\Tests\Feature\Attempts;

use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\ConsecutiveLimit;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 続けた失敗。時間で消えない数えで数え、上限でロックし、明けたら1回だけ照合する（窓のある数えでは、窓ごとに上限の手前まで試し続けられる）
 */
class ConsecutiveAttemptsTest extends TestCase
{
    private const KEY = 't:code';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
    }

    private function limit(): ConsecutiveLimit
    {
        return new ConsecutiveLimit(self::KEY, 3, 60);
    }

    private function attempts(): Attempts
    {
        return $this->app->make(Attempts::class);
    }

    private function fail3(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($this->attempts()->attemptConsecutive($this->limit(), fn () => false));
        }
    }

    private function assertRefusedWithoutVerifying(): TooManyAttempts
    {
        $caught = null;
        try {
            $this->attempts()->attemptConsecutive($this->limit(), function (): bool {
                $this->fail('ロック中に照合へ進んだ');
            });
        } catch (TooManyAttempts $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'ロック中なのに断られなかった');

        return $caught;
    }

    private function failureCount(): int
    {
        return RateLimiter::attempts(self::KEY.':count');
    }

    public function test_failures_do_not_expire_with_time(): void
    {
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);

        $this->travel(10)->days();
        $this->assertSame(2, $this->failureCount());

        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);
        $this->assertRefusedWithoutVerifying();
    }

    public function test_locks_when_the_count_reaches_the_limit_and_refuses_without_verifying(): void
    {
        $this->fail3();

        $this->assertRefusedWithoutVerifying();
        // 断った試みは数えない
        $this->assertSame(3, $this->failureCount());
    }

    public function test_after_the_lock_ends_only_one_attempt_is_verified_and_a_failure_locks_again(): void
    {
        $this->fail3();
        $this->travel(61)->seconds();

        $verified = 0;
        $this->assertFalse($this->attempts()->attemptConsecutive($this->limit(), function () use (&$verified): bool {
            $verified++;

            return false;
        }));

        $this->assertSame(1, $verified);
        $this->assertRefusedWithoutVerifying();
    }

    public function test_success_clears_the_count_and_the_lock(): void
    {
        $this->fail3();
        $this->travel(61)->seconds();

        $this->assertTrue($this->attempts()->attemptConsecutive($this->limit(), fn () => true));
        $this->assertSame(0, $this->failureCount());

        // 数えが空になったので、また上限の手前まで照合できる
        $this->assertFalse($this->attempts()->attemptConsecutive($this->limit(), fn () => false));
        $this->assertFalse($this->attempts()->attemptConsecutive($this->limit(), fn () => false));
        $verified = false;
        $this->attempts()->attemptConsecutive($this->limit(), function () use (&$verified): bool {
            $verified = true;

            return false;
        });
        $this->assertTrue($verified);
    }

    public function test_success_before_the_limit_clears_the_count(): void
    {
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);

        $this->assertTrue($this->attempts()->attemptConsecutive($this->limit(), fn () => true));
        $this->assertSame(0, $this->failureCount());
    }

    public function test_concurrent_attempts_at_the_limit_verify_only_once(): void
    {
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);

        $this->assertConcurrentAttemptsVerifyOnce();
    }

    public function test_concurrent_attempts_after_the_lock_ends_verify_only_once(): void
    {
        $this->fail3();
        $this->travel(61)->seconds();

        $this->assertConcurrentAttemptsVerifyOnce();
    }

    /**
     * 照合の途中で、同じ鍵の試みが割り込んで並ぶ形。割り込みは照合されず、数えにも残らない
     */
    private function assertConcurrentAttemptsVerifyOnce(): void
    {
        $attempts = $this->attempts();
        $limit = $this->limit();
        $before = $this->failureCount();
        $verified = 0;
        $stopped = 0;

        $verify = null;
        $verify = function () use (&$verify, &$verified, &$stopped, $attempts, $limit): bool {
            $verified++;
            if ($verified >= 10) {
                return false;
            }
            try {
                $attempts->attemptConsecutive($limit, $verify);
            } catch (TooManyAttempts) {
                $stopped++;
            }

            return false;
        };

        $attempts->attemptConsecutive($limit, $verify);

        $this->assertSame(1, $verified);
        // 割り込みが本当に起きて、止められたこと
        $this->assertSame(1, $stopped);
        $this->assertSame($before + 1, $this->failureCount());
    }

    /**
     * 2つの要求が、どちらもロックの印がないと読んだ後に、揃って印を立てようとする形。
     * 片方がロックの印を読んで「ない」と分かった所で、もう片方を最後まで走らせる（キャッシュの CacheMissed の知らせの中で）。
     * 印を add（原子的）で立てていれば、後の要求は立てられずに照合しない
     */
    public function test_racing_attempts_that_both_passed_the_lock_check_verify_only_once(): void
    {
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);

        $verified = 0;
        $raced = false;
        $attempts = $this->attempts();
        $limit = $this->limit();
        Event::listen(CacheMissed::class, function (CacheMissed $event) use (&$raced, &$verified, $attempts, $limit) {
            if ($raced || $event->key !== self::KEY.':lock') {
                return;
            }
            $raced = true;
            $attempts->attemptConsecutive($limit, function () use (&$verified): bool {
                $verified++;

                return false;
            });
        });

        try {
            $attempts->attemptConsecutive($limit, function () use (&$verified): bool {
                $verified++;

                return false;
            });
        } catch (TooManyAttempts) {
            // 後から印を立てようとした側は、照合せずに断られる
        }

        // 割り込みが本当に起きたこと
        $this->assertTrue($raced);
        $this->assertSame(1, $verified);
        // 照合しなかった側は数えない（前の2回 ＋ 照合した1回）
        $this->assertSame(3, $this->failureCount());
    }

    public function test_exception_before_the_limit_keeps_the_count(): void
    {
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->attempts()->attemptConsecutive($this->limit(), fn () => throw new \RuntimeException('落ちた'));
            } catch (\RuntimeException $e) {
                $this->assertSame('落ちた', $e->getMessage());
            }
        }

        $this->assertSame(2, $this->failureCount());
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);
        $this->assertRefusedWithoutVerifying();
    }

    public function test_exception_at_the_limit_keeps_the_lock_until_it_ends(): void
    {
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);
        $this->attempts()->attemptConsecutive($this->limit(), fn () => false);

        try {
            $this->attempts()->attemptConsecutive($this->limit(), fn () => throw new \RuntimeException('落ちた'));
            $this->fail('照合の例外はそのまま投げる');
        } catch (\RuntimeException $e) {
            $this->assertSame('落ちた', $e->getMessage());
        }

        // 例外でもロックは残る（例外を誘発して試みを重ねさせない）
        $this->assertRefusedWithoutVerifying();
        $this->travel(59)->seconds();
        $this->assertRefusedWithoutVerifying();

        // ロックの時間を過ぎると、印が消えて1回だけ照合できる（印が残り続けない）
        $this->travel(2)->seconds();
        $verified = false;
        $this->attempts()->attemptConsecutive($this->limit(), function () use (&$verified): bool {
            $verified = true;

            return false;
        });
        $this->assertTrue($verified);
    }

    public function test_retry_after_counts_down_to_the_end_of_the_lock(): void
    {
        $this->fail3();

        $this->assertSame(60, $this->assertRefusedWithoutVerifying()->retryAfter);
        $this->travel(20)->seconds();
        $this->assertSame(40, $this->assertRefusedWithoutVerifying()->retryAfter);
        $this->travel(39)->seconds();
        $this->assertSame(1, $this->assertRefusedWithoutVerifying()->retryAfter);
    }

    public function test_consecutive_does_not_change_windowed_limits(): void
    {
        $this->fail3();

        $this->assertTrue($this->attempts()->attempt([new Limit(self::KEY, 5, 60)], fn () => true));
    }

    public function test_default_limit_comes_from_config(): void
    {
        config(['foundation.attempts.consecutive' => ['max' => 4, 'lock_seconds' => 90]]);

        $limit = ConsecutiveLimit::fromConfig(self::KEY);

        $this->assertSame(4, $limit->max);
        $this->assertSame(90, $limit->lockSeconds);
    }
}
