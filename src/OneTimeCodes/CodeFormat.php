<?php

namespace Miraqceed\Foundation\OneTimeCodes;

/**
 * 頭に印のある秘密（頭の印 + 英数字40字）の作り方・形の確かめ・ハッシュ。
 *
 * 英数字だけにするのは、シェル・URL・フォームの本文で書き換えずに通り、ダブルクリックで1語として選べるため。
 * 40字で約238ビット。乱数で作った秘密なので、DB には速いハッシュ（SHA-256）だけを持てば、ハッシュから戻せない
 * （人が決めるパスワードには使わない）
 */
final class CodeFormat
{
    /** 頭の印の後の英数字の字数 */
    public const LENGTH = 40;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** 頭の印 + 英数字40字。暗号に使える乱数（random_int）で、62字から1字ずつ偏りなく選ぶ */
    public static function generate(string $prefix): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = $prefix;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** 文字列で、頭の印 + 英数字ちょうど40字か（後ろの改行も通さない）。文字列でなければ false */
    public static function matches(string $prefix, #[\SensitiveParameter] mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9]{'.self::LENGTH.'}\z/', $value) === 1;
    }

    /** DB に持つハッシュ（SHA-256 の16進、64字） */
    public static function hash(#[\SensitiveParameter] string $code): string
    {
        return hash('sha256', $code);
    }
}
