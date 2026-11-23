<?php

namespace Miraqceed\Foundation\Integrity;

/**
 * メモリーと数の上限（docs/integrity.md の「メモリーの上限」）。メモリーが尽きる誤りは受け止められないので、
 * 外の者が決める数に比例する所には、すべて上限を付ける。上限は memory_limit から決める（-1 は 1GB とみなす）。
 *
 * @internal
 */
final class Limits
{
    /** 歩きの深さ（PATH_MAX の内で作れる深さより小さく、ふつうの木より十分深い） */
    public const DEPTH = 256;

    /** 名前1つの長さ（NAME_MAX） */
    public const NAME_BYTES = 255;

    /** 名前を持つ違いの件数（後は種類ごとの数だけ） */
    public const FINDINGS_KEPT = 1000;

    /** 名前を持つ「確かめられなかった」の件数（違いと別の枠） */
    public const GAPS_KEPT = 100;

    /** 一覧の項目の行の長さ（改行を含まない） */
    public const LINE_BYTES = 20480;

    /** 一覧の頭の行の長さ */
    public const HEAD_BYTES = 65536;

    /** 画面の元・書いたものの、大きさの上の上限 */
    public const VIEW_SOURCE_BYTES = 2 * 1024 * 1024;

    public const VIEW_COMPILED_BYTES = 8 * 1024 * 1024;

    /**
     * 画面の見積もり：元の大きさ × A ＋ 部品の印でない広がる印の数 × B ＋ 部品の印の数 × C（バイト）。
     * compileString() だけを通したときの増え方から決めた
     * （docs/integrity.md の表。appendFilePath() は呼ばない）
     */
    public const VIEW_BYTES_FACTOR = 64;

    public const VIEW_MARK_BYTES = 2048;

    /**
     * 部品の印（<x-）1つの重さ。部品の印は、1つで千数百バイトの出力に広がり、compileString() の中で印1つあたり
     * 約 11〜15KB を使う（測った値。docs/integrity.md の表）。余裕を見て 16KB
     */
    public const VIEW_COMPONENT_BYTES = 16384;

    /**
     * 1つの元の、広がる印の数の上限。印の数はコンパイルの時間も決める（@props を並べた 256KB・約1万9千の印で約42秒。
     * compileString() の中は期限を見られない）。子は親が期限で止められるので多め、親の中は少なめ
     */
    public const VIEW_MARKS_CHILD = 20_000;

    public const VIEW_MARKS_IN_PROCESS = 2_000;

    /** 網：memory_limit のこの割合を超えたら止める */
    public const BUDGET_RATIO = 0.6;

    /** 子プロセスの出力の全体と行の長さ */
    public const CHILD_OUTPUT_BYTES = 1024 * 1024;

    /** -1（上限なし）のときにみなす大きさ */
    private const UNLIMITED_BYTES = 1024 * 1024 * 1024;

    /** @var array<string, int>|null 試しの継ぎ目（個別の上限を外す）。本番のコードからは呼ばない */
    private static ?array $overrides = null;

    /** @param  array<string, int>|null  $overrides  'n'・'depth'・'view_estimate'（試しで個別の上限を外す。網が最後に守ることを確かめるため） */
    public static function overrideForTests(?array $overrides): void
    {
        self::$overrides = $overrides;
    }

    /** memory_limit（バイト）。-1・読めない値は 1GB */
    public static function memoryLimit(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return self::UNLIMITED_BYTES;
        }

        $number = (int) $value;
        $unit = strtolower(substr($value, -1));
        $bytes = match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };

        return $bytes > 0 ? $bytes : self::UNLIMITED_BYTES;
    }

    /** 網の上限（バイト） */
    public static function budget(): int
    {
        return (int) (self::memoryLimit() * self::BUDGET_RATIO);
    }

    /** N：1つのフォルダーの項目の数・スタックが持つ名前の合計の件数 */
    public static function n(): int
    {
        if (isset(self::$overrides['n'])) {
            return self::$overrides['n'];
        }

        return min(200_000, max(10_000, intdiv(self::memoryLimit(), 4096)));
    }

    public static function depth(): int
    {
        return self::$overrides['depth'] ?? self::DEPTH;
    }

    /** storage/framework/down の大きさの上限 */
    public static function downBytes(): int
    {
        return min(1024 * 1024, intdiv(self::memoryLimit(), 64));
    }

    /** 画面のコンパイルの見積もり（バイト） */
    public static function viewEstimate(int $bytes, int $marks, int $components = 0): int
    {
        if (isset(self::$overrides['view_estimate'])) {
            return self::$overrides['view_estimate'];
        }

        return $bytes * self::VIEW_BYTES_FACTOR + max(0, $marks - $components) * self::VIEW_MARK_BYTES + $components * self::VIEW_COMPONENT_BYTES;
    }

    /** 広がる印（@・{{・{!!・<x-）の数。中身を解かずに数える */
    public static function viewMarks(string $source): int
    {
        return substr_count($source, '@') + substr_count($source, '{{') + substr_count($source, '{!!') + substr_count($source, '<x-');
    }

    /** 部品の印（<x-）の数 */
    public static function viewComponents(string $source): int
    {
        return substr_count($source, '<x-');
    }
}
