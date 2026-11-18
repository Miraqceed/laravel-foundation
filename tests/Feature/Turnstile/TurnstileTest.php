<?php

namespace Miraqceed\Foundation\Tests\Feature\Turnstile;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Miraqceed\Foundation\Tests\TestCase;
use Miraqceed\Foundation\Turnstile\Turnstile;
use Miraqceed\Foundation\Turnstile\TurnstileMisconfigured;
use Miraqceed\Foundation\Turnstile\TurnstilePassed;

/**
 * Turnstile の確かめ：閉じる側に倒す・設定の誤りを間引いて記録する・秘密を出さない。外への要求は偽る（漏れは止める）。
 * 鍵・トークンはすべて作り物
 */
class TurnstileTest extends TestCase
{
    private const SECRET = '0x4AAAAAAAsecret-for-tests-only';

    private const TOKEN = 'XXXX.TOKEN.FOR.TESTS';

    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private string $throttleDir;

    /** @var array{0: array<string, mixed>, 1: int}|null 偽の答え（Http::fake は先に登録したものが勝つので、1つの偽を使い回す） */
    private ?array $reply = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('services.turnstile.site_key', '0x4AAAAAAAsite-for-tests');
        $app['config']->set('services.turnstile.secret_key', self::SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 記録の間引きの目印を、試しごとに分ける
        $this->throttleDir = sys_get_temp_dir().'/foundation-turnstile-'.bin2hex(random_bytes(6));
        config(['foundation.failures.throttle_path' => $this->throttleDir]);
        $this->app['env'] = 'production';
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => fn () => $this->reply === null ? throw new ConnectionException('no reply') : Http::response(...$this->reply)]);
        Exceptions::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->throttleDir);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $answer */
    private function answer(array $answer, int $status = 200): void
    {
        $this->reply = [$answer, $status];
    }

    private function passes(mixed $token = self::TOKEN, ?string $action = null): bool
    {
        return $this->app->make(Turnstile::class)->passes($token, $action);
    }

    private function assertNothingSecretReported(): void
    {
        Exceptions::assertNotReported(fn (\Throwable $e) => str_contains($e->getMessage(), self::SECRET)
            || str_contains($e->getMessage(), self::TOKEN)
            || str_contains($e->getMessage(), '203.0.113.'));
    }

    public function test_success_なら通り_秘密の鍵とトークンを送る(): void
    {
        $this->answer(['success' => true, 'hostname' => 'app.example.com']);

        $this->assertTrue($this->passes());
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::ENDPOINT
            && $request['secret'] === self::SECRET
            && $request['response'] === self::TOKEN
            && ! isset($request['remoteip']));
        Exceptions::assertNothingReported();
    }

    public function test_トークンの形が違えば外へ送らずに断る(): void
    {
        $this->answer(['success' => true]);

        foreach ([null, 123, ['x'], '', str_repeat('a', 2049)] as $token) {
            $this->assertFalse($this->passes($token), json_encode($token));
        }
        $this->assertTrue($this->passes(str_repeat('a', 2048)));
        Http::assertSentCount(1);
    }

    public function test_秘密の鍵がなければ外へ送らずに断り_記録する(): void
    {
        config(['services.turnstile.secret_key' => '']);

        $this->assertFalse($this->passes());

        Http::assertNothingSent();
        Exceptions::assertReported(fn (TurnstileMisconfigured $e) => str_contains($e->getMessage(), 'services.turnstile.secret_key'));
    }

    public function test_設定の誤りの_error_codes_は断って記録し_5分に1回だけ(): void
    {
        $this->answer(['success' => false, 'error-codes' => ['invalid-input-secret']]);

        $this->assertFalse($this->passes());
        $this->assertFalse($this->passes());

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (TurnstileMisconfigured $e) => str_contains($e->getMessage(), 'invalid-input-secret'));
        $this->assertNothingSecretReported();
    }

    public function test_訪問者の側の誤りは断るが記録しない(): void
    {
        foreach (['invalid-input-response', 'timeout-or-duplicate', 'missing-input-response', 'bad-request'] as $code) {
            $this->answer(['success' => false, 'error-codes' => [$code]]);
            $this->assertFalse($this->passes(), $code);
        }

        Exceptions::assertNothingReported();
    }

    public function test_届かない_HTTP_の誤り_Cloudflare_の誤りは断って記録する(): void
    {
        $this->reply = null;
        $this->assertFalse($this->passes());
        Exceptions::assertReported(fn (TurnstileMisconfigured $e) => str_contains($e->getMessage(), '届かない'));

        foreach ([[['success' => true], 500], [['success' => false, 'error-codes' => ['internal-error']], 200], [['success' => true], 403]] as [$answer, $status]) {
            File::deleteDirectory($this->throttleDir);
            $this->answer($answer, $status);
            $this->assertFalse($this->passes(), json_encode([$answer, $status]));
        }

        Exceptions::assertReportedCount(4);
        $this->assertNothingSecretReported();
    }

    public function test_success_が真でなければ断る(): void
    {
        foreach ([[], ['success' => 'true'], ['success' => 1]] as $answer) {
            $this->answer($answer);
            $this->assertFalse($this->passes(), json_encode($answer));
        }
    }

    public function test_IP_は既定で送らず_send_ip_で送る(): void
    {
        $this->answer(['success' => true]);
        $this->app['request']->server->set('REMOTE_ADDR', '203.0.113.7');

        $this->passes();
        Http::assertSent(fn (HttpRequest $request) => ! isset($request['remoteip']));

        config(['foundation.turnstile.send_ip' => true]);
        $this->passes();
        Http::assertSent(fn (HttpRequest $request) => ($request['remoteip'] ?? null) === '203.0.113.7');
    }

    public function test_action_は渡したときだけ比べる(): void
    {
        $this->answer(['success' => true, 'action' => 'contact']);

        $this->assertTrue($this->passes(action: 'contact'));
        $this->assertFalse($this->passes(action: 'signup'));
        $this->assertTrue($this->passes());
    }

    public function test_hostname_は設定に書いたときだけ比べる(): void
    {
        $this->answer(['success' => true, 'hostname' => 'evil.example.net']);
        $this->assertTrue($this->passes());

        config(['foundation.turnstile.hostnames' => ['app.example.com', 'www.example.com']]);
        $this->assertFalse($this->passes());

        $this->answer(['success' => true, 'hostname' => 'www.example.com']);
        $this->assertTrue($this->passes());
    }

    public function test_テスト用の秘密の鍵は_production_でなければ_hostname_と_action_を比べない(): void
    {
        // テスト用の鍵の答えは hostname が example.com で action がない
        $this->answer(['success' => true, 'hostname' => 'example.com']);
        config(['services.turnstile.secret_key' => '1x0000000000000000000000000000000AA', 'foundation.turnstile.hostnames' => ['app.example.com']]);

        $this->assertFalse($this->passes(action: 'signup'));

        $this->app['env'] = 'local';
        $this->assertTrue($this->passes(action: 'signup'));
    }

    public function test_規則は断ると文が1つ(): void
    {
        $this->answer(['success' => false, 'error-codes' => ['invalid-input-response']]);
        app('translator')->setLocale('ja');

        $validator = Validator::make(['cf-turnstile-response' => self::TOKEN], ['cf-turnstile-response' => [new TurnstilePassed]]);

        $this->assertTrue($validator->fails());
        $this->assertSame('ボットでないことの確かめが済んでいません。もう一度お試しください。', $validator->errors()->first('cf-turnstile-response'));

        $this->answer(['success' => true, 'action' => 'contact']);
        $this->assertTrue(Validator::make(['t' => self::TOKEN], ['t' => [new TurnstilePassed(action: 'contact')]])->passes());
        $this->assertFalse(Validator::make(['t' => self::TOKEN], ['t' => [new TurnstilePassed(action: 'signup')]])->passes());
    }
}
