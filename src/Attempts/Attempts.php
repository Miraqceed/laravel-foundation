<?php

namespace Miraqceed\Foundation\Attempts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 照合の試みを数えて止める。
 *
 * - 照合の前に1回分を足し、足した後の数で判断する。見てから照合の後に足す形だと、
 *   照合を待つ間に並んだ要求が揃って判定を通り抜ける
 * - 照合が例外を投げたときは足した分を返さない（例外を誘発して数えさせない抜け道を作らない）
 * - 入力の確かめ・権限の確かめを通った後に、照合そのものを包んで呼ぶ（経路の手前で数えると、
 *   同じ接続元の人の入力の誤りで正当な利用者が止まる）
 *
 * 数えは RateLimiter・Cache で、Redis に頼らない。increment と add が原子的なキャッシュ（database・redis など）で使う。
 */
final class Attempts
{
    /** 続けた失敗の数えの窓（10年。時間で消えない数えとして使う） */
    private const NEVER_EXPIRES = 315_360_000;

    /**
     * @param  list<Limit>  $limits
     * @param  \Closure():bool  $verify  照合。通れば true
     * @return bool 照合の結果
     *
     * @throws TooManyAttempts 上限なら。このとき $verify は呼ばない
     */
    public function attempt(array $limits, \Closure $verify): bool
    {
        if ($this->bypassed()) {
            return $verify();
        }

        $this->reserve($limits);

        $ok = $verify();

        if ($ok) {
            foreach ($limits as $limit) {
                match ($limit->counting) {
                    Counting::FailuresOwn => RateLimiter::clear($limit->key),
                    Counting::FailuresShared => $this->release($limit),
                    Counting::Every => null,
                };
            }
        }

        return $ok;
    }

    /**
     * 続けた失敗の数えで照合する。
     *
     * - 失敗を時間で消えない数えで数える（窓のある数えでは、窓ごとに上限の手前まで試し続けられる）。成功で空にする
     * - ロックの印は1つの鍵で、ロックそのものを兼ねる。有効の時間はいつもロックの秒数
     * - 足した後の数が上限以上の試みは、照合の前に印を Cache::add（原子的）で立てる。立てられた要求だけが照合する
     *   （上限に届く最初の試みと、ロックが明けた後の最初の試み）。立てられなければ照合せずに断り、足した1回分を返す
     * - 照合の例外は失敗と同じに扱う。足した1回分を返さず、印も消さない（例外を誘発して試みを重ねさせない）。
     *   印には有効の時間があるので、例外でもプロセスが落ちても、ロックの秒数で消える
     *
     * @param  \Closure():bool  $verify  照合。通れば true
     * @return bool 照合の結果
     *
     * @throws TooManyAttempts ロック中なら。このとき $verify は呼ばない
     */
    public function attemptConsecutive(ConsecutiveLimit $limit, \Closure $verify): bool
    {
        if ($this->bypassed()) {
            return $verify();
        }

        $countKey = $limit->key.':count';
        $lockKey = $limit->key.':lock';

        $locked = Cache::get($lockKey);
        if ($locked !== null) {
            throw new TooManyAttempts(self::secondsUntil((int) $locked));
        }

        $count = AttemptCounter::make()->countOne($countKey, self::NEVER_EXPIRES);

        if ($count >= $limit->max) {
            $unlockAt = now()->getTimestamp() + $limit->lockSeconds;
            if (! Cache::add($lockKey, $unlockAt, $limit->lockSeconds)) {
                // 同時の別の要求が先に立てた。照合していないので数えない
                // 並んだ別の要求が成功して先に空にしていたら、負にせず空にする（giveBack）
                AttemptCounter::make()->giveBack($countKey);
                throw new TooManyAttempts(self::secondsUntil((int) (Cache::get($lockKey) ?? $unlockAt)));
            }
        }

        $ok = $verify();

        if ($ok) {
            RateLimiter::clear($countKey);
            Cache::forget($lockKey);
        }

        return $ok;
    }

    /**
     * 続けた失敗のロックが明けるまでの秒。ロックしていなければ 0。読むだけで、数えも印も変えない
     * （照合に失敗した後に、その失敗でロックになったかを知るのに使う）
     */
    public function lockedFor(ConsecutiveLimit $limit): int
    {
        $unlockAt = Cache::get($limit->key.':lock');

        return $unlockAt === null ? 0 : max(0, (int) $unlockAt - now()->getTimestamp());
    }

    /**
     * 上限に達していれば投げる。達していなければ、すべての枠に1回分を足す。
     *
     * @param  list<Limit>  $limits
     *
     * @throws TooManyAttempts
     */
    private function reserve(array $limits): void
    {
        // 上限を超えた要求は数えを膨らませずに返す
        $retryAfter = 0;
        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->max)) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($limit->key));
            }
        }
        if ($retryAfter > 0) {
            throw new TooManyAttempts(max(1, $retryAfter));
        }

        $counter = AttemptCounter::make();
        $hits = [];
        foreach ($limits as $i => $limit) {
            $hits[$i] = $counter->countOne($limit->key, $limit->decaySeconds);
        }

        $over = false;
        foreach ($limits as $i => $limit) {
            if ($hits[$i] > $limit->max) {
                $over = true;
                $retryAfter = max($retryAfter, RateLimiter::availableIn($limit->key));
            }
        }
        if ($over) {
            // 照合していないので失敗として残さない。数え直すと、その間に並列の成功で枠が空いたとき素通りする
            foreach ($limits as $limit) {
                $this->release($limit);
            }
            throw new TooManyAttempts(max(1, $retryAfter));
        }
    }

    /**
     * 足した1回分を返す。
     *
     * 窓が終わっていれば何もしない（足した分は窓ごと消える。decrement は失効した窓を作り直し、数えと窓がずれる）。
     * 並列で窓をまたいで負になったときは空にする（負のままだと次の窓で1回多く通す）。
     */
    private function release(Limit $limit): void
    {
        if (RateLimiter::availableIn($limit->key) === 0) {
            return;
        }
        AttemptCounter::make()->giveBack($limit->key);
    }

    /**
     * 診断の間だけ数えずに照合するか（foundation.attempts.bypass）。
     * production では効かせず、数え続けて report() で知らせる（1時間に1回）。本番で外れたままにしないため
     */
    private function bypassed(): bool
    {
        if (! config('foundation.attempts.bypass')) {
            return false;
        }

        if (app()->environment('production')) {
            if (Cache::add('foundation-attempts:bypass-reported', true, 3600)) {
                report(new \RuntimeException('foundation.attempts.bypass は production では効きません。試みを数え続けます。設定を外してください'));
            }

            return false;
        }

        return true;
    }

    /** その時刻まで再び試せない秒数（1以上） */
    private static function secondsUntil(int $timestamp): int
    {
        return max(1, $timestamp - now()->getTimestamp());
    }
}
