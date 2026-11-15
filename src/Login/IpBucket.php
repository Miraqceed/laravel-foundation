<?php

namespace Miraqceed\Foundation\Login;

use Illuminate\Http\Request;

/**
 * 回数の制限で「同じ利用者」とみなす接続元の単位。
 *
 * IPv6 の利用者には /64 以上が割り当てられ、その中のアドレスは自由に選べる。
 * アドレスのまま数えると、アドレスを変えるだけで制限を避けられるので、上位64ビットに丸めて数える。
 * IPv4 はアドレスのまま返す（鍵の文字列が変わらないので、入れ替えても既存の数えが消えない）。
 */
final class IpBucket
{
    /** IPv4-mapped (::ffff:0:0/96) */
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /** NAT64 の既定の接頭辞 (64:ff9b::/96, RFC 6052) */
    private const NAT64_PREFIX = "\0\x64\xff\x9b\0\0\0\0\0\0\0\0";

    public static function of(Request $request): string
    {
        return self::forIp($request->ip());
    }

    public static function forIp(?string $ip): string
    {
        // 接続元が分からない要求（コンソールなど）は1つの枠
        if ($ip === null || $ip === '') {
            return '';
        }

        $packed = @inet_pton($ip);

        // IP として読めない値は丸めずに返す。空に潰すと別々の値の要求が1つの枠を共有する
        if ($packed === false || strlen($packed) === 4) {
            return $ip;
        }

        // 下位32ビットに IPv4 を埋め込んだ形は IPv4 として数える。/64 に丸めると、
        // デュアルスタックのソケットや NAT64 経由の IPv4 の利用者が全員1枠に潰れる
        $prefix = substr($packed, 0, 12);
        if ($prefix === self::IPV4_MAPPED_PREFIX || $prefix === self::NAT64_PREFIX) {
            return inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
