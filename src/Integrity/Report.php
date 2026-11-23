<?php

namespace Miraqceed\Foundation\Integrity;

use Closure;

/**
 * 1回の調べの結果：違いと確かめられなかったところ。名前を持つ件数に上限がある（違い 1,000・道ごとの確かめられなかった 100）。
 * 上限を超えた分は、種類ごとの数だけを持つ。見つけた時に出力の1行を書く（$printer）。終わりを待たない
 */
final class Report
{
    /** @var list<array{0: Finding, 1: string}> */
    private array $findings = [];

    /** @var array<string, int> 種類ごとの数（上限を超えた分も） */
    private array $findingCounts = [];

    /** @var array<string, array{0: Gap, 1: string}> */
    private array $gaps = [];

    /** @var array<string, int> */
    private array $gapCounts = [];

    private int $perPathGaps = 0;

    /** 一覧で比べたファイルの数 */
    public ?int $files = null;

    /** 画面の調べの形（child・in-process・none） */
    public string $viewsMode = 'none';

    /** 親の中で流した画面の調べが、区切りの知らせの後に発見を足した */
    public bool $viewsChanged = false;

    /** @param  (Closure(string): void)|null  $printer  1行ずつ書く口 */
    public function __construct(private readonly ?Closure $printer = null) {}

    public function add(Finding $finding, string $path): void
    {
        $this->findingCounts[$finding->value] = ($this->findingCounts[$finding->value] ?? 0) + 1;
        if (count($this->findings) >= Limits::FINDINGS_KEPT) {
            return;
        }
        $this->findings[] = [$finding, $path];
        $this->print("[{$finding->value}] ".self::display($path));
    }

    public function notChecked(Gap $gap, string $detail = ''): void
    {
        $key = $gap->value.':'.$detail;
        if (isset($this->gaps[$key])) {
            return;
        }
        $this->gapCounts[$gap->value] = ($this->gapCounts[$gap->value] ?? 0) + 1;
        if ($gap->isPerPath()) {
            if ($this->perPathGaps >= Limits::GAPS_KEPT) {
                return;
            }
            $this->perPathGaps++;
        }
        $this->gaps[$key] = [$gap, $detail];
        $this->print('[not checked] '.$gap->value.($detail === '' ? '' : ' ('.self::display($detail).')'));
    }

    /** @return list<array{0: Finding, 1: string}> */
    public function findings(): array
    {
        return $this->findings;
    }

    /** @return list<array{0: Gap, 1: string}> */
    public function gaps(): array
    {
        return array_values($this->gaps);
    }

    public function findingCount(): int
    {
        return array_sum($this->findingCounts);
    }

    public function gapCount(): int
    {
        return array_sum($this->gapCounts);
    }

    /** @return array<string, int> */
    public function findingCounts(): array
    {
        return $this->findingCounts;
    }

    public function hasGap(Gap $gap): bool
    {
        return isset($this->gapCounts[$gap->value]);
    }

    public function isClean(): bool
    {
        return $this->findingCounts === [] && $this->gapCounts === [];
    }

    /** 違いと確かめられなかったところの組の指紋（知らせの鍵。上限を超えた分は種類ごとの数で入る） */
    public function fingerprint(): string
    {
        $parts = [];
        foreach ($this->findings as [$finding, $path]) {
            $parts[] = 'f:'.$finding->value.':'.$path;
        }
        foreach ($this->gaps as [$gap, $detail]) {
            $parts[] = 'g:'.$gap->value.':'.$detail;
        }
        sort($parts, SORT_STRING);
        ksort($this->findingCounts, SORT_STRING);

        return sha1(implode("\n", $parts)."\n".json_encode($this->findingCounts).json_encode($this->gapCounts));
    }

    private function print(string $line): void
    {
        if ($this->printer !== null) {
            ($this->printer)($line);
        }
    }

    /**
     * 名前の見せ方：置いた者が選べるので、正しい UTF-8 だけにし（壊れた並びは ?）、制御の字・書式の字・行と段落の区切り・\ を書き出す
     * （偽の行・x<U+202E>gpj.php が xphp.jpg に見えることを防ぐ）
     */
    public static function display(string $path): string
    {
        return (string) preg_replace_callback(
            '/[\\\\\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u',
            fn (array $match): string => match ($match[0]) {
                '\\' => '\\\\',
                "\n" => '\n',
                "\r" => '\r',
                "\t" => '\t',
                default => sprintf('\u{%04X}', mb_ord($match[0], 'UTF-8')),
            },
            mb_scrub($path, 'UTF-8'),
        );
    }

    /** 知らせの本文の名前：display() に加え、送り先の記法（Slack の <!channel> など）が効かないよう & < > を書き換える */
    public static function forAlert(string $path): string
    {
        return htmlspecialchars(self::display($path), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
