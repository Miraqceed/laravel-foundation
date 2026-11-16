<?php

namespace Miraqceed\Foundation\Tests\Feature\Mail;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Miraqceed\Foundation\Mail\NotReservedEmailDomain;
use Miraqceed\Foundation\Tests\TestCase;

class NotReservedEmailDomainTest extends TestCase
{
    private ?string $langPath = null;

    protected function tearDown(): void
    {
        if ($this->langPath !== null) {
            File::deleteDirectory($this->langPath);
        }

        parent::tearDown();
    }

    private function validate(mixed $value, string $locale = 'ja'): \Illuminate\Validation\Validator
    {
        $this->app->setLocale($locale);

        return Validator::make(['email' => $value], ['email' => [new NotReservedEmailDomain]], [], ['email' => $locale === 'ja' ? 'メールアドレス' : 'email']);
    }

    public function test_予約のアドレスを断る(): void
    {
        $this->assertTrue($this->validate('taro@example.com')->fails());
        $this->assertTrue($this->validate('deleted-5@anonymized.invalid')->fails());
        $this->assertTrue($this->validate('taro@localhost')->fails());
    }

    public function test_文は日本語と英語で欄の名前が入る(): void
    {
        $this->assertSame(
            'メールアドレスには、メールを届けられません。普段お使いのメールアドレスを入れてください。',
            $this->validate('taro@example.com')->errors()->first('email'),
        );
        $this->assertSame(
            'The email cannot receive email. Please use an address you actually use.',
            $this->validate('taro@example.com', 'en')->errors()->first('email'),
        );
    }

    public function test_予約でない値と文字列でない値は通す(): void
    {
        $this->assertTrue($this->validate('taro@gmail.com')->passes());
        $this->assertTrue($this->validate(null)->passes());
        $this->assertTrue($this->validate(['taro@example.com'])->passes());
        $this->assertTrue($this->validate(123)->passes());
    }

    public function test_アプリが文を上書きできる(): void
    {
        $this->langPath = sys_get_temp_dir().'/foundation-lang-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->langPath.'/vendor/foundation/ja');
        File::put($this->langPath.'/vendor/foundation/ja/validation.php', "<?php\n\nreturn ['reserved_email' => ':attributeは届かないアドレスです。'];\n");

        $this->app->useLangPath($this->langPath);
        // 訳は読んだ言語のものを覚えるので、置き場所を変えた後に読み直す
        $this->app->forgetInstance('translator');
        $this->app->forgetInstance('translation.loader');
        Validator::clearResolvedInstances();
        $this->app->forgetInstance('validator');

        $this->assertSame('メールアドレスは届かないアドレスです。', $this->validate('taro@example.com')->errors()->first('email'));
    }
}
