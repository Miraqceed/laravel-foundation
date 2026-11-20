<?php

namespace Miraqceed\Foundation\Japan;

/**
 * 郵便番号を「100-0001」の形にする。「〒」・全角・ハイフンとして読む記号・空白を読む（記号の読み方は PhoneNumber と同じ）。
 * 7桁の数字でなければ null
 */
final class PostalCode
{
    public static function format(?string $input): ?string
    {
        $text = PhoneNumber::normalize($input);
        if ($text === null) {
            return null;
        }
        $text = (string) preg_replace('/[\s-]/u', '', $text);

        // /u の \d はほかの文字の数字にも当たるので、[0-9] で書く
        return preg_match('/\A〒?([0-9]{3})([0-9]{4})\z/u', $text, $m) ? "{$m[1]}-{$m[2]}" : null;
    }
}
