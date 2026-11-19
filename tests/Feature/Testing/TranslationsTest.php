<?php

namespace Miraqceed\Foundation\Tests\Feature\Testing;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Miraqceed\Foundation\Testing\Translations;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * 訳し忘れを見つける試しの助け：足りない鍵を入れ子まで返し、英語の混ざった誤りの文で失敗する
 */
class TranslationsTest extends TestCase
{
    private string $langPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->langPath = sys_get_temp_dir().'/foundation-translations-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->langPath);
        $app->useLangPath($this->langPath);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->post('/settings/password', function () {
            request()->validate(['current_password' => 'required', 'password' => 'required']);
        });
        $router->post('/api/settings/password', function () {
            request()->validate(['current_password' => 'required']);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->langPath);

        parent::tearDown();
    }

    public function test_写した日本語の文がそろっていれば_足りない鍵はない(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'foundation-lang-ja'])->assertSuccessful();

        $this->assertSame([], Translations::missing('ja'));
    }

    public function test_足りない鍵を_入れ子の鍵まで返す(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'foundation-lang-ja'])->assertSuccessful();
        $lines = require $this->langPath.'/ja/validation.php';
        unset($lines['required'], $lines['max']['string']);
        File::put($this->langPath.'/ja/validation.php', '<?php return '.var_export($lines, true).';');
        File::delete($this->langPath.'/ja/pagination.php');

        $this->assertSame(
            ['validation.max.string', 'validation.required', 'pagination.previous', 'pagination.next'],
            Translations::missing('ja'),
        );
        $this->assertSame(['validation.max.string', 'validation.required'], Translations::missing('ja', ['validation']));
    }

    public function test_欄ごとの文と欄の名前は比べない(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'foundation-lang-ja'])->assertSuccessful();
        // lang:publish で写した英語には、欄ごとの文の例（attribute-name）がある
        File::ensureDirectoryExists($this->langPath.'/en');
        File::put($this->langPath.'/en/validation.php', "<?php return ['custom' => ['attribute-name' => ['rule-name' => 'custom-message']], 'attributes' => ['name' => 'name']];");

        $this->assertSame([], Translations::missing('ja', ['validation']));
    }

    public function test_アプリの_lang_en_の鍵も基準に入る(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'foundation-lang-ja'])->assertSuccessful();
        File::ensureDirectoryExists($this->langPath.'/en');
        File::put($this->langPath.'/en/validation.php', "<?php return ['postal_code' => 'The :attribute field must be a postal code.'];");

        $this->assertSame(['validation.postal_code'], Translations::missing('ja', ['validation']));
    }

    public function test_名前空間を渡すと_パッケージの規則の文の差を返す(): void
    {
        $this->assertSame([], Translations::missing('ja', ['validation'], 'foundation'));

        File::ensureDirectoryExists($this->langPath.'/vendor/foundation/en');
        File::put($this->langPath.'/vendor/foundation/en/validation.php', "<?php return ['postal_code' => 'The :attribute field must be a postal code.'];");

        $this->assertSame(['foundation::validation.postal_code'], Translations::missing('ja', ['validation'], 'foundation'));
    }

    public function test_英語の混ざった文で失敗する(): void
    {
        foreach (['The メールアドレス field is required.', 'current passwordを入力してください。', ':attributeを入力してください。'] as $message) {
            try {
                Translations::assertNoEnglish([$message]);
            } catch (AssertionFailedError $e) {
                $this->assertStringContainsString($message, $e->getMessage());

                continue;
            }

            $this->fail("通ってしまった：{$message}");
        }
    }

    public function test_大文字で始まる語と_拡張子と_許す語は通す(): void
    {
        Translations::assertNoEnglish(['URL の形で入力してください。', '正しい IPv4 アドレスを入力してください。', 'Base64 の文字列にしてください。']);
        Translations::assertNoEnglish(['次の種類のファイルにしてください：jpg, png, pdf。']);
        Translations::assertNoEnglish(['次の値で始めてください：abc。'], ['abc']);

        $this->expectException(AssertionFailedError::class);
        Translations::assertNoEnglish(['次の値で始めてください：abc。']);
    }

    public function test_文が1つもなければ失敗する(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('誤りの文が見つかりません');

        Translations::assertNoEnglish([]);
    }

    public function test_MessageBag_と_入れ子の配列を受ける(): void
    {
        Translations::assertNoEnglish(new MessageBag(['email' => ['メールアドレスを入力してください。']]));
        Translations::assertNoEnglish(['email' => ['メールアドレスを入力してください。']]);

        $this->expectException(AssertionFailedError::class);
        Translations::assertNoEnglish(['email' => ['ok。', 'The email field is required.']]);
    }

    public function test_応答のセッションの誤りを見る(): void
    {
        app()->setLocale('ja');
        File::ensureDirectoryExists($this->langPath.'/ja');
        File::put($this->langPath.'/ja/validation.php', "<?php return ['required' => ':attributeを入力してください。', 'attributes' => ['password' => 'パスワード']];");

        $response = $this->post('/settings/password');
        $response->assertSessionHasErrors(['current_password', 'password']);

        try {
            Translations::assertNoEnglish($response);
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('current passwordを入力してください。', $e->getMessage());
            $this->assertStringNotContainsString('パスワードを入力してください。（', $e->getMessage());

            return;
        }

        $this->fail('欄の名前の置き忘れで失敗しない');
    }

    public function test_応答の_JSON_の誤りを見る(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'foundation-lang-ja'])->assertSuccessful();
        app()->setLocale('ja');

        $response = $this->postJson('/api/settings/password');
        $response->assertJsonValidationErrors('current_password');

        Translations::assertNoEnglish($response);
        $this->assertSame('現在のパスワードを入力してください。', $response->json('errors.current_password.0'));
    }
}
