<?php

namespace Miraqceed\Foundation\Login;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\Counting;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;

/**
 * ログインの失敗のロック。数えは試みの数え（Attempts）に任せ、ここでは枠を組み立てる。
 *
 * - 照合の前に1回分を足し、足した後の数で判断する（Attempts）
 * - 失敗だけを数える。成功したらメールアドレスと接続元の組の枠を空にし、接続元の枠は今回の1回分だけ戻す
 *   （空にすると、自分のアカウントを持つ攻撃者がスプレーの合間にログインするだけで接続元の枠を戻せる）
 * - 枠は2つ：メールアドレスと接続元の組（本人が同じ接続元を共有する他人に巻き込まれない）と、
 *   接続元（メールを変えながら1回ずつ試すスプレーを止める）
 * - 照合が例外を投げたときは足した分を返さない（例外を誘発して数えさせない抜け道を作らない）
 *
 * 鍵は APP_KEY の HMAC（AttemptKey）。数えは RateLimiter（キャッシュ）で、Redis に頼らない。
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

        $client = new Limit(
            AttemptKey::for("login:{$this->scope}:client", $ipBucket),
            (int) $this->config('client_max'),
            (int) $this->config('decay_seconds'),
            Counting::FailuresShared,
        );

        if ($this->config('email_only')) {
            // メールアドレスと接続元の組の枠を、メールアドレスだけの枠に置き換える。接続元の枠は残す
            $account = new Limit(
                AttemptKey::for("login:{$this->scope}:email", $emailKey),
                (int) $this->config('email_only_max'),
                (int) $this->config('email_only_decay_seconds'),
            );
        } else {
            $account = new Limit(
                AttemptKey::for("login:{$this->scope}:account", $emailKey, $ipBucket),
                (int) $this->config('account_max'),
                (int) $this->config('decay_seconds'),
            );
        }

        return $this->guard([$account, $client], $verify);
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
        $limit = new Limit(
            AttemptKey::for("login:{$this->scope}:password", (string) $user->getAuthIdentifier(), IpBucket::of($request)),
            (int) $this->config('account_max'),
            (int) $this->config('decay_seconds'),
        );

        return $this->guard([$limit], $verify);
    }

    /**
     * @param  list<Limit>  $limits
     * @param  \Closure():bool  $verify
     *
     * @throws LoginLockedOut
     */
    private function guard(array $limits, \Closure $verify): bool
    {
        // 照合の前の断り（上限）だけをログインのロックの例外にする。照合の中の例外（別の数えの TooManyAttempts も）はそのまま出す
        $verifying = false;
        try {
            return app(Attempts::class)->attempt($limits, function () use ($verify, &$verifying): bool {
                $verifying = true;

                return $verify();
            });
        } catch (TooManyAttempts $e) {
            if ($verifying || $e instanceof LoginLockedOut) {
                throw $e;
            }
            throw new LoginLockedOut($e->retryAfter);
        }
    }

    private function config(string $key): mixed
    {
        return config("foundation.login.{$key}");
    }
}
