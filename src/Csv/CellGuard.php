<?php

namespace Miraqceed\Foundation\Csv;

/**
 * 表計算ソフトで計算される値の先頭に「'」を付ける。全角の記号で始まる値も数式として扱われることがある。
 *
 * 符号付きの数は数として読むだけなので変えない。「'」で始まる値に印が要るかは続きで決める
 * （`'=1` を印と取り違えないように `''=1` にする）。
 */
final class CellGuard
{
    public static function guard(string $value): string
    {
        return self::runsAsFormula(ltrim($value, "'")) ? "'".$value : $value;
    }

    /** guard() の逆。印でない「'」は外さない */
    public static function unguard(string $value): string
    {
        return str_starts_with($value, "'") && self::runsAsFormula(ltrim($value, "'")) ? substr($value, 1) : $value;
    }

    private static function runsAsFormula(string $value): bool
    {
        return preg_match('/^[=+\-@\t\r\n＝＋－＠]/u', $value) === 1
            && preg_match('/^[+\-]?[0-9]*(\.[0-9]+)?$/D', $value) !== 1;
    }
}
