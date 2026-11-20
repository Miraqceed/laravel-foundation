<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Session;
use Miraqceed\Foundation\DeployCheck\Checks\IpHashKey;
use Miraqceed\Foundation\DeployCheck\Checks\LimiterKeys;
use Miraqceed\Foundation\DeployCheck\Checks\SessionIp;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * session_ip・limiter_keys・ip_hash_key：生の IP を残さない設定の調べ。値は出さない
 */
class PrivacyChecksTest extends TestCase
{
    private const IP_HASH_KEY = 'ip-hash-key-for-deploy-check-tests-01234';

    private bool $sessionsWithoutIp = false;

    private bool $keyed = false;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session.driver', 'database');
        $app['config']->set('foundation.privacy.sessions_without_ip', $this->sessionsWithoutIp);
        $app['config']->set('foundation.privacy.keyed_rate_limiter', $this->keyed);
    }

    /** 設定を替えてアプリを作り直す（差し替えは起動のときに決まる） */
    private function boot(bool $sessionsWithoutIp = false, bool $keyed = false): void
    {
        $this->sessionsWithoutIp = $sessionsWithoutIp;
        $this->keyed = $keyed;
        $this->refreshApplication();
    }

    public function test_session_ip_is_skipped_when_sessions_are_not_in_the_database(): void
    {
        config(['session.driver' => 'file']);

        $this->assertSame(Status::Skip, (new SessionIp)->run()->status);
    }

    public function test_session_ip_is_info_when_laravel_writes_the_ip(): void
    {
        $result = (new SessionIp)->run();

        $this->assertSame(Status::Info, $result->status);
        $this->assertStringContainsString('sessions_without_ip', (string) $result->detail);
    }

    public function test_session_ip_is_ok_with_the_package_handler(): void
    {
        $this->boot(sessionsWithoutIp: true);

        $this->assertSame(Status::Ok, (new SessionIp)->run()->status);
    }

    public function test_session_ip_is_ok_with_the_apps_own_handler_without_ip(): void
    {
        Session::extend('database', fn ($app) => new AppHandlerWithoutIp($app['db']->connection(), 'sessions', 120, $app));

        $this->assertSame(Status::Ok, (new SessionIp)->run()->status);
    }

    public function test_limiter_keys_is_info_when_not_replaced(): void
    {
        $result = (new LimiterKeys)->run();

        $this->assertSame(Status::Info, $result->status);
        $this->assertStringContainsString('keyed_rate_limiter', (string) $result->detail);
    }

    public function test_limiter_keys_is_ok_when_replaced(): void
    {
        $this->boot(keyed: true);

        $this->assertSame(Status::Ok, (new LimiterKeys)->run()->status);
    }

    public function test_limiter_keys_fails_when_replaced_without_an_app_key(): void
    {
        $this->boot(keyed: true);
        config(['app.key' => '']);

        $result = (new LimiterKeys)->run();

        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString('APP_KEY', (string) $result->detail);
    }

    public function test_limiter_keys_fails_when_the_setting_is_on_but_the_limiter_is_not_replaced(): void
    {
        $this->boot(keyed: true);
        // アプリが RateLimiter を自分で結び付け直した
        $this->app->instance(CacheRateLimiter::class, new CacheRateLimiter($this->app['cache']->driver()));

        $this->assertSame(Status::Fail, (new LimiterKeys)->run()->status);
    }

    public function test_ip_hash_key_is_skipped_when_empty(): void
    {
        foreach ([null, ''] as $key) {
            config(['foundation.privacy.ip_hash_key' => $key]);

            $this->assertSame(Status::Skip, (new IpHashKey)->run()->status);
        }
    }

    public function test_ip_hash_key_fails_when_short_without_showing_it(): void
    {
        $short = substr(self::IP_HASH_KEY, 0, 31);
        config(['foundation.privacy.ip_hash_key' => $short]);

        $result = (new IpHashKey)->run();

        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringNotContainsString($short, (string) $result->detail);
    }

    public function test_ip_hash_key_is_ok_when_long_enough(): void
    {
        config(['foundation.privacy.ip_hash_key' => self::IP_HASH_KEY]);

        $result = (new IpHashKey)->run();

        $this->assertSame(Status::Ok, $result->status);
        $this->assertStringNotContainsString(self::IP_HASH_KEY, (string) $result->detail);
    }
}

/** アプリが自分で書いた、IP を書かない database の handler */
class AppHandlerWithoutIp extends DatabaseSessionHandler
{
    protected function ipAddress(): ?string
    {
        return null;
    }
}
