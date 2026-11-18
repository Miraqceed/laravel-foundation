<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Miraqceed\Foundation\DeployCheck\Checks\MailLinks;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * mail_links：設定が偽なら skip。真ならパッケージのリンクの作り方が効いていれば ok、アプリが自分で作っていれば info
 */
class MailLinksTest extends TestCase
{
    protected function linksFromAppUrl($app): void
    {
        $app['config']->set('foundation.mail.links_from_app_url', true);
    }

    protected function tearDown(): void
    {
        // createUrlUsing・toMailUsing は static で、試しをまたいで残る
        ResetPassword::$createUrlCallback = null;
        ResetPassword::$toMailCallback = null;
        VerifyEmail::$createUrlCallback = null;
        VerifyEmail::$toMailCallback = null;

        parent::tearDown();
    }

    /** @return array{Status, ?string} */
    private function check(): array
    {
        $result = (new MailLinks)->run();

        // detail に URL を出さない
        $this->assertStringNotContainsString('https://', (string) $result->detail);

        return [$result->status, $result->detail];
    }

    public function test_設定が偽なら_skip(): void
    {
        $this->assertSame(Status::Skip, $this->check()[0]);
    }

    #[DefineEnvironment('linksFromAppUrl')]
    public function test_設定が真でパッケージの作り方が効いていれば_ok(): void
    {
        $this->assertSame([Status::Ok, null], $this->check());
    }

    #[DefineEnvironment('linksFromAppUrl')]
    public function test_アプリが_createUrlUsing_で上書きしていれば_info(): void
    {
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token) => 'https://app-own.example.com/reset-password/'.$token);

        [$status, $detail] = $this->check();
        $this->assertSame(Status::Info, $status);
        $this->assertSame('アプリが自分で作っている：再設定（createUrlUsing）', $detail);
    }

    #[DefineEnvironment('linksFromAppUrl')]
    public function test_アプリが_toMailUsing_でメールを作っていれば_info(): void
    {
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new MailMessage)->line('確認'));

        [$status, $detail] = $this->check();
        $this->assertSame(Status::Info, $status);
        $this->assertSame('アプリが自分で作っている：確認（toMailUsing でメールを作る）', $detail);
    }

    #[DefineEnvironment('linksFromAppUrl')]
    public function test_設定が真なのに登録されていなければ_fail(): void
    {
        ResetPassword::$createUrlCallback = null;
        VerifyEmail::$createUrlCallback = null;

        [$status, $detail] = $this->check();
        $this->assertSame(Status::Fail, $status);
        $this->assertStringContainsString('再設定・確認', (string) $detail);
    }
}
