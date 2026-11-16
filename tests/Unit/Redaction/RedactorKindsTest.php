<?php

namespace Miraqceed\Foundation\Tests\Unit\Redaction;

use Miraqceed\Foundation\Redaction\Finding;
use Miraqceed\Foundation\Redaction\Redactor;
use Miraqceed\Foundation\Tests\Support\FakeSecrets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 組み込みの10の形。値はすべて作り物
 */
class RedactorKindsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> 文・伏せる文字列・種類 */
    public static function eachKind(): array
    {
        return [
            'private_key' => ["鍵を貼った\n".FakeSecrets::privateKey()."\nあとで消した", 'FAKEkeyFAKEkeyFAKEkey', 'private_key'],
            'aws_key' => ['鍵 '.FakeSecrets::awsKey().' を貼ってしまった', FakeSecrets::awsKey(), 'aws_key'],
            'aws_key next to Japanese' => ['鍵'.FakeSecrets::awsKey().'を貼った', FakeSecrets::awsKey(), 'aws_key'],
            'github_token' => [FakeSecrets::githubToken().' を使った', FakeSecrets::githubToken(), 'github_token'],
            'github_pat' => [FakeSecrets::githubPat().' を使った', FakeSecrets::githubPat(), 'github_token'],
            'api_key with ant' => [FakeSecrets::anthropicKey().' を貼った', FakeSecrets::anthropicKey(), 'api_key'],
            'api_key' => [FakeSecrets::apiKey().' を貼った', FakeSecrets::apiKey(), 'api_key'],
            'slack_token' => [FakeSecrets::slackToken().' を貼った', FakeSecrets::slackToken(), 'slack_token'],
            'jwt' => [FakeSecrets::jwt().' を貼った', FakeSecrets::jwt(), 'jwt'],
            'assignment' => ['.env に DB_PASSWORD=FakePass1234 を書いた', 'FakePass1234', 'assignment'],
            'url_password' => ['postgres://app:FakePass1234@localhost:5432/db', 'FakePass1234', 'url_password'],
            'email' => ['taro@example.com に送った', 'taro@example.com', 'email'],
            'phone landline' => ['03-1234-5678 に電話した', '03-1234-5678', 'phone'],
            'phone mobile' => ['090-1234-5678 に電話した', '090-1234-5678', 'phone'],
        ];
    }

    #[DataProvider('eachKind')]
    public function test_redacts_each_kind(string $text, string $secret, string $kind): void
    {
        $result = (new Redactor)->text($text);

        $this->assertStringNotContainsString($secret, $result->value);
        $this->assertStringContainsString('[REDACTED]', $result->value);
        $this->assertEquals([new Finding($kind, '')], $result->findings);
        // 伏せたものの記録に、秘密そのものは残さない
        $this->assertStringNotContainsString($secret, (string) json_encode($result->findings));
    }

    public function test_keeps_the_key_and_separator_of_an_assignment_and_redacts_only_the_value(): void
    {
        $result = (new Redactor)->text('DB_PASSWORD=FakePass1234、api_key: "fakevalue123" と Token = FakeToken99。');

        $this->assertSame('DB_PASSWORD=[REDACTED]、api_key: "[REDACTED]" と Token = [REDACTED]。', $result->value);
        $this->assertSame(['assignment', 'assignment', 'assignment'], array_map(fn (Finding $f) => $f->kind, $result->findings));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function quotedOrLongerKeyNames(): array
    {
        return [
            'json' => ['{"password":"FakePass1234"}', '{"password":"[REDACTED]"}'],
            'json with space' => ['{"token": "FakeTokenValue99"}', '{"token": "[REDACTED]"}'],
            'php array single quotes' => ["'password' => 'FakePass1234',", "'password' => '[REDACTED]',"],
            'php array double quotes' => ['"api_key" => "FakeApiKeyValue99"', '"api_key" => "[REDACTED]"'],
            'secret access key' => ['AWS_SECRET_ACCESS_KEY=FakeSecretAccessKey123', 'AWS_SECRET_ACCESS_KEY=[REDACTED]'],
            'stripe secret key' => ['STRIPE_SECRET_KEY=FakeStripeKey123', 'STRIPE_SECRET_KEY=[REDACTED]'],
            'secret key base' => ['SECRET_KEY_BASE=FakeKeyBase12345', 'SECRET_KEY_BASE=[REDACTED]'],
            'array index single quotes' => ["\$cfg['password'] = 'FakePass1234';", "\$cfg['password'] = '[REDACTED]';"],
            'array index double quotes' => ['$cfg["password"] = "FakePass1234";', '$cfg["password"] = "[REDACTED]";'],
            'env token' => ["ENV['TOKEN'] = 'FakeTokenValue99'", "ENV['TOKEN'] = '[REDACTED]'"],
            // 引用符の中の値は、後ろの括弧も値に含める（引用符のない値だけ、後ろの括弧を外す）
            'quoted value ending with a bracket' => ['password: "Fakepw1)"', 'password: "[REDACTED]"'],
        ];
    }

    #[DataProvider('quotedOrLongerKeyNames')]
    public function test_redacts_assignments_with_quoted_or_longer_key_names(string $text, string $expected): void
    {
        $result = (new Redactor)->text($text);

        $this->assertSame($expected, $result->value);
        $this->assertEquals([new Finding('assignment', '')], $result->findings);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function passwordsWithSymbols(): array
    {
        return [
            'symbols' => ['password=Fa#ke$Pa%ss!12', 'password=[REDACTED]'],
            'closing parenthesis kept' => ['（password=FakePass1234)', '（password=[REDACTED])'],
            'comma kept' => ['password=FakePass1234, user=app', 'password=[REDACTED], user=app'],
            'unclosed opening quote kept' => ['password: "FakePass1234', 'password: "[REDACTED]'],
        ];
    }

    #[DataProvider('passwordsWithSymbols')]
    public function test_redacts_the_whole_value_of_a_password_with_symbols(string $text, string $expected): void
    {
        $this->assertSame($expected, (new Redactor)->text($text)->value);
    }

    public function test_redacts_a_quoted_value_with_spaces_up_to_the_closing_quote(): void
    {
        $result = (new Redactor)->text('password: "Fake Pass 1234" を使った');

        $this->assertSame('password: "[REDACTED]" を使った', $result->value);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function connectionStrings(): array
    {
        return [
            'postgres localhost' => ['postgres://app:FakePass1234@localhost:5432/db', 'postgres://app:[REDACTED]@localhost:5432/db'],
            'mysql ip' => ['mysql://root:FakePass1234@127.0.0.1:3306/x', 'mysql://root:[REDACTED]@127.0.0.1:3306/x'],
            'domain host is not taken as email' => ['postgres://app:FakePass1234@db.example.com/x', 'postgres://app:[REDACTED]@db.example.com/x'],
            'empty user name' => ['redis://:FakePass1234@localhost:6379/0', 'redis://:[REDACTED]@localhost:6379/0'],
        ];
    }

    #[DataProvider('connectionStrings')]
    public function test_redacts_only_the_password_in_a_connection_string(string $text, string $expected): void
    {
        $result = (new Redactor)->text($text);

        $this->assertSame($expected, $result->value);
        $this->assertEquals([new Finding('url_password', '')], $result->findings);
    }

    /** @return array<string, array{0: string}> */
    public static function connectionStringsWithoutPassword(): array
    {
        return [
            'user only' => ['postgres://app@localhost:5432/db'],
            'host and port' => ['http://192.0.2.10:8000/'],
            'no user and no password' => ['redis://localhost:6379/0'],
        ];
    }

    #[DataProvider('connectionStringsWithoutPassword')]
    public function test_leaves_a_connection_string_without_a_password_alone(string $text): void
    {
        $result = (new Redactor)->text($text);

        $this->assertSame($text, $result->value);
        $this->assertSame([], $result->findings);
    }

    public function test_counts_the_more_specific_kind_rather_than_assignment(): void
    {
        $result = (new Redactor)->text('token='.FakeSecrets::githubToken().' と api_key='.FakeSecrets::apiKey());

        $this->assertSame('token=[REDACTED] と api_key=[REDACTED]', $result->value);
        $this->assertEquals([new Finding('github_token', ''), new Finding('api_key', '')], $result->findings);
    }

    public function test_counts_each_match_in_one_text_separately(): void
    {
        $result = (new Redactor)->text('taro@example.com と hanako@example.com に送った');

        $this->assertSame('[REDACTED] と [REDACTED] に送った', $result->value);
        $this->assertEquals([new Finding('email', ''), new Finding('email', '')], $result->findings);
    }

    /** @return array<string, array{0: string}> */
    public static function ordinaryText(): array
    {
        return [
            'short sha' => ['コミット 1a2b3c4 で直した'],
            'count' => ['テストを 3 回流した'],
            'full sha' => ['da39a3ee5e6b4b0d3255bfef95601890afd80709 を戻した'],
            'url' => ['https://laravel.com/docs を読んだ'],
            'short sk-' => ['sk-short123 という名前の枝'],
            'date' => ['2026-11-14 に直した'],
            'version' => ['版を 1.2.3 に上げた'],
            'us style date' => ['09-30-2026 に出した'],
            'token word in Japanese' => ['token: 期限切れで再発行した'],
            'short assignment value' => ['password=abc を試した'],
            'short quoted value' => ['password: "abc" を試した'],
            'short AKIA' => ['AKIA1234 という文字'],
            'long digits that are not a phone number' => ['番号 03-1234-56789 です'],
        ];
    }

    #[DataProvider('ordinaryText')]
    public function test_does_not_flag_ordinary_text(string $text): void
    {
        $result = (new Redactor)->text($text);

        $this->assertSame($text, $result->value);
        $this->assertSame([], $result->findings);
    }
}
