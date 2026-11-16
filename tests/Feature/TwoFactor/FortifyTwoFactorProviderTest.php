<?php

namespace Miraqceed\Foundation\Tests\Feature\TwoFactor;

use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider as ProviderContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticationProvider as FortifyOwnProvider;
use Miraqceed\Foundation\Tests\Support\WrongTotpCodes;
use Miraqceed\Foundation\Tests\TestCase;
use Miraqceed\Foundation\TwoFactor\FortifyTwoFactorProvider;
use Miraqceed\Foundation\TwoFactor\Totp;
use PragmaRX\Google2FA\Google2FA;

class FortifyTwoFactorUser extends Authenticatable
{
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * Fortify の確かめ（TwoFactorAuthenticationProvider）の差し替え。使った刻みの印を Cache::add で立て、間違いを続けた失敗で数える
 */
class FortifyTwoFactorProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FortifyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.users.model', FortifyTwoFactorUser::class);
        $app['config']->set('fortify.features', [Features::twoFactorAuthentication(['confirm' => true])]);
        // Fortify の two-factor の制限は外す（続けた失敗で数えるので、二重に数えない）
        $app['config']->set('fortify.limiters.two-factor', null);
        $app['config']->set('foundation.attempts.consecutive', ['max' => 3, 'lock_seconds' => 60]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function provider(): FortifyTwoFactorProvider
    {
        return $this->app->make(FortifyTwoFactorProvider::class);
    }

    /** 刻み t の始め（境目）に時刻を合わせる */
    private function travelToStepStart(int $step): void
    {
        $this->travelTo(Carbon::createFromTimestamp($step * 30));
        $this->assertSame(0, now()->getTimestamp() % 30);
    }

    /**
     * Fortify の元の差し替えの穴を、先に起こしておく（設計書 3.5 で「試しで確かめる」とした所）。
     * 元の作りは、キャッシュを get してから確かめ、put する。2つの要求がどちらも get で何もないと読むと、同じコードが2回通る。
     * Fortify の版が上がって起きなくなったら飛ばす（説明書を見直す合図）
     */
    public function test_fortify_own_provider_lets_the_same_code_through_twice_when_requests_race(): void
    {
        $own = new FortifyOwnProvider(new Google2FA, Cache::store());
        $secret = Totp::generateSecret();
        $code = Totp::codeAt($secret, now()->getTimestamp());
        $second = null;
        $raced = false;
        Event::listen(CacheMissed::class, function (CacheMissed $event) use ($own, $secret, $code, &$second, &$raced) {
            if (! $raced && $event->key === 'fortify.2fa_codes.'.md5($code)) {
                $raced = true;
                $second = $own->verify($secret, $code);
            }
        });

        $first = $own->verify($secret, $code);

        if (! $raced || ! ($first && $second)) {
            $this->markTestSkipped('Fortify の元の差し替えの穴が、この版では起きない（raced='.var_export($raced, true).'）');
        }
        $this->assertTrue($first && $second);
    }

    public function test_same_code_is_accepted_only_once(): void
    {
        $secret = Totp::generateSecret();
        $code = Totp::codeAt($secret, now()->getTimestamp());

        $this->assertTrue($this->provider()->verify($secret, $code));
        $this->assertFalse($this->provider()->verify($secret, $code));
    }

    /** @return array<string, array{0: int}> */
    public static function windows(): array
    {
        return ['window 1' => [1], 'window 2' => [2]];
    }

    /**
     * 印の有効の時間は (2 × window + 2) × 30 秒。窓の全体 (2 × window + 1) × 30 秒より 1 刻み長い。
     * 余りの 1 刻みは、複数のサーバーの時計のずれを吸うためのもので、1つの時計で動く試しでは確かめられない
     * （この試しが確かめるのは、窓の全体より短くないことだけ）
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('windows')]
    public function test_code_is_refused_again_until_the_end_of_the_window(int $window): void
    {
        config(['fortify-options.two-factor-authentication.window' => $window]);
        $secret = Totp::generateSecret();
        $step = intdiv(1795136415, 30);
        // 刻み t の始めに、窓のいちばん後ろ（t+window）のコードで通る。このコードは刻み t+2×window の終わりまで受けられる
        $this->travelToStepStart($step);
        $code = Totp::codeAt($secret, ($step + $window) * 30);
        $this->assertTrue($this->provider()->verify($secret, $code));

        $this->travelTo(Carbon::createFromTimestamp(($step + 2 * $window + 1) * 30 - 1));
        // 前提：この時刻でも、コードはまだ窓の中にある
        $this->assertSame($step + $window, Totp::verify($secret, $code, now()->getTimestamp(), null, $window));

        $this->assertFalse($this->provider()->verify($secret, $code));
    }

    public function test_earlier_steps_in_the_window_are_refused_after_a_later_step_passed(): void
    {
        $secret = Totp::generateSecret();
        $step = intdiv(1795136415, 30);
        $this->travelToStepStart($step);

        $this->assertTrue($this->provider()->verify($secret, Totp::codeAt($secret, $step * 30)));
        // 前提：前の刻みのコードは、まだ窓の中にある
        $earlier = Totp::codeAt($secret, ($step - 1) * 30);
        $this->assertSame($step - 1, Totp::verify($secret, $earlier, now()->getTimestamp(), null, 1));

        $this->assertFalse($this->provider()->verify($secret, $earlier));
    }

    public function test_later_step_passes_after_an_earlier_step(): void
    {
        $secret = Totp::generateSecret();
        $step = intdiv(1795136415, 30);
        $this->travelToStepStart($step);

        $this->assertTrue($this->provider()->verify($secret, Totp::codeAt($secret, ($step - 1) * 30)));
        $this->assertTrue($this->provider()->verify($secret, Totp::codeAt($secret, $step * 30)));
    }

    public function test_cache_keys_contain_neither_the_secret_nor_the_code(): void
    {
        $secret = Totp::generateSecret();
        $code = Totp::codeAt($secret, now()->getTimestamp());
        $keys = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$keys) {
            $keys[] = $event->key;
        });

        $this->provider()->verify($secret, $code);
        $this->provider()->verify($secret, WrongTotpCodes::one($secret));

        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertStringNotContainsString($secret, $key);
            $this->assertStringNotContainsString($code, $key);
            $this->assertStringNotContainsString(md5($code), $key);
        }
    }

    public function test_wrong_codes_lock_with_429(): void
    {
        $secret = Totp::generateSecret();
        foreach (WrongTotpCodes::for($secret, 3) as $code) {
            $this->assertFalse($this->provider()->verify($secret, $code));
        }

        $caught = null;
        try {
            // ロック中は、正しいコードでも確かめない
            $this->provider()->verify($secret, Totp::codeAt($secret, now()->getTimestamp()));
        } catch (ValidationException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame(429, $caught->status);
        $this->assertArrayHasKey('code', $caught->errors());
    }

    public function test_works_through_fortify_confirmation_and_two_factor_challenge(): void
    {
        $this->app->singleton(ProviderContract::class, FortifyTwoFactorProvider::class);
        $secret = Totp::generateSecret();
        $user = FortifyTwoFactorUser::query()->create([
            'email' => 'a@example.test',
            'password' => Hash::make('correct-password'),
            // Fortify 1.25 は currentEncrypter を持たず、encrypt() で暗号にする（--prefer-lowest の組み合わせ）
            'two_factor_secret' => method_exists(Fortify::class, 'currentEncrypter') ? Fortify::currentEncrypter()->encrypt($secret) : encrypt($secret),
        ]);
        $code = Totp::codeAt($secret, now()->getTimestamp());

        // 登録の確かめ（ConfirmTwoFactorAuthentication）も、差し替えた確かめを通る
        $this->app->make(ConfirmTwoFactorAuthentication::class)($user, $code);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        // 登録に使ったコードは、ログインの確かめで使い回せない
        $this->withSession(['login.id' => $user->getKey()])
            ->postJson('/two-factor-challenge', ['code' => $code])
            ->assertStatus(422);

        $this->travel(30)->seconds();
        $next = Totp::codeAt($secret, now()->getTimestamp());
        $this->withSession(['login.id' => $user->getKey()])
            ->postJson('/two-factor-challenge', ['code' => $next])
            ->assertSuccessful();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_generate_secret_key_honours_the_length(): void
    {
        foreach ([16, 32] as $length) {
            $secret = $this->provider()->generateSecretKey($length);
            $this->assertSame($length, strlen($secret));
            $this->assertMatchesRegularExpression('/^[A-Z2-7]+\z/', $secret);
        }
    }

    public function test_qr_code_url_is_encoded(): void
    {
        $url = $this->provider()->qrCodeUrl('架空 & Co', 'a b@example.test', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('otpauth://totp/'.rawurlencode('架空 & Co').':'.rawurlencode('a b@example.test').'?', $url);
    }

    /**
     * 先の刻みのコードで通った後、窓の中の前の刻みのコードは、どれも通らない（手書きの側の「最後に受けた刻み以下は受けない」と同じ）。
     * 今の刻みが c のとき、c+1 のコードで通ると、c−1 のコードもまだ窓の中にある
     */
    public function test_older_steps_in_the_window_are_refused_after_a_future_step_passed(): void
    {
        $secret = Totp::generateSecret();
        $step = intdiv(1795136415, 30);
        $this->travelToStepStart($step);
        $this->assertTrue($this->provider()->verify($secret, Totp::codeAt($secret, ($step + 1) * 30)));

        $older = Totp::codeAt($secret, ($step - 1) * 30);
        // 前提：c−1 のコードは、まだ窓の中にある
        $this->assertSame($step - 1, Totp::verify($secret, $older, now()->getTimestamp(), null, 1));
        $this->assertFalse($this->provider()->verify($secret, $older));
        $this->assertFalse($this->provider()->verify($secret, Totp::codeAt($secret, $step * 30)));
    }

    /**
     * 使った刻みの印を、確かめてから立てる2段にすると、同じコードの2つの要求が揃って通る。
     * database のキャッシュで、1つ目が印を読んだ所（cache の表の SELECT）で、2つ目を最後まで走らせる
     */
    public function test_used_step_mark_is_taken_atomically(): void
    {
        Schema::dropIfExists('cache');
        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        config(['cache.default' => 'database']);
        Cache::forgetDriver('database');
        $this->app->forgetInstance(\Illuminate\Cache\RateLimiter::class);
        \Illuminate\Support\Facades\RateLimiter::clearResolvedInstance(\Illuminate\Cache\RateLimiter::class);

        try {
            $secret = Totp::generateSecret();
            $code = Totp::codeAt($secret, now()->getTimestamp());
            $results = [];
            $raced = false;
            \Illuminate\Support\Facades\DB::listen(function ($query) use (&$raced, &$results, $secret, $code) {
                if ($raced || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'cache')) {
                    return;
                }
                foreach ($query->bindings as $binding) {
                    if (is_string($binding) && str_contains($binding, 'two-factor-used')) {
                        $raced = true;
                        $results['second'] = $this->provider()->verify($secret, $code);

                        return;
                    }
                }
            });

            $results['first'] = $this->provider()->verify($secret, $code);

            // 起きたことを先に確かめる：1つ目が印を読んだ所で、2つ目が走った
            $this->assertTrue($raced);
            $this->assertSame(1, count(array_filter($results)), json_encode($results));
        } finally {
            Schema::dropIfExists('cache');
        }
    }

    /**
     * 別の利用者が同じコードを打っても断られない（印の鍵は秘密の鍵と刻みの HMAC で、コードによらない）。
     * 下の2つの作り物の鍵は、時刻 1795136415 で同じコード 371969 を出す（総当たりで探した組）
     */
    public function test_another_user_with_the_same_code_is_not_refused(): void
    {
        $first = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $second = 'SYZOYBNZAB6MMWI5JEQKGXMK5VRNPHZC';
        $this->travelTo(Carbon::createFromTimestamp(1795136415));
        $code = Totp::codeAt($first, now()->getTimestamp());
        $this->assertSame($code, Totp::codeAt($second, now()->getTimestamp()));

        $this->assertTrue($this->provider()->verify($first, $code));
        $this->assertTrue($this->provider()->verify($second, $code));
    }

    private function lockProvider(string $secret): void
    {
        foreach (WrongTotpCodes::for($secret, 3) as $code) {
            $this->provider()->verify($secret, $code);
        }
    }

    public function test_locked_challenge_is_429_for_json_and_a_redirect_with_the_error_for_forms(): void
    {
        $this->app->singleton(ProviderContract::class, FortifyTwoFactorProvider::class);
        $secret = Totp::generateSecret();
        $user = FortifyTwoFactorUser::query()->create([
            'email' => 'a@example.test',
            'password' => Hash::make('correct-password'),
            'two_factor_secret' => method_exists(Fortify::class, 'currentEncrypter') ? Fortify::currentEncrypter()->encrypt($secret) : encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->lockProvider($secret);

        $this->withSession(['login.id' => $user->getKey()])
            ->postJson('/two-factor-challenge', ['code' => '123456'])
            ->assertStatus(429)
            ->assertJsonValidationErrors('code');

        $this->withSession(['login.id' => $user->getKey()])
            ->post('/two-factor-challenge', ['code' => '123456'])
            ->assertStatus(302)
            ->assertSessionHasErrors('code');
    }

    /** 登録の確かめ（two-factor.confirm）でロックしたときは、Fortify の登録の確かめと同じ袋に誤りを入れる */
    public function test_locked_confirmation_puts_the_error_in_the_confirmation_bag(): void
    {
        $secret = Totp::generateSecret();
        $this->lockProvider($secret);
        $request = \Illuminate\Http\Request::create('/user/confirmed-two-factor-authentication', 'POST');
        $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('POST', 'user/confirmed-two-factor-authentication', []))->name('two-factor.confirm'));
        $this->app->instance('request', $request);

        $caught = null;
        try {
            $this->provider()->verify($secret, '123456');
        } catch (ValidationException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('confirmTwoFactorAuthentication', $caught->errorBag);
        $this->assertArrayHasKey('code', $caught->errors());
    }
}
