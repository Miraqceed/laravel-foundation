<?php

namespace Miraqceed\Foundation\Tests\Feature\Accounts;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Miraqceed\Foundation\Accounts\PasswordSessions;
use Miraqceed\Foundation\Tests\Support\AccountsUser;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * パスワードの後のセッション：ほかのセッションを消す・この端末は入り直す・remember の作り直し・ハッシュを計算し直さない
 */
class PasswordSessionsTest extends TestCase
{
    private AccountsUser $alice;

    private AccountsUser $bob;

    private ?string $sessionFiles = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', AccountsUser::class);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function ($router) {
            $router->post('/test/login', function (Request $request) {
                Auth::login(AccountsUser::findOrFail($request->input('id')), $request->boolean('remember'));

                return ['session' => $request->session()->getId()];
            });

            // パスワードの変更の口（ログイン中の本人）。ハッシュは試しが先に作って渡す（後始末がハッシュを計算しないことを見るため）
            $router->post('/test/change-password/{id?}', function (Request $request, ?string $id = null) {
                $user = $id === null ? $request->user() : AccountsUser::findOrFail($id);
                $before = $request->session()->getId();

                $user->forceFill(['password' => $request->input('hash')])->save();
                $ended = app(PasswordSessions::class)->afterChange($request, $user);

                return ['before' => $before, 'after' => $request->session()->getId(), 'ended' => $ended];
            })->middleware('auth');

            $router->get('/test/me', fn (Request $request) => ['id' => $request->user()->getAuthIdentifier()])
                ->middleware(['auth', 'auth.session']);

            // auth.session はログアウトさせるとき、行き先に login の経路の名前を引く
            $router->get('/test/sign-in', fn () => 'sign in')->name('login');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
        });
        // Laravel の雛形の sessions の表と同じ形
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        $this->alice = AccountsUser::create(['email' => 'alice@example.test', 'password' => Hash::make('old-password'), 'remember_token' => 'alice-old-token']);
        $this->bob = AccountsUser::create(['email' => 'bob@example.test', 'password' => Hash::make('old-password'), 'remember_token' => 'bob-old-token']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');

        if ($this->sessionFiles !== null) {
            File::deleteDirectory($this->sessionFiles);
        }

        parent::tearDown();
    }

    /**
     * 1つの試しの中で、要求ごとに別の端末にする（ガード・セッション・積まれた Cookie・送る Cookie を残さない）
     */
    private function forgetRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['cookie']->flushQueuedCookies();
        $this->defaultCookies = [];
    }

    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    /**
     * ログインして、その端末の Cookie（セッション。remember なら remember も）を返す
     *
     * @return array<string, string>
     */
    private function signIn(AccountsUser $user, bool $remember = false): array
    {
        $this->forgetRequestState();
        $response = $this->postJson('/test/login', ['id' => $user->id, 'remember' => $remember])->assertOk();

        $cookies = [config('session.cookie') => $response->getCookie(config('session.cookie'))->getValue()];
        if ($remember) {
            $cookies[$this->recallerName()] = $response->getCookie($this->recallerName())->getValue();
        }

        return $cookies;
    }

    /**
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $uri, array $cookies, array $data = []): TestResponse
    {
        $this->forgetRequestState();

        // JSON の要求は、withCredentials() がないと Cookie を送らない
        return $this->withCredentials()->withCookies($cookies)->json($method, $uri, $data);
    }

    private function insertSession(string $id, AccountsUser $user): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }

    /** @return list<string> */
    private function sessionIds(AccountsUser $user): array
    {
        return DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
    }

    private function useFileSessions(): void
    {
        $this->sessionFiles = sys_get_temp_dir().'/foundation-sessions-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->sessionFiles);
        config(['session.driver' => 'file', 'session.files' => $this->sessionFiles]);
    }

    public function test_変更の後_本人のほかのセッションを消し_ほかの利用者のは残し_この端末の_ID_を変える(): void
    {
        $cookies = $this->signIn($this->alice);
        $current = $cookies[config('session.cookie')];
        $this->insertSession('alice-phone', $this->alice);
        $this->insertSession('alice-tablet', $this->alice);
        $this->insertSession('bob-phone', $this->bob);

        $newHash = Hash::make('new-password');
        Hash::spy();

        $response = $this->send('POST', '/test/change-password', $cookies, ['hash' => $newHash])->assertOk();

        $this->assertSame($current, $response->json('before'));
        $this->assertNotSame($current, $response->json('after'));
        $this->assertSame(2, $response->json('ended'));
        // 残るのは、この端末の新しい ID の行だけ（古い ID の行は作り直しで消える）
        $this->assertSame([$response->json('after')], $this->sessionIds($this->alice));
        $this->assertSame(['bob-phone'], $this->sessionIds($this->bob));
        // ハッシュを計算し直さない（logoutOtherDevices を使わない）
        Hash::shouldNotHaveReceived('make');
        // remember のトークンは作り直す。remember の Cookie を持っていなかったので、Cookie は作らない
        $this->assertNotSame('alice-old-token', $this->alice->fresh()->remember_token);
        $this->assertNull($response->getCookie($this->recallerName(), false));
        $this->assertSame('bob-old-token', $this->bob->fresh()->remember_token);
    }

    public function test_remember_の_Cookie_を持っていたときだけ_新しいトークンで作り直す(): void
    {
        $cookies = $this->signIn($this->alice, remember: true);
        $oldToken = $this->alice->fresh()->remember_token;

        $response = $this->send('POST', '/test/change-password', $cookies, ['hash' => Hash::make('new-password')])->assertOk();

        $newToken = $this->alice->fresh()->remember_token;
        $this->assertNotSame($oldToken, $newToken);
        [$id, $token] = explode('|', $response->getCookie($this->recallerName())->getValue());
        $this->assertSame((string) $this->alice->id, $id);
        $this->assertSame($newToken, $token);
    }

    /**
     * auth.session だけで守るアプリ（セッションが file）。ほかの端末は次の要求でログアウトし、この端末は残る。
     * Laravel 12.0 の Auth::login() はセッションにパスワードのハッシュを置かないので、後始末が置かなければ、
     * この端末も古いハッシュのままで次の要求でログアウトする（L12 lowest の組み合わせで確かめる）
     */
    public function test_file_のセッションでは_0_を返して投げず_auth_session_がほかの端末を締め出し_この端末は残る(): void
    {
        $this->useFileSessions();

        $laptop = $this->signIn($this->alice);
        $phone = $this->signIn($this->alice);
        // auth.session が、今のパスワードのハッシュを両方のセッションに置く
        $this->send('GET', '/test/me', $laptop)->assertOk();
        $this->send('GET', '/test/me', $phone)->assertOk();

        $response = $this->send('POST', '/test/change-password', $laptop, ['hash' => Hash::make('new-password')])->assertOk();
        $this->assertSame(0, $response->json('ended'));

        $laptop[config('session.cookie')] = $response->getCookie(config('session.cookie'))->getValue();
        $this->send('GET', '/test/me', $laptop)->assertOk()->assertJson(['id' => $this->alice->id]);
        $this->send('GET', '/test/me', $phone)->assertUnauthorized();
    }

    public function test_ログイン中の利用者が別の人なら_その人のすべてのセッションを消し_今のログインには触らない(): void
    {
        $cookies = $this->signIn($this->bob);
        $this->insertSession('alice-phone', $this->alice);
        $this->insertSession('alice-tablet', $this->alice);

        $response = $this->send('POST', "/test/change-password/{$this->alice->id}", $cookies, ['hash' => Hash::make('new-password')])->assertOk();

        $this->assertSame(2, $response->json('ended'));
        $this->assertSame($response->json('before'), $response->json('after'));
        $this->assertSame([], $this->sessionIds($this->alice));
        $this->assertSame([$response->json('after')], $this->sessionIds($this->bob));
        $this->assertNotSame('alice-old-token', $this->alice->fresh()->remember_token);
    }

    public function test_再設定の後_本人のすべてのセッションを消し_remember_のトークンを作り直す(): void
    {
        $this->insertSession('alice-laptop', $this->alice);
        $this->insertSession('alice-phone', $this->alice);
        $this->insertSession('bob-phone', $this->bob);

        $ended = app(PasswordSessions::class)->afterReset($this->alice);

        $this->assertSame(2, $ended);
        $this->assertSame([], $this->sessionIds($this->alice));
        $this->assertSame(['bob-phone'], $this->sessionIds($this->bob));
        $this->assertNotSame('alice-old-token', $this->alice->fresh()->remember_token);
        $this->assertSame('bob-old-token', $this->bob->fresh()->remember_token);
    }

    public function test_再設定の後_file_のセッションでは_0_を返す(): void
    {
        $this->useFileSessions();

        $this->assertSame(0, app(PasswordSessions::class)->afterReset($this->alice));
        $this->assertNotSame('alice-old-token', $this->alice->fresh()->remember_token);
    }
}
