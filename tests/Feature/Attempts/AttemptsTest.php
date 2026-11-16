<?php

namespace Miraqceed\Foundation\Tests\Feature\Attempts;

use Illuminate\Support\Facades\RateLimiter;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\Counting;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 窓のある数え。照合の前に1回分を足し、足した後の数で照合に進むかを決める
 */
class AttemptsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
    }

    private function attempts(): Attempts
    {
        return $this->app->make(Attempts::class);
    }

    private function refuse(): \Closure
    {
        return function (): bool {
            $this->fail('上限に達しているのに照合へ進んだ');
        };
    }

    public function test_refuses_without_verifying_when_any_limit_is_full(): void
    {
        $own = new Limit('t:own', 2, 60);
        $shared = new Limit('t:shared', 5, 60, Counting::FailuresShared);

        $this->attempts()->attempt([$own, $shared], fn () => false);
        $this->attempts()->attempt([$own, $shared], fn () => false);

        $this->expectException(TooManyAttempts::class);
        $this->attempts()->attempt([$own, $shared], $this->refuse());
    }

    public function test_concurrent_attempts_cannot_exceed_the_limit(): void
    {
        $attempts = $this->attempts();
        $limit = new Limit('t:race', 3, 60);
        $verified = 0;
        $stopped = 0;

        // 照合の途中で、同じ枠の試みが割り込んで並ぶ形（照合の中で次の試みを起こす）
        $verify = null;
        $verify = function () use (&$verify, &$verified, &$stopped, $attempts, $limit): bool {
            $verified++;
            // 守りが効かないときに果てしなく割り込まないよう、10段で止める（守りが効けば 4段目で止まる）
            if ($verified >= 10) {
                return false;
            }
            try {
                $attempts->attempt([$limit], $verify);
            } catch (TooManyAttempts) {
                $stopped++;
            }

            return false;
        };

        $attempts->attempt([$limit], $verify);

        $this->assertSame(3, $verified);
        // 割り込みが本当に起きて、止められたこと（起きていなければ、守りがなくても緑になる）
        $this->assertSame(1, $stopped);
        $this->assertSame(3, RateLimiter::attempts('t:race'));
    }

    public function test_over_limit_attempt_does_not_inflate_the_count(): void
    {
        $limit = new Limit('t:inflate', 1, 60);
        $this->attempts()->attempt([$limit], fn () => false);

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->attempts()->attempt([$limit], $this->refuse());
            } catch (TooManyAttempts) {
            }
        }

        $this->assertSame(1, RateLimiter::attempts('t:inflate'));
    }

    public function test_failures_own_is_cleared_on_success(): void
    {
        $limit = new Limit('t:own', 5, 60);
        $this->attempts()->attempt([$limit], fn () => false);
        $this->attempts()->attempt([$limit], fn () => false);

        $this->assertTrue($this->attempts()->attempt([$limit], fn () => true));
        $this->assertSame(0, RateLimiter::attempts('t:own'));
    }

    public function test_failures_shared_releases_only_this_attempt_on_success(): void
    {
        $limit = new Limit('t:shared', 5, 60, Counting::FailuresShared);
        $this->attempts()->attempt([$limit], fn () => false);
        $this->attempts()->attempt([$limit], fn () => false);

        $this->attempts()->attempt([$limit], fn () => true);

        $this->assertSame(2, RateLimiter::attempts('t:shared'));
    }

    public function test_every_is_kept_on_success(): void
    {
        $limit = new Limit('t:every', 5, 60, Counting::Every);
        $this->attempts()->attempt([$limit], fn () => true);
        $this->attempts()->attempt([$limit], fn () => true);

        $this->assertSame(2, RateLimiter::attempts('t:every'));
    }

    public function test_shared_release_does_not_clear_own_limit_and_own_clear_does_not_touch_shared(): void
    {
        $own = new Limit('t:own', 5, 60);
        $shared = new Limit('t:shared', 5, 60, Counting::FailuresShared);
        $other = new Limit('t:other', 5, 60);
        $this->attempts()->attempt([$own, $shared], fn () => false);
        $this->attempts()->attempt([$other], fn () => false);

        $this->attempts()->attempt([$own, $shared], fn () => true);

        $this->assertSame(0, RateLimiter::attempts('t:own'));
        $this->assertSame(1, RateLimiter::attempts('t:shared'));
        $this->assertSame(1, RateLimiter::attempts('t:other'));
    }

    public function test_failure_is_kept_when_verify_throws(): void
    {
        $limit = new Limit('t:throws', 5, 60);

        try {
            $this->attempts()->attempt([$limit], fn () => throw new \RuntimeException('DB が落ちた'));
            $this->fail('照合の例外はそのまま投げる');
        } catch (\RuntimeException $e) {
            $this->assertSame('DB が落ちた', $e->getMessage());
        }

        $this->assertSame(1, RateLimiter::attempts('t:throws'));
    }

    public function test_release_after_the_window_ends_does_nothing(): void
    {
        $shared = new Limit('t:window', 5, 60, Counting::FailuresShared);
        $attempts = $this->attempts();

        $attempts->attempt([$shared], function (): bool {
            // 照合の間に窓が終わる
            $this->travel(61)->seconds();
            $this->assertSame(0, RateLimiter::availableIn('t:window'));

            return true;
        });

        // 終わった窓を decrement で作り直していない（作り直すと -1 から始まる窓ができる）
        $this->assertSame(0, RateLimiter::attempts('t:window'));
        $attempts->attempt([$shared], fn () => false);
        $this->assertSame(1, RateLimiter::attempts('t:window'));
    }

    public function test_release_never_leaves_a_negative_count(): void
    {
        $shared = new Limit('t:negative', 5, 60, Counting::FailuresShared);

        $this->attempts()->attempt([$shared], function (): bool {
            // 並んだ別の成功が、先に枠を空にした形
            RateLimiter::clear('t:negative');
            RateLimiter::hit('t:negative', 60);
            RateLimiter::decrement('t:negative');

            return true;
        });

        $this->assertGreaterThanOrEqual(0, RateLimiter::attempts('t:negative'));
    }

    public function test_retry_after_is_at_least_one(): void
    {
        $limit = new Limit('t:retry', 1, 60);
        $this->attempts()->attempt([$limit], fn () => false);

        try {
            $this->attempts()->attempt([$limit], $this->refuse());
            $this->fail('止まるはず');
        } catch (TooManyAttempts $e) {
            $this->assertGreaterThanOrEqual(1, $e->retryAfter);
            $this->assertLessThanOrEqual(60, $e->retryAfter);
        }
    }
}
