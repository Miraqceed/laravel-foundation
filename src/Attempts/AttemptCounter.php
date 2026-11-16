<?php

namespace Miraqceed\Foundation\Attempts;

use Illuminate\Cache\RateLimiter;

/**
 * 試みの数えの足し算と返し。RateLimiter と同じ鍵（数えと :timer）を使うので、読む側（tooManyAttempts・attempts・availableIn・clear）は
 * RateLimiter のままでよい。
 *
 * RateLimiter::increment を使わない。increment は、鍵を add できなかったのに足した後の数が足した数と同じとき、put で数えを書き直す。
 * 窓の始まりに要求が並ぶと、別の要求が足した後の数を、その put が上書きして1回分が消える（上限より多く照合する）。
 * ここでは add（無ければ 0 で作る）と increment だけで数え、put で書き直さない。increment が偽（鍵が消えていた）なら add からやり直す。
 *
 * @internal
 */
final class AttemptCounter extends RateLimiter
{
    /** 鍵が消えていたときに、add からやり直す回数 */
    private const RETRIES = 3;

    /** RateLimiter と同じキャッシュ（cache.limiter、なければ既定）で作る */
    public static function make(): self
    {
        return new self(app('cache')->driver(config('cache.limiter')));
    }

    /**
     * 1回分を足し、足した後の数を返す。窓は、初めて足したときから $decaySeconds 秒
     *
     * @throws \RuntimeException 何度やり直しても足せなかったとき（数えないまま通さない）
     */
    public function countOne(string $key, int $decaySeconds): int
    {
        $key = $this->cleanRateLimiterKey($key);

        $this->cache->add($key.':timer', $this->availableAt($decaySeconds), $decaySeconds);

        for ($i = 0; $i < self::RETRIES; $i++) {
            $this->withoutSerializationOrCompression(fn () => $this->cache->add($key, 0, $decaySeconds));
            $hits = $this->withoutSerializationOrCompression(fn () => $this->cache->increment($key));
            if ($hits !== false) {
                return (int) $hits;
            }
        }

        throw new \RuntimeException('試みの数えを足せませんでした。キャッシュを確かめてください');
    }

    /**
     * 足した1回分を返す。並んだ要求が先に数えを空にしていて負になったら、空にする（負のままだと次に1回多く通す）
     */
    public function giveBack(string $key): void
    {
        $key = $this->cleanRateLimiterKey($key);

        $left = $this->withoutSerializationOrCompression(fn () => $this->cache->decrement($key));
        if ($left !== false && (int) $left < 0) {
            $this->clear($key);
        }
    }
}
