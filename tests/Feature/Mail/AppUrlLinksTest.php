<?php

namespace Miraqceed\Foundation\Tests\Feature\Mail;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Miraqceed\Foundation\Tests\Support\AppUrlLinksAppProvider;
use Miraqceed\Foundation\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Symfony\Component\Mime\Email;

class AppUrlLinksTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'app@example.co.jp', 'name' => 'App']);
        $app['config']->set('app.url', 'https://app.example.jp');
    }

    protected function linksFromAppUrl($app): void
    {
        $app['config']->set('foundation.mail.links_from_app_url', true);
    }

    protected function defineRoutes($router): void
    {
        // Fortify・Breeze と同じ名前の経路。確認の経路は Laravel の signed の中間（絶対の URL で署名を確かめる）
        $router->get('/reset-password/{token}', fn () => 'reset')->name('password.reset');
        $router->get('/email/verify/{id}/{hash}', fn () => 'verified')->middleware('signed')->name('verification.verify');

        // 要求の中で同期で送り、送った後の同じ要求の中の url() を返す
        $router->get('/send-reset', function () {
            // 先に全体の url で経路の URL を作っておく（作り手が元に残っていても、複製が要求の Host を使わないこと）
            route('password.reset', ['token' => 'warm-up']);
            self::user()->notify(new ResetPassword('reset-token'));

            return url('/x');
        });
        $router->get('/send-verify', function () {
            self::user()->sendEmailVerificationNotification();

            return url('/x');
        });
    }

    protected function tearDown(): void
    {
        // createUrlUsing は static で、試しをまたいで残る
        ResetPassword::$createUrlCallback = null;
        VerifyEmail::$createUrlCallback = null;

        parent::tearDown();
    }

    private static function user(): User
    {
        $user = new class extends User implements MustVerifyEmail
        {
            use Notifiable;
        };

        return $user->forceFill(['id' => 7, 'email' => 'someone@gmail.com']);
    }

    /** 届いたメールの text の版から、再設定・確認のリンクを取り出す */
    private function sentLink(): string
    {
        /** @var Email $email */
        $email = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        preg_match('#https?://\S+/(?:reset-password|email/verify)/[^\s)\]]+#', (string) $email->getTextBody(), $match);

        return $match[0] ?? '';
    }

    public function test_既定では登録しない(): void
    {
        $this->assertNull(ResetPassword::$createUrlCallback);
        $this->assertNull(VerifyEmail::$createUrlCallback);

        // Laravel の既定のまま：要求の Host から作る
        $this->get('http://evil.example/send-reset')->assertOk();

        $this->assertStringStartsWith('http://evil.example/reset-password/reset-token', $this->sentLink());
    }

    #[DefineEnvironment('linksFromAppUrl')]
    public function test_再設定のリンクは_APP_URL(): void
    {
        $this->get('http://evil.example/send-reset')->assertOk();

        $this->assertSame('https://app.example.jp/reset-password/reset-token?email=someone%40gmail.com', $this->sentLink());
    }

    #[DefineEnvironment('linksFromAppUrl')]
    public function test_確認のリンクは_APP_URL_で、signed_を通る(): void
    {
        $this->get('http://evil.example/send-verify')->assertOk();
        $link = $this->sentLink();

        $this->assertStringStartsWith('https://app.example.jp/email/verify/7/'.sha1('someone@gmail.com').'?expires=', $link);
        $this->get($link)->assertOk()->assertSee('verified');

        // 書き換えると通らない
        $this->get(str_replace('/email/verify/7/', '/email/verify/8/', $link))->assertForbidden();

        // 期限（auth.verification.expire、既定は60分）を過ぎると通らない
        $this->travel(61)->minutes();
        $this->get($link)->assertForbidden();
    }

    #[DefineEnvironment('linksFromAppUrl')]
    public function test_URL_の根を変えない(): void
    {
        // 送った後の同じ要求の中の url() は、要求の Host のまま（全体の forceRootUrl を掛けない）
        $this->get('http://evil.example/send-reset')->assertOk()->assertContent('http://evil.example/x');
        $this->get('http://evil.example/send-verify')->assertOk()->assertContent('http://evil.example/x');
    }

    #[DefineEnvironment('linksFromAppUrl')]
    public function test_APP_URL_の道も保つ(): void
    {
        config(['app.url' => 'https://example.jp/app/']);

        $this->get('http://evil.example/send-reset')->assertOk();

        $this->assertSame('https://example.jp/app/reset-password/reset-token?email=someone%40gmail.com', $this->sentLink());
    }

    #[DefineEnvironment('linksFromAppUrl')]
    public function test_アプリが後で書けばアプリが勝つ(): void
    {
        // アプリのプロバイダーは、パッケージのプロバイダーの後に起動する（Laravel の順と同じ）
        $this->app->register(AppUrlLinksAppProvider::class);

        $this->get('http://evil.example/send-reset')->assertOk();

        $this->assertSame('https://app-own.example.jp/reset-password/reset-token', $this->sentLink());
    }
}
