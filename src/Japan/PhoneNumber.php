<?php

namespace Miraqceed\Foundation\Japan;

/**
 * 日本の電話番号を、ハイフンで区切った形にする。桁の数で推測しない：
 *
 * - ハイフン・括弧・空白で3つに区切って入力されたら、その区切りを使う（0120 を「0120-98-8372」と書く会社がある）
 * - 数字だけなら、携帯電話・IP 電話・フリーダイヤルなどは番号の種類の決まりで、固定電話は市外局番の表
 *   （resources/japan/area-codes.json。総務省の「電気通信番号指定状況」を加工して作成）で区切る
 * - 区切りが決まらない番号は null
 */
final class PhoneNumber
{
    /** 市外局番の表（パッケージに入れる。作り直しは保守の道具 tools/make-area-codes.mjs） */
    public const AREA_CODES = __DIR__.'/../../resources/japan/area-codes.json';

    /** ハイフンとして読む記号（全角・長音の記号・ダッシュ）。全角の数字と記号は mb_convert_kana で半角にしてから読む */
    private const DASHES = '/[‐‑‒–—―−ーｰ－]/u';

    /** 区切って入力された形（3つの数字の並び。0 から始まる2〜5桁・1〜4桁・3〜4桁） */
    private const GROUPED = '/^(0\d{1,4})-(\d{1,4})-(\d{3,4})$/';

    /** @var array<string, int>|null 前方一致の表（0 から始まる番号 → 0 を含めた市外局番の桁）。呼んだときだけ読む */
    private static ?array $areaCodes = null;

    /** 区切った形（03-1234-5678）。区切りが決まらない・番号の形でなければ null */
    public static function format(?string $input): ?string
    {
        $groups = self::groupsOf($input);
        if ($groups === null) {
            return null;
        }
        $grouped = implode('-', $groups);
        if (count($groups) === 3 && preg_match(self::GROUPED, $grouped)) {
            return $grouped;
        }
        $parts = self::partsOf(implode('', $groups));

        return $parts === null ? null : implode('-', $parts);
    }

    /**
     * 区切れない理由。番号の形でない（使えない文字・0 から始まる10桁か11桁でない）は 'shape'、
     * 形は合うが区切りが決まらないは 'grouping'、区切れるなら null。断る文言を分ける
     * （区切っても通らない番号に「ハイフンで区切って」と言わない）
     */
    public static function problem(?string $input): ?string
    {
        return match (true) {
            self::groupsOf($input) === null => 'shape',
            self::format($input) === null => 'grouping',
            default => null,
        };
    }

    /**
     * 全角の数字と記号を半角に、ハイフンとして読む記号を「-」にし、前後の空白を除く。郵便番号も同じ読み方をする（PostalCode）。
     * 壊れた UTF-8 は null
     */
    public static function normalize(?string $input): ?string
    {
        $input ??= '';
        if (! mb_check_encoding($input, 'UTF-8')) {
            return null;
        }

        return trim((string) preg_replace(self::DASHES, '-', mb_convert_kana($input, 'as', 'UTF-8')));
    }

    /**
     * 数字・ハイフン・括弧・空白だけで書いた、0 から始まる10桁か11桁の番号の数字の並び。番号の形でなければ null
     *
     * @return list<string>|null
     */
    private static function groupsOf(?string $input): ?array
    {
        $text = self::normalize($input);
        if ($text === null || ! preg_match('/\A[0-9()\s-]+\z/', $text)) {
            return null;
        }
        $groups = preg_split('/[^0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return preg_match('/^0\d{9,10}$/', implode('', $groups)) ? $groups : null;
    }

    /**
     * 数字だけの番号を、番号の種類の決まりと市外局番の表で区切る
     *
     * @return list<string>|null
     */
    private static function partsOf(string $digits): ?array
    {
        $cut = fn (int $first, int $second, int $third) => [
            substr($digits, 0, $first),
            substr($digits, $first, $second),
            substr($digits, $first + $second, $third),
        ];

        if (strlen($digits) === 11) {
            // 携帯電話（060・070・080・090）・IP 電話（050）・M2M（020）は3・4・4、フリーダイヤル（0800）は4・3・4
            return match (true) {
                str_starts_with($digits, '0800') => $cut(4, 3, 4),
                (bool) preg_match('/^0[25-9]0/', $digits) => $cut(3, 4, 4),
                default => null,
            };
        }
        // フリーダイヤル（0120）・ナビダイヤル（0570）・情報料代理徴収（0990）は4・3・3
        if (preg_match('/^0(120|570|990)/', $digits)) {
            return $cut(4, 3, 3);
        }
        // 固定電話：0 から市内局番までの6桁で市外局番の桁（0 を含めて2〜5）が決まる。加入者番号は4桁
        $table = self::areaCodes();
        for ($length = 6; $length >= 2; $length--) {
            $area = $table[substr($digits, 0, $length)] ?? null;
            if (is_int($area) && $area >= 2 && $area <= 5) {
                return $cut($area, 6 - $area, 4);
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private static function areaCodes(): array
    {
        if (self::$areaCodes === null) {
            $decoded = json_decode((string) file_get_contents(self::AREA_CODES), true, flags: JSON_THROW_ON_ERROR);
            self::$areaCodes = $decoded['prefixes'];
        }

        return self::$areaCodes;
    }
}
