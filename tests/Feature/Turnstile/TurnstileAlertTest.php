<?php

namespace Miraqceed\Foundation\Tests\Feature\Turnstile;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Tests\TestCase;
use Miraqceed\Foundation\Turnstile\Turnstile;

/**
 * 見張りが有効なら、設定の誤りだけを知らせる（届かないときは知らせない）。知らせに秘密を入れない
 */
class TurnstileAlertTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = '0x4AAAAAAAsecret-for-tests-only';

    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private string $throttleDir;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log']);
        $app['config']->set('foundation.monitoring.external', []);
        $app['config']->set('foundation.monitoring.heartbeat_url', null);
        $app['config']->set('services.turnstile.secret_key', self::SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->throttleDir = sys_get_temp_dir().'/foundation-turnstile-'.bin2hex(random_bytes(6));
        config(['foundation.failures.throttle_path' => $this->throttleDir]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->throttleDir);

        parent::tearDown();
    }

    public function test_設定の誤りを知らせ_秘密を入れない(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']])]);

        $this->assertFalse($this->app->make(Turnstile::class)->passes('XXXX.TOKEN'));

        $alert = DB::table('foundation_alerts')->where('key', 'foundation.turnstile:misconfigured')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('invalid-input-secret', (string) $alert->body);
        $this->assertStringNotContainsString(self::SECRET, (string) $alert->body);
        $this->assertStringNotContainsString('XXXX.TOKEN', (string) $alert->body);
    }

    public function test_届かないときは知らせない(): void
    {
        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('timed out')]);

        $this->assertFalse($this->app->make(Turnstile::class)->passes('XXXX.TOKEN'));

        $this->assertSame(0, DB::table('foundation_alerts')->count());
    }
}
