<?php

namespace Miraqceed\Foundation\Tests\Support\DataFiles;

use Carbon\CarbonImmutable;
use Closure;
use Miraqceed\Foundation\DataFiles\DataSource;
use Miraqceed\Foundation\DataFiles\Download;
use Miraqceed\Foundation\DataFiles\Limits;
use RuntimeException;

/**
 * 試しの種類。データは作り物の形：1行目 "VERSION: 数"、最後の行 "END"。
 * verify() は形を見て版（整数）を返し、終わりの行がなければ投げる（切れたものを断る）
 */
class TestSource implements DataSource
{
    /** @var list<Download>|Closure(CarbonImmutable): iterable<Download> */
    public array|Closure $downloads = [];

    public Limits $limits;

    public ?string $fallback = null;

    /** @var (Closure(string): (string|int|null))|null */
    public ?Closure $verifier = null;

    /** @var list<string> verify() を呼んだ道 */
    public array $verified = [];

    public function __construct(public string $sourceName = 'sample')
    {
        $this->limits = new Limits(maxDownloadBytes: 1024 * 1024, maxBytes: 1024 * 1024, minBytes: 16, maxShrink: 0.5, staleAfterDays: 7);
    }

    public function name(): string
    {
        return $this->sourceName;
    }

    public function downloads(CarbonImmutable $now): iterable
    {
        return $this->downloads instanceof Closure ? ($this->downloads)($now) : $this->downloads;
    }

    public function limits(): Limits
    {
        return $this->limits;
    }

    public function verify(string $path): string|int|null
    {
        $this->verified[] = $path;
        if ($this->verifier !== null) {
            return ($this->verifier)($path);
        }

        return self::versionOf((string) file_get_contents($path));
    }

    public function fallbackPath(): ?string
    {
        return $this->fallback;
    }

    /** 作り物のデータ（版・中身の行の数） */
    public static function data(int $version, int $lines = 20): string
    {
        $body = "VERSION: {$version}\n";
        for ($i = 1; $i <= $lines; $i++) {
            $body .= "row-{$version}-{$i}.example.test\n";
        }

        return $body."END\n";
    }

    /** 形が合えば版、合わなければ投げる */
    public static function versionOf(string $text): int
    {
        if (preg_match('/\AVERSION: ([0-9]{1,9})\n/', $text, $match) !== 1 || ! str_ends_with($text, "\nEND\n")) {
            throw new RuntimeException('not a sample data file');
        }

        return (int) $match[1];
    }
}
