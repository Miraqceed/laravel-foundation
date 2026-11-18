<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\DeployCheck\Checks\MailDomain;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * mail_domain：SPF・DMARC（DKIM はセレクターがあるとき）。DNS は差し替える（試しは外へ引かない）
 */
class MailDomainTest extends TestCase
{
    /** @var list<string> 引いた名前 */
    private array $asked = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // mail の調べが通る設定
        $app['config']->set('mail.default', 'smtp');
        $app['config']->set('mail.mailers.smtp.transport', 'smtp');
        $app['config']->set('mail.mailers.smtp.host', 'smtp.app-mail.jp');
        $app['config']->set('mail.from.address', 'noreply@App-Mail.jp');
    }

    /** @param  array<string, list<string>|false>  $records 名前ごとの TXT。無い名前は空 */
    private function check(array $records): Result
    {
        $this->asked = [];

        return (new MailDomain(function (string $name) use ($records): array|false {
            $this->asked[] = $name;

            return $records[$name] ?? [];
        }))->run();
    }

    private function complete(): array
    {
        return [
            'app-mail.jp' => ['google-site-verification=abc', 'v=spf1 include:_spf.mail.example ~all'],
            '_dmarc.app-mail.jp' => ['v=DMARC1; p=none'],
            'mail1._domainkey.app-mail.jp' => ['v=DKIM1; k=rsa; p=MIIBIjAN'],
        ];
    }

    public function test_SPF_と_DMARC_があれば_ok_DKIM_はセレクターがなければ見ない(): void
    {
        $result = $this->check($this->complete());

        $this->assertSame(Status::Ok, $result->status);
        $this->assertStringContainsString('DKIM は見ていない', (string) $result->detail);
        $this->assertSame(['app-mail.jp', '_dmarc.app-mail.jp'], $this->asked);
        $this->assertStringNotContainsString('noreply', (string) $result->detail);
    }

    public function test_セレクターがあれば_DKIM_も見る(): void
    {
        config(['foundation.deploy_check.dkim_selector' => 'mail1']);

        $this->assertSame(Status::Ok, $this->check($this->complete())->status);
        $this->assertSame(['app-mail.jp', '_dmarc.app-mail.jp', 'mail1._domainkey.app-mail.jp'], $this->asked);

        $records = $this->complete();
        unset($records['mail1._domainkey.app-mail.jp']);
        $result = $this->check($records);
        $this->assertSame(Status::Later, $result->status);
        $this->assertStringContainsString('DKIM', (string) $result->detail);
    }

    public function test_SPF_か_DMARC_がなければ_later(): void
    {
        foreach (['app-mail.jp' => 'SPF', '_dmarc.app-mail.jp' => 'DMARC'] as $name => $label) {
            $records = $this->complete();
            unset($records[$name]);
            $result = $this->check($records);

            $this->assertSame(Status::Later, $result->status, $label);
            $this->assertStringContainsString($label, (string) $result->detail);
            $this->assertStringContainsString('app-mail.jp', (string) $result->detail);
        }

        $result = $this->check([]);
        $this->assertStringContainsString('SPF・DMARC', (string) $result->detail);
    }

    public function test_SPF_が引けなければ残りを引かずに_info(): void
    {
        config(['foundation.deploy_check.dkim_selector' => 'mail1']);

        $result = $this->check(['app-mail.jp' => false] + $this->complete());

        $this->assertSame(Status::Info, $result->status);
        $this->assertStringContainsString('foundation.deploy_check.skip', (string) $result->detail);
        $this->assertSame(['app-mail.jp'], $this->asked);
    }

    public function test_DMARC_が引けなければ_DKIM_を引かない(): void
    {
        config(['foundation.deploy_check.dkim_selector' => 'mail1']);

        $result = $this->check(['_dmarc.app-mail.jp' => false] + $this->complete());

        $this->assertSame(Status::Info, $result->status);
        $this->assertSame(['app-mail.jp', '_dmarc.app-mail.jp'], $this->asked);
    }

    public function test_mail_の調べが失敗する設定なら_skip_で引かない(): void
    {
        foreach ([['mail.default' => 'log', 'mail.mailers.log.transport' => 'log'], ['mail.from.address' => 'noreply@example.com']] as $case) {
            config($case);

            $this->assertSame(Status::Skip, $this->check($this->complete())->status);
            $this->assertSame([], $this->asked);
        }
    }

    public function test_skip_に足すと走らない(): void
    {
        config(['foundation.deploy_check.skip' => ['mail_domain']]);

        Artisan::call('foundation:deploy-check', ['--json' => true]);
        $row = collect(json_decode(Artisan::output(), true)['checks'])->firstWhere('id', 'mail_domain');

        $this->assertSame('skip', $row['status']);
    }
}
