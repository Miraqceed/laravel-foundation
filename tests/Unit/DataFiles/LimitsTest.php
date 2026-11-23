<?php

namespace Miraqceed\Foundation\Tests\Unit\DataFiles;

use InvalidArgumentException;
use Miraqceed\Foundation\DataFiles\Limits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LimitsTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function wrongLimits(): array
    {
        $ok = ['maxDownloadBytes' => 100, 'maxBytes' => 100, 'minBytes' => 1, 'maxShrink' => 0.1, 'staleAfterDays' => 1];

        return [
            '取る大きさ 0' => [[...$ok, 'maxDownloadBytes' => 0]],
            '解いた大きさ 0' => [[...$ok, 'maxBytes' => 0]],
            '下限 0' => [[...$ok, 'minBytes' => 0]],
            '下限が上限より大きい' => [[...$ok, 'minBytes' => 101]],
            '減り 0' => [[...$ok, 'maxShrink' => 0.0]],
            '減り 1' => [[...$ok, 'maxShrink' => 1.0]],
            '減りがマイナス' => [[...$ok, 'maxShrink' => -0.1]],
            '古さ 0' => [[...$ok, 'staleAfterDays' => 0]],
        ];
    }

    /** @param  array<string, mixed>  $arguments */
    #[DataProvider('wrongLimits')]
    public function test_refuses_wrong_limits(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Limits(...$arguments);
    }

    public function test_accepts_null_for_the_optional_checks(): void
    {
        $limits = new Limits(maxDownloadBytes: 10, maxBytes: 20, minBytes: 20, maxShrink: null, staleAfterDays: null);

        $this->assertNull($limits->maxShrink);
        $this->assertNull($limits->staleAfterDays);
    }

    public function test_has_no_defaults(): void
    {
        $constructor = new \ReflectionMethod(Limits::class, '__construct');

        foreach ($constructor->getParameters() as $parameter) {
            $this->assertFalse($parameter->isDefaultValueAvailable(), "{$parameter->getName()} に既定がある");
        }
    }
}
