<?php

namespace Miraqceed\Foundation\Tests\Feature\TwoFactor;

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Tests\Support\TwoFactorUser;
use Miraqceed\Foundation\Tests\TestCase;
use Miraqceed\Foundation\TwoFactor\Totp;
use Miraqceed\Foundation\TwoFactor\TwoFactor;

/**
 * 通った後のセッション。番号を作り直し、通った印を登録した鍵に結び付ける
 */
class TwoFactorSessionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
        });
        $files = glob(__DIR__.'/../../../database/two-factor-migrations/*.php');
        (require $files[0])->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function request(): Request
    {
        $request = Request::create('/two-factor', 'POST');
        $session = new Store('test', new ArraySessionHandler(10));
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    /** @return array{0: TwoFactorUser, 1: string} */
    private function confirmedUser(): array
    {
        $user = TwoFactorUser::query()->create(['email' => 'a@example.test']);
        $secret = Totp::generateSecret();
        $this->assertTrue($this->twoFactor()->confirm($user, $secret, Totp::codeAt($secret, now()->getTimestamp())));

        return [$user, $secret];
    }

    private function twoFactor(): TwoFactor
    {
        return $this->app->make(TwoFactor::class);
    }

    public function test_pass_regenerates_the_session_id(): void
    {
        [$user] = $this->confirmedUser();
        $request = $this->request();
        $before = $request->session()->getId();

        $this->twoFactor()->pass($request, $user);

        $this->assertNotSame($before, $request->session()->getId());
    }

    public function test_passed_is_true_after_pass_and_false_before(): void
    {
        [$user] = $this->confirmedUser();
        $request = $this->request();
        $this->assertFalse($this->twoFactor()->passed($request, $user));

        $this->twoFactor()->pass($request, $user);

        $this->assertTrue($this->twoFactor()->passed($request, $user));
    }

    public function test_passed_is_false_after_the_secret_is_confirmed_again(): void
    {
        [$user] = $this->confirmedUser();
        $request = $this->request();
        $this->twoFactor()->pass($request, $user);

        $this->twoFactor()->reset($user);
        $again = Totp::generateSecret();
        $this->assertTrue($this->twoFactor()->confirm($user, $again, Totp::codeAt($again, now()->getTimestamp())));

        $this->assertFalse($this->twoFactor()->passed($request, $user));
    }

    public function test_passed_is_false_for_another_user(): void
    {
        [$user] = $this->confirmedUser();
        $other = TwoFactorUser::query()->create(['email' => 'b@example.test']);
        $secret = Totp::generateSecret();
        $this->twoFactor()->confirm($other, $secret, Totp::codeAt($secret, now()->getTimestamp()));
        $request = $this->request();
        $this->twoFactor()->pass($request, $user);

        $this->assertFalse($this->twoFactor()->passed($request, $other));
    }

    public function test_mark_does_not_contain_the_secret(): void
    {
        [$user, $secret] = $this->confirmedUser();
        $request = $this->request();

        $this->twoFactor()->pass($request, $user);

        $this->assertStringNotContainsString($secret, json_encode($request->session()->all()));
    }
}
