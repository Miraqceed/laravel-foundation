<?php

namespace Miraqceed\Foundation\Mail;

/**
 * 利用者が打ったメールアドレスを、保存する値と比べの形にそろえる。設定・DB・DNS を読まない（同じ入力にいつも同じ答え）。
 *
 * - normalize()：保存する値（送る宛先・表示）。届き先を変えないものだけをそろえる
 * - canonical()：比べの形（一意の列・探すとき）。同じ人の別名（大文字・Gmail の点と +）を1つにする。送る宛先には使わない
 *
 * 形の確かめ（@ の数・ラベルの形）はしない。それは email の規則の仕事。届かないドメインの判定は ReservedEmailDomains
 */
final class EmailInput
{
    /** 句点・半角の句点・全角のピリオド（ドメインの中では点とみなす。ReservedEmailDomains と同じ） */
    private const DOTS = ['。', '｡', '．'];

    /** 点と + 以降を区別しないと Google が明言しているドメイン。ほかに広げない（Google Workspace の独自ドメインは点を区別する） */
    private const GMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    /**
     * 保存する値：全角の英数字・記号を半角、前後の空白を落とす、ドメインの句点を点にして末尾の点を落とす、ドメインの ASCII の英字を小文字。
     * ローカル部の大文字・小文字は変えない（規格では受け手が区別してよい）。日本語のドメインは打ったまま（xn-- にしない）。
     * 壊れた UTF-8 は変えずに返す（直さずに、検証で断らせる）
     */
    public static function normalize(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // a：全角の英数字・記号（＠・＿・－・＋・．を含む）を半角、s：全角の空白を半角
        $value = self::trim(mb_convert_kana($value, 'as', 'UTF-8'));

        $at = strrpos($value, '@');
        if ($at === false) {
            return $value;
        }

        $domain = rtrim(str_replace(self::DOTS, '.', substr($value, $at + 1)), '.');

        // PHP 8.2 から strtolower() は ASCII の英字だけを変える（非 ASCII の文字を壊さない）
        return substr($value, 0, $at).'@'.strtolower($domain);
    }

    /**
     * 比べの形：normalize() の後、全体を小文字（非 ASCII の大文字も）。gmail.com・googlemail.com だけ、ローカル部の点と + 以降を落とし、
     * ドメインを gmail.com にそろえる。壊れた UTF-8 は変えずに返す
     */
    public static function canonical(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $value = mb_strtolower(self::normalize($value), 'UTF-8');

        $at = strrpos($value, '@');
        if ($at === false) {
            return $value;
        }

        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);
        if (! in_array($domain, self::GMAIL_DOMAINS, true)) {
            return $value;
        }

        $plus = strpos($local, '+');
        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        return str_replace('.', '', $local).'@gmail.com';
    }

    /** 前後の空白（全角・ノーブレークスペース・ゼロ幅・BOM を含む） */
    private static function trim(string $value): string
    {
        return (string) preg_replace('/\A[\s\p{Z}\x{200B}-\x{200D}\x{FEFF}]+|[\s\p{Z}\x{200B}-\x{200D}\x{FEFF}]+\z/u', '', $value);
    }
}
