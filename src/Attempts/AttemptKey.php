<?php

namespace Miraqceed\Foundation\Attempts;

/**
 * 数えの鍵。APP_KEY の HMAC-SHA256 にする。
 *
 * 秘密を使わない鍵（sha1 など）は、キャッシュの表の鍵から IP・メールアドレスを総当たりで戻せる（IPv4 は 2^32 通りしかない）。
 * パッケージは秘密を持たず、config('app.key') を読むだけ。APP_KEY を入れ替えると、鍵が変わって数えが消える。
 */
final class AttemptKey
{
    /**
     * @param  string  $name  口の名前（login:user:account・totp など）。鍵にそのまま入るので、IP・メールアドレスを入れない
     * @param  string  ...$parts  数えを分ける値（メールアドレスの鍵・接続元など）。鍵には HMAC だけが入る
     *
     * @throws \RuntimeException app.key が空のとき
     */
    public static function for(string $name, string ...$parts): string
    {
        $secret = config('app.key');
        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('数えの鍵を作れません。APP_KEY（app.key）を設定してください');
        }

        // 部分の数と長さを入れてつなぐ（'ab'+'c' と 'a'+'bc' を別にする）
        $message = $name;
        foreach ($parts as $part) {
            $message .= "\n".strlen($part).':'.$part;
        }

        return "foundation-attempts:{$name}:".hash_hmac('sha256', $message, $secret);
    }
}
