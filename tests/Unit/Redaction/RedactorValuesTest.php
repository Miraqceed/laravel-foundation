<?php

namespace Miraqceed\Foundation\Tests\Unit\Redaction;

use Miraqceed\Foundation\Redaction\Finding;
use Miraqceed\Foundation\Redaction\Redactor;
use Miraqceed\Foundation\Redaction\UnreadableText;
use Miraqceed\Foundation\Tests\Support\FakeSecrets;
use PHPUnit\Framework\TestCase;

/**
 * 入れ子の配列。文字列の値だけを伏せ、キーと文字列でない値には触れない
 */
class RedactorValuesTest extends TestCase
{
    public function test_redacts_only_string_values_and_keeps_keys_and_other_values(): void
    {
        $result = (new Redactor)->values([
            'evidence' => ['refs' => ['https://laravel.com/docs', '連絡は taro@example.com へ']],
            'case' => ['what_happened' => '03-1234-5678 に電話した'],
            'count' => 3,
            'flag' => true,
            'none' => null,
        ]);

        $this->assertSame([
            'evidence' => ['refs' => ['https://laravel.com/docs', '連絡は [REDACTED] へ']],
            'case' => ['what_happened' => '[REDACTED] に電話した'],
            'count' => 3,
            'flag' => true,
            'none' => null,
        ], $result->value);
        $this->assertEquals([
            new Finding('email', 'evidence.refs.1'),
            new Finding('phone', 'case.what_happened'),
        ], $result->findings);
    }

    public function test_does_not_redact_keys(): void
    {
        $key = 'note '.FakeSecrets::githubToken();

        $result = (new Redactor)->values([$key => 'ふつうの文']);

        $this->assertSame([$key => 'ふつうの文'], $result->value);
    }

    public function test_a_secret_in_a_key_does_not_reach_the_findings(): void
    {
        $result = (new Redactor)->values([FakeSecrets::githubToken() => ['body' => 'taro@example.com']]);

        $this->assertEquals([new Finding('email', '[REDACTED].body')], $result->findings);
        $this->assertStringNotContainsString(FakeSecrets::githubToken(), (string) json_encode($result->findings));
    }

    public function test_a_secret_in_a_key_does_not_reach_the_exception(): void
    {
        $caught = null;
        try {
            (new Redactor)->values([FakeSecrets::githubToken() => ['body' => "\xff 壊れた文"]]);
        } catch (UnreadableText $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'UnreadableText が投げられなかった');
        $this->assertStringNotContainsString(FakeSecrets::githubToken(), $caught->getMessage());
        $this->assertStringContainsString('[REDACTED].body', $caught->getMessage());
    }

    public function test_a_malformed_key_does_not_reach_the_exception(): void
    {
        $caught = null;
        try {
            (new Redactor)->values(["\xff key" => "\xff 壊れた文"]);
        } catch (UnreadableText $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'UnreadableText が投げられなかった');
        $this->assertTrue(mb_check_encoding($caught->getMessage(), 'UTF-8'), '例外の文が UTF-8 として読める');
    }
}
