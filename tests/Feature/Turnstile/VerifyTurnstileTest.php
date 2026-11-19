<?php

namespace Miraqceed\Foundation\Tests\Feature\Turnstile;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Miraqceed\Foundation\Tests\TestCase;
use Miraqceed\Foundation\Turnstile\VerifyTurnstileOnNamedRoutes;

/**
 * ミドルウェア：付けた経路ではいつも確かめる。名前で当てる形は routes の経路だけ。GET は見ない。入れなければ何も変わらない
 */
class VerifyTurnstileTest extends TestCase
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private string $throttleDir;

    /** @var array<string, mixed> 偽の答え（Http::fake は先に登録したものが勝つので、1つの偽を使い回す） */
    private array $reply = ['success' => false, 'error-codes' => ['invalid-input-response']];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('services.turnstile.secret_key', '0x4AAAAAAAsecret-for-tests-only');
    }

    protected function defineRoutes($router): void
    {
        $router->post('/contact', fn () => 'sent')->middleware('foundation.turnstile');
        $router->get('/contact', fn () => 'form')->middleware('foundation.turnstile');
        $router->post('/signup', fn () => 'signed up')->middleware('foundation.turnstile:signup');
        $router->post('/plain', fn () => 'plain');

        // Fortify の経路の形（経路に付けられない）
        $router->middleware(VerifyTurnstileOnNamedRoutes::class)->group(function ($router) {
            $router->post('/login', fn () => 'logged in')->name('login.store');
            $router->post('/other', fn () => 'other')->name('other.store');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->throttleDir = sys_get_temp_dir().'/foundation-turnstile-'.bin2hex(random_bytes(6));
        config(['foundation.failures.throttle_path' => $this->throttleDir]);
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => fn () => Http::response($this->reply)]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->throttleDir);

        parent::tearDown();
    }

    private function answer(bool $success, ?string $action = null): void
    {
        $this->reply = ['success' => $success, 'action' => $action, 'error-codes' => $success ? [] : ['invalid-input-response']];
    }

    public function test_付けた経路は確かめを通れば進み_通らなければ検証の誤り(): void
    {
        $this->answer(true);
        $this->postJson('/contact', ['cf-turnstile-response' => 'XXXX.TOKEN'])->assertOk()->assertSee('sent');

        $this->answer(false);
        $this->postJson('/contact', ['cf-turnstile-response' => 'XXXX.TOKEN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cf-turnstile-response');
        $this->postJson('/contact', [])->assertUnprocessable();
    }

    public function test_GET_は見ない(): void
    {
        $this->get('/contact')->assertOk();
        Http::assertNothingSent();
    }

    public function test_action_を経路の引数で渡せる(): void
    {
        $this->answer(true, 'contact');
        $this->postJson('/signup', ['cf-turnstile-response' => 'XXXX.TOKEN'])->assertUnprocessable();

        $this->answer(true, 'signup');
        $this->postJson('/signup', ['cf-turnstile-response' => 'XXXX.TOKEN'])->assertOk();
    }

    public function test_欄の名前を設定で変えられる(): void
    {
        config(['foundation.turnstile.field' => 'human_token']);
        $this->answer(true);

        $this->postJson('/contact', ['human_token' => 'XXXX.TOKEN'])->assertOk();
        Http::assertSent(fn (HttpRequest $request) => $request['response'] === 'XXXX.TOKEN');
    }

    public function test_名前で当てる形は_routes_の経路だけを確かめる(): void
    {
        $this->answer(false);

        // routes が空なら何もしない
        $this->postJson('/login')->assertOk();

        config(['foundation.turnstile.routes' => ['login.store']]);
        $this->postJson('/login', ['cf-turnstile-response' => 'XXXX.TOKEN'])->assertUnprocessable();
        $this->postJson('/other')->assertOk();
    }

    public function test_routes_の書き違いは止める(): void
    {
        config(['foundation.turnstile.routes' => 'login.store']);

        $response = $this->postJson('/login');

        $response->assertServerError();
        $this->assertInstanceOf(InvalidArgumentException::class, $response->exception);
    }

    public function test_入れなければ何も変わらない(): void
    {
        $this->postJson('/plain')->assertOk();
        Http::assertNothingSent();
    }
}
