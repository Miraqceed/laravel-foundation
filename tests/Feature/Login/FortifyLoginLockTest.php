<?php

namespace Miraqceed\Foundation\Tests\Feature\Login;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Miraqceed\Foundation\Login\Fortify\AuthenticateWithLoginLock;
use Miraqceed\Foundation\Login\IpBucket;
use Miraqceed\Foundation\Login\LoginLock;
use Miraqceed\Foundation\Login\NormalizedEmailKey;
use Miraqceed\Foundation\Tests\TestCase;

class FortifyLoginLockTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FortifyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.users.model', FortifyTestUser::class);
        // Fortify 自身の回数の制限は外す（二重に数えない）
        $app['config']->set('fortify.limiters.login', null);
        // 二段階認証を有効にすると、Fortify は認証の関数を RedirectIfTwoFactorAuthenticatable でも呼ぶ
        $app['config']->set('fortify.features', [Features::twoFactorAuthentication()]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
        });

        FortifyTestUser::create(['email' => 'a@example.test', 'password' => Hash::make('correct-password')]);
    }

    protected function tearDown(): void
    {
        Fortify::$authenticateUsingCallback = null;
        Fortify::$authenticateThroughCallback = null;
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function useLock(?int &$calls = null): void
    {
        $inner = new AuthenticateWithLoginLock(new LoginLock(new NormalizedEmailKey));
        $calls = 0;

        // Fortify 自身の回数の制限（EnsureLoginIsNotThrottled）を流れから除く
        Fortify::authenticateThrough(fn () => AuthenticateWithLoginLock::loginPipeline());

        Fortify::authenticateUsing(function ($request) use ($inner, &$calls) {
            $calls++;

            return $inner($request);
        });
    }

    private function accountKey(): string
    {
        return 'foundation-login:user:account:'.sha1((new NormalizedEmailKey)->key('a@example.test').'|'.IpBucket::forIp('127.0.0.1'));
    }

    public function test_fortify_login_is_locked_with_throttle_message(): void
    {
        $this->useLock();

        // Fortify 自身の制限は username|ip で5回なので、メールを変える20回では止まらない。
        // 止めるのは LoginLock の接続元の枠だけ
        for ($i = 1; $i <= 20; $i++) {
            $this->postJson('/login', ['email' => "user{$i}@example.test", 'password' => 'wrong'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('email');
        }

        $response = $this->postJson('/login', ['email' => 'a@example.test', 'password' => 'correct-password']);

        $response->assertStatus(429)->assertJsonValidationErrors('email');
        $this->assertStringStartsWith('Too many login attempts', $response->json('errors.email.0'));
        $this->assertGuest();
    }

    public function test_fortify_own_throttle_is_not_in_pipeline(): void
    {
        $this->assertNotContains(\Laravel\Fortify\Actions\EnsureLoginIsNotThrottled::class, AuthenticateWithLoginLock::loginPipeline());
        $this->assertCount(4, AuthenticateWithLoginLock::loginPipeline());
    }

    public function test_fortify_with_two_factor_counts_once(): void
    {
        $this->useLock($calls);

        // 失敗：1回目の呼び出しで失敗して終わるので、1回しか数えない
        $this->postJson('/login', ['email' => 'a@example.test', 'password' => 'wrong'])->assertStatus(422);
        $this->assertSame(1, $calls);
        $this->assertSame(1, RateLimiter::attempts($this->accountKey()));

        // 成功：二段階認証のない利用者は RedirectIfTwoFactorAuthenticatable と
        // AttemptToAuthenticate の両方が呼ぶので、関数は2回呼ばれる。成功のたびに数えを戻すので何も残らない
        $calls = 0;
        $this->postJson('/login', ['email' => 'a@example.test', 'password' => 'correct-password'])->assertSuccessful();
        $this->assertSame(2, $calls);
        $this->assertSame(0, RateLimiter::attempts($this->accountKey()));
        $this->assertAuthenticated();
    }
}

class FortifyTestUser extends Authenticatable
{
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];
}
