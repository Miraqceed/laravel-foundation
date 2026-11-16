<?php

namespace Miraqceed\Foundation\Tests\Feature\Redaction;

use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Redaction\InvalidRedactionConfig;
use Miraqceed\Foundation\Redaction\Redactor;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 伏せる部品の作り方と設定。設定の書き違いは、作るときに分かる例外にする（黙って既定に寄せない）
 */
class RedactorConfigTest extends TestCase
{
    public function test_default_marker_and_all_kinds(): void
    {
        $redactor = new Redactor;

        $this->assertSame('[REDACTED]', $redactor->marker());
        $this->assertSame(Redactor::KINDS, $redactor->kinds());
    }

    public function test_container_builds_from_config(): void
    {
        config(['foundation.redaction' => [
            'marker' => '<hidden>',
            'kinds' => ['email'],
            'patterns' => ['myapp_token' => '/myapp_[a-z0-9]{32}/'],
        ]]);

        $redactor = $this->app->make(Redactor::class);

        $this->assertSame('<hidden>', $redactor->marker());
        $this->assertSame(['email', 'myapp_token'], $redactor->kinds());
    }

    public function test_package_defaults_fill_redaction_config(): void
    {
        config(['foundation' => ['redaction' => ['marker' => '<hidden>']]]);
        (new FoundationServiceProvider($this->app))->register();

        $this->assertSame('<hidden>', config('foundation.redaction.marker'));
        $this->assertNull(config('foundation.redaction.kinds'));
        $this->assertSame([], config('foundation.redaction.patterns'));
    }

    public function test_unknown_kind_is_rejected_with_its_name(): void
    {
        $e = $this->captureConfigError(fn () => new Redactor(kinds: ['email', 'credit_card']));

        $this->assertStringContainsString('credit_card', $e->getMessage());
    }

    public function test_pattern_that_does_not_compile_is_rejected(): void
    {
        $e = $this->captureConfigError(fn () => new Redactor(patterns: ['myapp_token' => '/myapp_[a-z/']));

        $this->assertStringContainsString('myapp_token', $e->getMessage());
    }

    public function test_pattern_named_like_a_built_in_kind_is_rejected(): void
    {
        $e = $this->captureConfigError(fn () => new Redactor(patterns: ['email' => '/x{8}/']));

        $this->assertStringContainsString('email', $e->getMessage());
    }

    public function test_pattern_that_matches_the_marker_is_rejected(): void
    {
        $e = $this->captureConfigError(fn () => new Redactor(patterns: ['shouting' => '/[A-Z]{8,}/']));

        $this->assertStringContainsString('shouting', $e->getMessage());
    }

    public function test_marker_that_a_built_in_kind_matches_is_rejected(): void
    {
        $this->captureConfigError(fn () => new Redactor(marker: 'hidden@example.com'));
    }

    public function test_empty_marker_is_rejected(): void
    {
        $this->captureConfigError(fn () => new Redactor(marker: ''));
    }

    /**
     * 例外を受けて返す。型を絞って受け、PHPUnit の失敗（RuntimeException の子）を飲み込まない
     */
    private function captureConfigError(\Closure $make): InvalidRedactionConfig
    {
        $caught = null;
        try {
            $make();
        } catch (InvalidRedactionConfig $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'InvalidRedactionConfig が投げられなかった');

        return $caught;
    }
}
