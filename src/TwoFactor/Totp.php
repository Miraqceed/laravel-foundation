<?php

namespace Miraqceed\Foundation\TwoFactor;

/**
 * 認証アプリの確認コード（RFC 6238 の TOTP）の芯。HMAC-SHA1・6桁・30秒の刻み。秘密の鍵は Base32（RFC 4648、詰め物なし）で持つ。
 * 純粋な計算で、設定・時計・DB を読まない（時刻は呼ぶ側が渡す）。
 */
final class Totp
{
    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 打たれたコードから除く空白（ASCII の空白・タブ・全角の空白）。改行は除かない */
    private const SPACES = [' ' => '', "\t" => '', "\u{3000}" => ''];

    /** 全角の数字を半角に直す */
    private const FULL_WIDTH_DIGITS = [
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
        '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
    ];

    /** 秘密の鍵を作る。既定は 160 ビット（RFC 4226 の勧め）で、Base32 で 32 字 */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** その時刻の確認コード（6桁。頭の 0 を残す） */
    public static function codeAt(string $secret, int $unixTime): string
    {
        return self::codeAtStep(self::base32Decode($secret), intdiv($unixTime, self::PERIOD_SECONDS));
    }

    /**
     * 打たれたコードを確かめ、合った刻み（Unix 時刻 ÷ 30）を返す。合わない・形が違う・使い回し（$lastStep 以下の刻み）なら null。
     * 今の刻みの前後 $window 刻みまで受ける（時計のずれ）
     */
    public static function verify(string $secret, string $code, int $unixTime, ?int $lastStep, int $window = 1): ?int
    {
        $code = self::normalizeCode($code);
        if ($code === null) {
            return null;
        }

        $key = self::base32Decode($secret);
        $current = intdiv($unixTime, self::PERIOD_SECONDS);
        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if ($step < 0 || ($lastStep !== null && $step <= $lastStep)) {
                continue;
            }
            if (hash_equals(self::codeAtStep($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * 打たれたコードを6桁の半角の数字にする。空白（全角を含む）を除き、全角の数字を半角に直す。6桁の数字でなければ null
     */
    public static function normalizeCode(string $input): ?string
    {
        $code = strtr(strtr($input, self::SPACES), self::FULL_WIDTH_DIGITS);

        return preg_match('/^[0-9]{'.self::DIGITS.'}\z/', $code) === 1 ? $code : null;
    }

    /**
     * 認証アプリに登録する URI（otpauth://totp/…）。ラベルは「issuer:account」で、それぞれを URL エンコードし、間の : だけを残す
     */
    public static function uri(string $issuer, string $account, string $secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($issuer),
            rawurlencode($account),
            http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD_SECONDS,
            ], '', '&', PHP_QUERY_RFC3986),
        );
    }

    /** RFC 4648 の Base32。= の詰め物は付けない */
    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $text = '';
        foreach (str_split($bits, 5) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            $text .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $text;
    }

    /**
     * base32Encode の逆。小文字と末尾の = も読む
     *
     * @throws \InvalidArgumentException 字母にない字があるとき
     */
    public static function base32Decode(string $text): string
    {
        $text = rtrim(strtoupper($text), '=');

        $bits = '';
        foreach (str_split($text) as $char) {
            if ($char === '') {
                continue;
            }
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new \InvalidArgumentException('Base32 の字母にない字があります');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        // 8 ビットにならない末尾は、符号化のときの詰め物なので捨てる
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }

    private static function codeAtStep(string $key, int $step): string
    {
        // 刻みを 8 バイトのビッグエンディアンにして HMAC-SHA1 を取り、動的な切り出し（RFC 4226 の 5.3）で 6 桁にする
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }
}
