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

    public function test_本番でテスト用の秘密の鍵なら_success_でも断り_知らせる(): void
    {
        // テスト用の 1x の秘密の鍵には、Cloudflare がいつも success を返す。action も hostnames も決めていない
        $secret = '1x0000000000000000000000000000000AA';
        config(['services.turnstile.secret_key' => $secret]);
        $this->app['env'] = 'production';
        Http::fake([self::ENDPOINT => Http::response(['success' => true, 'hostname' => 'example.com'])]);

        $this->assertFalse($this->app->make(Turnstile::class)->passes('XXXX.TOKEN'));

        Http::assertNothingSent();
        $alerts = DB::table('foundation_alerts')->where('key', 'foundation.turnstile:misconfigured')->get();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('秘密の鍵が Cloudflare のテスト用', (string) $alerts[0]->body);
        $this->assertStringNotContainsString($secret, (string) $alerts[0]->body);
    }

    public function test_本番でなければテスト用の秘密の鍵で通し_知らせない(): void
    {
        config(['services.turnstile.secret_key' => '1x0000000000000000000000000000000AA']);
        $this->app['env'] = 'local';
        Http::fake([self::ENDPOINT => Http::response(['success' => true, 'hostname' => 'example.com'])]);

        $this->assertTrue($this->app->make(Turnstile::class)->passes('XXXX.TOKEN'));

        $this->assertSame(0, DB::table('foundation_alerts')->count());
    }

    public function test_届かないときは知らせない(): void
    {
        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('timed out')]);

        $this->assertFalse($this->app->make(Turnstile::class)->passes('XXXX.TOKEN'));

        $this->assertSame(0, DB::table('foundation_alerts')->count());
    }
}
