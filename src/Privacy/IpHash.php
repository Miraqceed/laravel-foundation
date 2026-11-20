<?php

namespace Miraqceed\Foundation\Privacy;

use Illuminate\Http\Request;
use Miraqceed\Foundation\Login\IpBucket;

/**
 * DB に残す IP の値（同じ回線の登録の数え・通報の数えなど）を、秘密の鍵つきの HMAC-SHA256（16進64字）にする。
 *
 * - 鍵は foundation.privacy.ip_hash_key（APP_KEY と別）。残す値は日をまたいで比べるので、漏れたときに入れ替える APP_KEY に結び付けない
 * - 鍵を入れ替えると、前の値と比べられなくなる（生の IP を持たないので作り直せない。前の鍵を試す口は持たない）
 * - ハッシュにしても、同じ回線を日をまたいで結び付けられる。個人に関する情報として扱い、保存の期間を決める
 */
final class IpHash
{
    public const MIN_KEY_LENGTH = 32;

    /**
     * IP の書き方の違い（IPv6 の大文字・:: の省略・0 の省略）は、正規の形にそろえてから作る
     *
     * @throws \InvalidArgumentException IP として読めないとき
     * @throws \RuntimeException 鍵が空・短いとき
     */
    public static function of(?string $ip): string
    {
        return self::hmac(self::normalize($ip));
    }

    /**
     * IpBucket と同じ単位で作る（IPv6 は /64、IPv4 と IPv4 を埋め込んだ IPv6 は IPv4 のアドレス）。IPv4 は of() と同じ値
     *
     * @throws \InvalidArgumentException IP として読めないとき
     * @throws \RuntimeException 鍵が空・短いとき
     */
    public static function ofBucket(Request|string|null $requestOrIp): string
    {
        $ip = $requestOrIp instanceof Request ? $requestOrIp->ip() : $requestOrIp;
        $bucket = IpBucket::forIp(self::normalize($ip));

        return str_ends_with($bucket, '/64') ? self::hmac($bucket) : self::of($bucket);
    }

    private static function normalize(?string $ip): string
    {
        $packed = $ip === null ? false : @inet_pton(trim($ip));
        if ($packed === false) {
            // 値は文に入れない（IP そのものか、利用者の入力）
            throw new \InvalidArgumentException('IP として読めない値です');
        }

        return (string) inet_ntop($packed);
    }

    private static function hmac(string $value): string
    {
        $key = config('foundation.privacy.ip_hash_key');
        if (! is_string($key) || strlen($key) < self::MIN_KEY_LENGTH) {
            throw new \RuntimeException('IP のハッシュの鍵がないか短すぎます。foundation.privacy.ip_hash_key（FOUNDATION_IP_HASH_KEY）に '.self::MIN_KEY_LENGTH.' 字以上の乱数を書いてください');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
