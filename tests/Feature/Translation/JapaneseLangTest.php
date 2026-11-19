<?php

namespace Miraqceed\Foundation\Tests\Feature\Translation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\Translator;
use Miraqceed\Foundation\Tests\TestCase;
use ReflectionClass;

/**
 * 写す日本語の文（lang-ja）：入れた Laravel の英語の鍵がそろい、英語が混ざらず、写すときに上書きしない
 */
class JapaneseLangTest extends TestCase
{
    private const GROUPS = ['validation', 'auth', 'passwords', 'pagination'];

    /**
     * 入れた Laravel の英語にない鍵のうち、日本語のファイルに置いてよいもの（支えるほかの版にある鍵）。
     * どの版にもない鍵（書き損じ）は、この一覧にないので落ちる
     */
    private const OTHER_VERSION_KEYS = [
        'validation.any_of' => 'Laravel 12.x の途中で足された（12.0 にない）',
        'validation.in_array_keys' => 'Laravel 12.x の途中で足された（12.0 にない）',
        'validation.doesnt_contain' => 'Laravel 12.x の途中で足された（12.0 にない）',
        'validation.encoding' => 'Laravel 12.x の途中で足された（12.0 にない）',
        'validation.array_keys' => 'Laravel 13.x の途中で足された（13.0 にない）',
        'validation.base64' => 'Laravel 13.x の途中で足された（13.0 にない）',
    ];

    /** 欄ごとの文と欄の名前は、アプリが書く（英語の例と比べない） */
    private const APP_SPECIFIC = ['validation.custom', 'validation.attributes'];

    private string $langPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // 写す先は試しごとの空の lang（publish の行き先は、プロバイダーの起動のときの lang の道で決まる）
        $this->langPath = sys_get_temp_dir().'/foundation-lang-ja-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->langPath);
        $app->useLangPath($this->langPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->langPath);

        parent::tearDown();
    }

    public function test_入れた_Laravel_の英語の鍵がすべてある(): void
    {
        foreach (self::GROUPS as $group) {
            $missing = array_diff($this->keys($this->english($group), $group), $this->keys($this->japanese($group), $group));

            $this->assertSame([], array_values($missing), "lang-ja/{$group}.php に足りない鍵");
        }
    }

    public function test_余計な鍵は_支えるほかの版にある鍵だけ(): void
    {
        foreach (self::GROUPS as $group) {
            $extra = array_diff($this->keys($this->japanese($group), $group), $this->keys($this->english($group), $group));
            $unknown = array_diff($extra, array_keys(self::OTHER_VERSION_KEYS));

            $this->assertSame([], array_values($unknown), "lang-ja/{$group}.php の、どの版にもない鍵");
        }
    }

    public function test_英語の語がなく_置き換えの印が英語と同じで_文末が句点(): void
    {
        foreach (self::GROUPS as $group) {
            $english = $this->lines($this->english($group), $group);

            foreach ($this->lines($this->japanese($group), $group) as $key => $line) {
                // 置き換えの印（:attribute）と HTML の文字参照（&laquo;）を除いて数える
                $text = preg_replace(['/:[a-z_]+/', '/&[a-z]+;/'], '', $line);
                $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z])[a-z]{2,}(?![A-Za-z])/', $text, "{$key}：{$line}");

                if (isset($english[$key])) {
                    $this->assertSame($this->placeholders($english[$key]), $this->placeholders($line), "{$key} の置き換えの印");
                }

                // ページ送りは文でなく、ボタンの言葉
                if ($group !== 'pagination') {
                    $this->assertStringEndsWith('。', $line, $key);
                }
            }
        }
    }

    public function test_欄の名前は_どの画面でも同じ呼び名の4つだけ(): void
    {
        $this->assertSame(
            ['email', 'password', 'password_confirmation', 'current_password'],
            array_keys($this->japanese('validation')['attributes']),
        );
        $this->assertSame([], $this->japanese('validation')['custom']);
    }

    public function test_写すと_lang_ja_に入り_既にあるファイルは上書きしない(): void
    {
        File::ensureDirectoryExists($this->langPath.'/ja');
        File::put($this->langPath.'/ja/validation.php', "<?php\n\nreturn ['required' => 'アプリの文。'];\n");

        $this->artisan('vendor:publish', ['--tag' => 'foundation-lang-ja'])->assertSuccessful();

        $this->assertSame("<?php\n\nreturn ['required' => 'アプリの文。'];\n", File::get($this->langPath.'/ja/validation.php'));
        foreach (['auth', 'passwords', 'pagination'] as $group) {
            $this->assertFileEquals(__DIR__."/../../../lang-ja/{$group}.php", $this->langPath."/ja/{$group}.php");
        }
    }

    public function test_写さなければ_日本語の文は使われない(): void
    {
        app()->setLocale('ja');

        $validator = Validator::make(['email' => ''], ['email' => 'required']);

        $this->assertSame('The email field is required.', $validator->errors()->first('email'));
    }

    public function test_写した文で_誤りが日本語になり_欄の名前が入る(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'foundation-lang-ja'])->assertSuccessful();
        app()->setLocale('ja');

        $validator = Validator::make(
            ['email' => 'not-an-address', 'name' => str_repeat('あ', 21), 'password' => 'secret-1', 'password_confirmation' => 'secret-2', 'current_password' => ''],
            ['email' => 'email', 'name' => 'max:20', 'password' => 'confirmed', 'current_password' => 'required'],
        );

        $this->assertSame([
            'email' => ['メールアドレスには、正しいメールアドレスを入力してください。'],
            'name' => ['nameは、20文字以内にしてください。'],
            'password' => ['パスワードが、確認の入力と一致しません。'],
            'current_password' => ['現在のパスワードを入力してください。'],
        ], $validator->errors()->toArray());
    }

    /** @return array<string, mixed> */
    private function english(string $group): array
    {
        $dir = dirname((string) (new ReflectionClass(Translator::class))->getFileName()).'/lang/en';

        return require "{$dir}/{$group}.php";
    }

    /** @return array<string, mixed> */
    private function japanese(string $group): array
    {
        return require __DIR__."/../../../lang-ja/{$group}.php";
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return list<string>
     */
    private function keys(array $lines, string $prefix): array
    {
        return array_keys($this->lines($lines, $prefix));
    }

    /**
     * 入れ子の鍵を点でつないだ鍵と、その文（欄ごとの文と欄の名前は除く）
     *
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string>
     */
    private function lines(array $lines, string $prefix): array
    {
        if (in_array($prefix, self::APP_SPECIFIC, true)) {
            return [];
        }

        $flat = [];
        foreach ($lines as $key => $value) {
            $flat += is_array($value) ? $this->lines($value, "{$prefix}.{$key}") : ["{$prefix}.{$key}" => $value];
        }

        return $flat;
    }

    /** @return list<string> */
    private function placeholders(string $line): array
    {
        preg_match_all('/:[a-z_]+/', $line, $matches);
        $found = array_values(array_unique($matches[0]));
        sort($found);

        return $found;
    }
}
