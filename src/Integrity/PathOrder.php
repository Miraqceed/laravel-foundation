<?php

namespace Miraqceed\Foundation\Integrity;

/**
 * 一覧の項目の並び（書く側と読む側で同じものを使う）。道は根からの、/ で区切った道。
 *
 * - 最上の段は組の順：public/… → bootstrap/… → 根の直下で終わる道（ファイル・リンク）→ .git/… → ほかの …/… → vendor/…。
 *   組は lstat の種類でなく道の形（その段で終わるか、下へ続くか）で決める
 * - 2段目からは：最初に違う段で、片方がそこで終わるなら、それが前（ファイル・リンクを先、下のフォルダーを後）。
 *   どちらも終わる・どちらも続くなら strcmp（バイトの比べ。sort() の既定は数字に見える名前を数として比べる）
 *
 * @internal
 */
final class PathOrder
{
    /** 最上の段の組の順。$leaf はその段で終わるか */
    public static function group(string $first, bool $leaf): int
    {
        if ($leaf) {
            return 2;
        }

        return match ($first) {
            'public' => 0,
            'bootstrap' => 1,
            '.git' => 3,
            'vendor' => 5,
            default => 4,
        };
    }

    public static function compare(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $x = explode('/', $a);
        $y = explode('/', $b);

        $groupA = self::group($x[0], count($x) === 1);
        $groupB = self::group($y[0], count($y) === 1);
        if ($groupA !== $groupB) {
            return $groupA <=> $groupB;
        }

        $length = min(count($x), count($y));
        for ($i = 0; $i < $length; $i++) {
            $leafA = $i === count($x) - 1;
            $leafB = $i === count($y) - 1;
            if ($x[$i] === $y[$i] && $leafA === $leafB) {
                continue;
            }
            if ($leafA !== $leafB) {
                return $leafA ? -1 : 1;
            }

            return strcmp($x[$i], $y[$i]) <=> 0;
        }

        return count($x) <=> count($y);
    }

    /**
     * 1つのフォルダーの名前を並べる：ふつうのファイルとリンクを先、下のフォルダーを後、それぞれ strcmp
     *
     * @param  list<string>  $leaves
     * @param  list<string>  $folders
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function sortFolder(array $leaves, array $folders): array
    {
        sort($leaves, SORT_STRING);
        sort($folders, SORT_STRING);

        return [$leaves, $folders];
    }
}
