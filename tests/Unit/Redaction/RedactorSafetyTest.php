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

        return $texts + [
            'marker as an unquoted value' => ['password: [REDACTED]'],
            'marker followed by a comma' => ['token=[REDACTED], user=x'],
            'marker as a quoted value' => ['password: "[REDACTED]"'],
            'marker in json' => ['{"password":"[REDACTED]"}'],
            'marker as a connection password' => ['postgres://app:[REDACTED]@localhost/db'],
            'marker as a connection password without user' => ['redis://:[REDACTED]@h'],
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
