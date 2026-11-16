<?php

namespace Miraqceed\Foundation\Tests\Unit\Redaction;

use Miraqceed\Foundation\Redaction\Finding;
use Miraqceed\Foundation\Redaction\Redactor;
use Miraqceed\Foundation\Tests\Support\FakeSecrets;
use PHPUnit\Framework\TestCase;

/**
 * 種類を絞る・形を足す
 */
class RedactorOptionsTest extends TestCase
{
    public function test_restricting_kinds_leaves_other_kinds_alone(): void
    {
        $redactor = new Redactor(kinds: ['github_token']);

        $result = $redactor->text('taro@example.com が '.FakeSecrets::githubToken().' を貼った');

        $this->assertSame('taro@example.com が [REDACTED] を貼った', $result->value);
        $this->assertEquals([new Finding('github_token', '')], $result->findings);
    }

    public function test_an_added_pattern_is_counted_as_its_own_kind_before_assignment(): void
    {
        $token = 'myapp_'.str_repeat('Fa1', 10).'ke';
        $redactor = new Redactor(patterns: ['myapp_token' => '/(?<![A-Za-z0-9])myapp_[A-Za-z0-9]{32}(?![A-Za-z0-9])/']);

        $result = $redactor->text("MYAPP_TOKEN={$token} を使った");

        $this->assertSame('MYAPP_TOKEN=[REDACTED] を使った', $result->value);
        $this->assertEquals([new Finding('myapp_token', '')], $result->findings);
    }

    public function test_an_added_pattern_works_with_no_built_in_kinds(): void
    {
        $redactor = new Redactor(kinds: [], patterns: ['ticket' => '/T-[0-9]{6}/']);

        $result = $redactor->text('T-123456 と taro@example.com');

        $this->assertSame('[REDACTED] と taro@example.com', $result->value);
        $this->assertSame(['ticket'], $redactor->kinds());
    }
}
