<?php

namespace Miraqceed\Foundation\Privacy;

use Illuminate\Cache\RateLimiter;

/**
 * 回数の制限の鍵を、すべて APP_KEY の HMAC-SHA256 にする RateLimiter。
 *
 * Laravel の RateLimiter は鍵をハッシュにしないので、database のキャッシュでは鍵がそのまま cache の表に残る。
 * Laravel の throttle（ログインしていない人は sha1(ドメイン|IP)、名前の付いた制限は md5(名前.鍵)）・Fortify の
 * LoginRateLimiter（メールアドレス|IP をそのまま）・アプリの Limit::by($request->ip()) は、秘密がないので IP に戻せる。
 * RateLimiter のどの口も cleanRateLimiterKey() を通るので、ここで HMAC にすれば、キャッシュの表にはどれも HMAC だけが入る。
 *
 * - RateLimiter は自分の中で cleanRateLimiterKey() を2回通すことがある（remaining()・clear()）。HMAC にした鍵はそのまま返す
 * - APP_KEY が空なら RuntimeException（黙って生の鍵に戻さない）。APP_KEY を入れ替えると、鍵が変わって数えが消える
 * - foundation.privacy.keyed_rate_limiter を真にすると、パッケージが容器の RateLimiter をこれに差し替える
 */
class KeyedRateLimiter extends RateLimiter
{
    /** 差し替える前の RateLimiter のキャッシュと名前の付いた制限を引き継ぐ */
    public static function from(RateLimiter $limiter): static
    {
        $keyed = new static($limiter->cache);
        $keyed->limiters = $limiter->limiters;

        return $keyed;
    }

    /**
     * @param  string  $key
     * @return string
     *
     * @throws \RuntimeException app.key が空のとき
     */
    public function cleanRateLimiterKey($key)
    {
        $key = (string) $key;

        // 既に HMAC にした鍵（RateLimiter の中の2回目）。秘密を知らなければ、この形の鍵を他人の鍵に合わせて作れない
        if (LimiterKey::isKeyed($key)) {
            return $key;
        }

        return LimiterKey::for(parent::cleanRateLimiterKey($key));
    }
}
