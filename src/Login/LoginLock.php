<?php

namespace Miraqceed\Foundation\Login;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ログインの失敗のロック。
 *
 * - 照合の前に1回分を足し、足した後の数で判断する。見てから照合の後に足す形だと、
 *   照合を待つ間に並んだ要求が揃って判定を通り抜ける
 * - 失敗だけを数える。成功したらメールアドレスと接続元の組の枠を空にし、接続元の枠は今回の1回分だけ戻す
 *   （空にすると、自分のアカウントを持つ攻撃者がスプレーの合間にログインするだけで接続元の枠を戻せる）
 * - 枠は2つ：メールアドレスと接続元の組（本人が同じ接続元を共有する他人に巻き込まれない）と、
 *   接続元（メールを変えながら1回ずつ試すスプレーを止める）
 * - 照合が例外を投げたときは足した分を返さない（例外を誘発して数えさせない抜け道を作らない）
 *
 * 数えは RateLimiter（キャッシュ）で、Redis に頼らない。
 */
final class LoginLock
{
    public function __construct(
        private readonly EmailKey $emailKey,
        private readonly string $scope = 'user',
    ) {}

    /**
     * @param  \Closure():bool  $verify  照合。通れば true
     * @return bool 照合の結果
     *
     * @throws LoginLockedOut 上限なら。このとき $verify は呼ばない
     */
    public function attempt(Request $request, string $email, \Closure $verify): bool
    {
        $emailKey = $this->emailKey->key($email);

        $ipBucket = IpBucket::of($request);
        $client = new Bucket("foundation-login:{$this->scope}:client:{$ipBucket}", (int) $this->config('client_max'), (int) $this->config('decay_seconds'));

        if ($this->config('email_only')) {
            // メールアドレスと接続元の組の枠を、メールアドレスだけの枠に置き換える。接続元の枠は残す
            $account = new Bucket("foundation-login:{$this->scope}:email:{$emailKey}", (int) $this->config('email_only_max'), (int) $this->config('email_only_decay_seconds'));
        } else {
            $account = new Bucket("foundation-login:{$this->scope}:account:".sha1($emailKey.'|'.$ipBucket), (int) $this->config('account_max'), (int) $this->config('decay_seconds'));
        }
        $buckets = [$account, $client];

        if (! $this->reserve($buckets)) {
            // reserve が投げる。ここには来ない
            throw new \LogicException;
        }

        $ok = $verify();

        if ($ok) {
            RateLimiter::clear($account->key);
            $this->release($client);
        }

        return $ok;
    }

    /**
     * 現在のパスワードの確かめ（パスワードの変更・確かめの画面）の失敗を、ログインとは別に数える。
     * 確かめの失敗でログインはロックしない。
     *
     * @param  \Closure():bool  $verify
     *
     * @throws LoginLockedOut
     */
    public function attemptPasswordConfirmation(Request $request, Authenticatable $user, \Closure $verify): bool
    {
        $bucket = new Bucket(
            "foundation-login:{$this->scope}:password:".sha1($user->getAuthIdentifier().'|'.IpBucket::of($request)),
            (int) $this->config('account_max'),
            (int) $this->config('decay_seconds'),
        );

        $this->reserve([$bucket]);

        $ok = $verify();

        if ($ok) {
            RateLimiter::clear($bucket->key);
        }

        return $ok;
    }

    /**
     * 上限に達していれば投げる。達していなければ、すべての枠に1回分を足す。
     *
     * @param  list<Bucket>  $buckets
     */
    private function reserve(array $buckets): bool
    {
        // 上限を超えた要求は数えを膨らませずに返す
        $retryAfter = 0;
        foreach ($buckets as $bucket) {
            if (RateLimiter::tooManyAttempts($bucket->key, $bucket->max)) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($bucket->key));
            }
        }
        if ($retryAfter > 0) {
            throw new LoginLockedOut(max(1, $retryAfter));
        }

        $hits = [];
        foreach ($buckets as $i => $bucket) {
            $hits[$i] = RateLimiter::increment($bucket->key, $bucket->decay);
        }

        $retryAfter = 0;
        $over = false;
        foreach ($buckets as $i => $bucket) {
            if ($hits[$i] > $bucket->max) {
                $over = true;
                $retryAfter = max($retryAfter, RateLimiter::availableIn($bucket->key));
            }
        }
        if ($over) {
            // 照合していないので失敗として残さない。数え直すと、その間に並列の成功で枠が空いたとき素通りする
            foreach ($buckets as $bucket) {
                $this->release($bucket);
            }
            throw new LoginLockedOut(max(1, $retryAfter));
        }

        return true;
    }

    /**
     * 足した1回分を返す。
     *
     * 窓が終わっていれば何もしない（足した分は窓ごと消える。decrement は失効した窓を作り直し、数えと窓がずれる）。
     * 並列で窓をまたいで -1 から始まったときは 0 に戻す（-1 のままだと次の窓で1回多く通す）。
     */
    private function release(Bucket $bucket): void
    {
        if (RateLimiter::availableIn($bucket->key) === 0) {
            return;
        }
        if (RateLimiter::decrement($bucket->key, $bucket->decay) < 0) {
            RateLimiter::clear($bucket->key);
        }
    }

    private function config(string $key): mixed
    {
        return config("foundation.login.{$key}");
    }
}
