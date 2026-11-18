<?php

namespace Miraqceed\Foundation\Failures;

/**
 * 1つの IP の、文に出うる書き方を集めて伏せる（FailureReporter::redactIp() の中身）。
 *
 * IPv6 は書き方が揺れる（大文字・小文字、:: の省略、0 の省略、全部の形）。str_replace 1回では伏せきれない。
 * IPv4 を埋め込んだ IPv6（::ffff:0:0/96・64:ff9b::/96）は、中の IPv4 が別に出ることがある。
 *
 * @internal
 */
final class IpSpellings
{
    /** IPv4-mapped (::ffff:0:0/96) */
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /** NAT64 の既定の接頭辞 (64:ff9b::/96, RFC 6052) */
    private const NAT64_PREFIX = "\0\x64\xff\x9b\0\0\0\0\0\0\0\0";

    public static function redact(string $message, ?string $ip, string $marker): string
    {
        if ($ip === null || $ip === '' || $message === '') {
            return $message;
        }

        $packed = @inet_pton($ip);

        // IP として読めない値は、書いたとおりだけを伏せる
        if ($packed === false) {
            return str_ireplace($ip, $marker, $message);
        }

        $ipv4 = [];
        $ipv6 = [];

        if (strlen($packed) === 4) {
            $ipv4[] = (string) inet_ntop($packed);
            $ipv6[] = '::ffff:'.inet_ntop($packed);
            $ipv6[] = '::ffff:'.self::hexTail($packed);
        } else {
            $ipv6[] = (string) inet_ntop($packed);
            $groups = str_split(bin2hex($packed), 4);
            $ipv6[] = implode(':', $groups);
            $ipv6[] = implode(':', array_map(fn (string $group) => ltrim($group, '0') ?: '0', $groups));

            $prefix = substr($packed, 0, 12);
            if ($prefix === self::IPV4_MAPPED_PREFIX || $prefix === self::NAT64_PREFIX) {
                $ipv4[] = (string) inet_ntop(substr($packed, 12));
            }
        }

        // 渡した書き方（前後の空白を除く）も入れる。読める IP なので、どちらかの組に入る
        $given = trim($ip);
        if (str_contains($given, ':')) {
            $ipv6[] = $given;
        } else {
            $ipv4[] = $given;
        }

        $patterns = [];
        foreach (self::longestFirst($ipv6) as $form) {
            // 前後に16進の字・: が続くときは、もっと長いアドレスの一部なので当てない
            $patterns[] = '/(?<![0-9a-f:])'.preg_quote($form, '/').'(?![0-9a-f:])(?!\.[0-9])/i';
        }
        foreach (self::longestFirst($ipv4) as $form) {
            // 前後に数字が続く（1203.0.113.5・203.0.113.50・203.0.113.5.1）ときは当てない。文の終わりの . は当てる
            $patterns[] = '/(?<![0-9])(?<![0-9]\.)'.preg_quote($form, '/').'(?![0-9])(?!\.[0-9])/';
        }

        // u を付けない（壊れた UTF-8 の文でも、バイトのまま扱って失敗しない）
        $redacted = preg_replace($patterns, $marker, $message);

        // 正規表現が失敗したら（上限に掛かるなど）、境目を見ずに伏せる。伏せずに返さない
        return is_string($redacted) ? $redacted : str_ireplace([...$ipv6, ...$ipv4], $marker, $message);
    }

    /** IPv4 を ::ffff: の後ろの16進の2組の形にする（::ffff:cb00:7105） */
    private static function hexTail(string $packed): string
    {
        [$high, $low] = str_split(bin2hex($packed), 4);

        return (ltrim($high, '0') ?: '0').':'.(ltrim($low, '0') ?: '0');
    }

    /**
     * 長い書き方を先に当てる（短い書き方が長い書き方の一部を先に消さないため）
     *
     * @param  list<string>  $forms
     * @return list<string>
     */
    private static function longestFirst(array $forms): array
    {
        $forms = array_values(array_unique(array_filter($forms, fn (string $form) => $form !== '')));
        usort($forms, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $forms;
    }
}
