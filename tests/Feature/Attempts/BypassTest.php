<?php

namespace Miraqceed\Foundation\Tests\Feature\Attempts;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\ConsecutiveLimit;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Login\LoginLock;
use Miraqceed\Foundation\Login\LoginLockedOut;
use Miraqceed\Foundation\Login\NormalizedEmailKey;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 診断の道具は、回数の制限で弾かれた要求を失敗とみなさず、認証の経路の検査が黙って抜ける。
 * 診断の間だけ数えずに照合する。production では効かせない（本番で外れたままにしない）
 */
class BypassTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('foundation.attempts.bypass', true);
    }

    private function attempts(): Attempts
    {
        return $this->app->make(Attempts::class);
    }

    private function inProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->environment('production'));
    }

    public function test_bypass_verifies_without_counting_outside_production(): void
    {
        $limit = new Limit('t:bypass', 1, 60);
        $consecutive = new ConsecutiveLimit('t:bypass-code', 1, 60);
        $lock = new LoginLock(new NormalizedEmailKey);
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $verified = 0;
        $verify = function () use (&$verified): bool {
            $verified++;

            return false;
        };

        for ($i = 0; $i < 30; $i++) {
            $this->attempts()->attempt([$limit], $verify);
            $this->attempts()->attemptConsecutive($consecutive, $verify);
            $lock->attempt($request, 'a@example.test', $verify);
        }

        $this->assertSame(90, $verified);
    }

    public function test_bypass_is_ignored_and_reported_in_production(): void
    {
        Exceptions::fake();
        $this->inProduction();
        $limit = new Limit('t:bypass', 1, 60);

        $this->attempts()->attempt([$limit], fn () => false);

        try {
            $this->attempts()->attempt([$limit], function (): bool {
                $this->fail('production では bypass を効かせない');
            });
            $this->fail('止まるはず');
        } catch (TooManyAttempts) {
        }
        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'foundation.attempts.bypass'));
    }

    public function test_bypass_is_ignored_in_production_for_consecutive_and_login(): void
    {
        Exceptions::fake();
        $this->inProduction();
        $consecutive = new ConsecutiveLimit('t:bypass-code', 1, 60);
        $this->attempts()->attemptConsecutive($consecutive, fn () => false);

        try {
            $this->attempts()->attemptConsecutive($consecutive, fn () => false);
            $this->fail('production では続けた失敗のロックも効く');
        } catch (TooManyAttempts) {
        }

        config(['foundation.login.account_max' => 1]);
        $lock = new LoginLock(new NormalizedEmailKey);
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $lock->attempt($request, 'a@example.test', fn () => false);
        $this->expectException(LoginLockedOut::class);
        $lock->attempt($request, 'a@example.test', fn () => false);
    }

    public function test_bypass_report_is_throttled(): void
    {
        Exceptions::fake();
        $this->inProduction();
        $limit = new Limit('t:bypass', 100, 60);

        for ($i = 0; $i < 5; $i++) {
            $this->attempts()->attempt([$limit], fn () => false);
        }
        Exceptions::assertReportedCount(1);

        // 1時間たてば、また知らせる（本番で外れたままなら、知らせ続ける）
        $this->travel(61)->minutes();
        $this->attempts()->attempt([$limit], fn () => false);
        Exceptions::assertReportedCount(2);
    }
}
