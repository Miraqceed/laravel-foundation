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
        $this->provider()->verify($secret, '000000');

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
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($this->provider()->verify($secret, sprintf('%06d', $i)));
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
}
