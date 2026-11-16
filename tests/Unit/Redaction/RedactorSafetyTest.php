<?php

namespace Miraqceed\Foundation\Tests\Unit\Redaction;

use Miraqceed\Foundation\Redaction\Redactor;
use Miraqceed\Foundation\Redaction\UnreadableText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 素通りさせない・止まらない・印を数え直さない
 */
class RedactorSafetyTest extends TestCase
{
    /** @return array<string, array{0: string}> 種類ごとの文と、印が文脈で値に読まれうる文 */
    public static function textsToRedactTwice(): array
    {
        $texts = [];
        foreach (RedactorKindsTest::eachKind() as $name => [$text]) {
            $texts[$name] = [$text];
        }
        foreach (RedactorKindsTest::quotedOrLongerKeyNames() as $name => [$text]) {
            $texts["key name: {$name}"] = [$text];
        }
        foreach (RedactorKindsTest::passwordsWithSymbols() as $name => [$text]) {
            $texts["symbols: {$name}"] = [$text];
        }
        foreach (RedactorKindsTest::connectionStrings() as $name => [$text]) {
            $texts["connection: {$name}"] = [$text];
        }
        foreach (RedactorKindsTest::quotedValuesOfAnyCharacters() as $name => [$text]) {
            $texts["quoted: {$name}"] = [$text];
        }
        foreach (RedactorKindsTest::emailsFollowedBySymbols() as $name => [$text]) {
            $texts["email: {$name}"] = [$text];
        }

        return $texts + [
            'marker as an unquoted value' => ['password: [REDACTED]'],
            'marker followed by a comma' => ['token=[REDACTED], user=x'],
            'marker as a quoted value' => ['password: "[REDACTED]"'],
            'marker in json' => ['{"password":"[REDACTED]"}'],
            'marker as a connection password' => ['postgres://app:[REDACTED]@localhost/db'],
            'marker as a connection password without user' => ['redis://:[REDACTED]@h'],
            'email followed by a non-domain label' => ['a@example.com.1 に送った'],
        ];
    }

    #[DataProvider('textsToRedactTwice')]
    public function test_redacting_redacted_text_again_finds_nothing(string $text): void
    {
        $redactor = new Redactor;
        $once = $redactor->text($text);
        $twice = $redactor->text($once->value);

        $this->assertSame($once->value, $twice->value);
        $this->assertSame([], $twice->findings);
    }

    public function test_redacting_with_an_added_pattern_again_finds_nothing(): void
    {
        $redactor = new Redactor(patterns: ['myapp_token' => '/(?<![A-Za-z0-9])myapp_[A-Za-z0-9]{32}(?![A-Za-z0-9])/']);
        $once = $redactor->text('MYAPP_TOKEN=myapp_'.str_repeat('Fa1', 10).'ke を使った');
        $twice = $redactor->text($once->value);

        $this->assertSame($once->value, $twice->value);
        $this->assertSame([], $twice->findings);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function longQuotedLines(): array
    {
        return [
            'with closing quote' => ['password: "'.str_repeat('a', 50000).'"', 'password: "[REDACTED]"'],
            'without closing quote' => ['password: "'.str_repeat('a', 50000), 'password: "[REDACTED]'],
        ];
    }

    #[DataProvider('longQuotedLines')]
    public function test_does_not_stop_on_a_very_long_quoted_line(string $text, string $expected): void
    {
        $this->assertJitIsOn();

        $this->assertSame($expected, (new Redactor)->text($text)->value);
    }

    /** @return array<string, array{0: string}> */
    public static function longSchemeLikeLines(): array
    {
        return [
            'long user part' => ['postgres://'.str_repeat('a', 50000)],
            'long password part' => ['postgres://app:'.str_repeat('a', 50000)],
        ];
    }

    #[DataProvider('longSchemeLikeLines')]
    public function test_does_not_stop_on_a_very_long_scheme_like_line(string $text): void
    {
        $this->assertJitIsOn();

        $this->assertSame([], (new Redactor)->text($text)->findings);
    }

    /** @return array<string, array{0: string}> */
    public static function repeatedKeyNames(): array
    {
        return [
            'token_' => [str_repeat('token_', intdiv(100000, 6))],
            'secret-' => [str_repeat('secret-', intdiv(100000, 7))],
            'password ' => [str_repeat('password ', intdiv(100000, 9))],
        ];
    }

    /**
     * 鍵の名前の語が繰り返す文で、かかる時間が字数の二乗で伸びない（利用者の文で重くできない）
     */
    #[DataProvider('repeatedKeyNames')]
    public function test_repeated_key_names_take_linear_time(string $text): void
    {
        $started = microtime(true);
        (new Redactor)->text($text);

        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    /** @return array<string, array{0: string, 1: int}> 文・見つけるメールアドレスの数 */
    public static function longDottedEmailDomains(): array
    {
        return [
            'ends with a top level domain' => ['a@'.str_repeat('b.', 25000).'cc', 1],
            'ends with a digit' => ['a@'.str_repeat('b.', 25000).'1', 0],
        ];
    }

    #[DataProvider('longDottedEmailDomains')]
    public function test_does_not_stop_on_a_long_dotted_email_domain(string $text, int $emails): void
    {
        $this->assertJitIsOn();

        $this->assertCount($emails, (new Redactor)->text($text)->findings);
    }

    /**
     * 壊れた UTF-8 でない PCRE の失敗（ここでは後戻りの上限）も、素通りさせずに止める
     */
    public function test_fails_closed_when_pcre_fails_for_another_reason(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        $caught = null;
        try {
            (new Redactor)->text('password=FakePass1234 と taro@example.com');
        } catch (UnreadableText $e) {
            $caught = $e;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertNotNull($caught, 'UnreadableText が投げられなかった');
        $this->assertStringContainsString('Backtrack limit', $caught->getMessage());
        $this->assertStringNotContainsString('FakePass1234', $caught->getMessage());
        $this->assertStringNotContainsString('taro@example.com', $caught->getMessage());
    }

    /** @return array<string, array{0: string}> */
    public static function markerFragments(): array
    {
        return [
            'cut marker after a key' => ['password=[REDACTE'],
            'cut marker one character short' => ['token=[REDACTED'],
            'cut marker in quotes' => ['password: "[REDACTED'],
        ];
    }

    #[DataProvider('markerFragments')]
    public function test_marker_fragments_are_not_redacted_again(string $text): void
    {
        $result = (new Redactor)->text($text);

        $this->assertSame($text, $result->value);
        $this->assertSame([], $result->findings);
    }

    public function test_fails_closed_on_malformed_utf8_without_echoing_the_text(): void
    {
        $caught = null;
        try {
            (new Redactor)->text("\xff taro@example.com");
        } catch (UnreadableText $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'UnreadableText が投げられなかった');
        $this->assertStringNotContainsString('taro@example.com', $caught->getMessage());
        $this->assertStringContainsString('Malformed UTF-8', $caught->getMessage());
    }

    public function test_fails_closed_on_malformed_utf8_even_with_only_an_added_pattern_without_u(): void
    {
        $this->expectException(UnreadableText::class);

        (new Redactor(kinds: [], patterns: ['myapp_token' => '/myapp_[a-z]{8}/']))->text("\xff myapp_abcdefgh");
    }

    /**
     * 長い行の試しは、PCRE の JIT が有効なときにだけ意味がある（無効の環境では、独占的でない形でも止まらずに緑になる）
     */
    private function assertJitIsOn(): void
    {
        $this->assertTrue((bool) ini_get('pcre.jit'), 'pcre.jit が無効なので、長い行の守りを確かめられない');
    }
}
