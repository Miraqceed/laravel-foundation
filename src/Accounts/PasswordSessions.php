<?php

namespace Miraqceed\Foundation\Accounts;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * パスワードを変えた・再設定した後の後始末。盗まれたセッションと remember の Cookie を効かなくする。
 *
 *   // パスワードを変えた後（ログイン中の本人。同じ要求の中で）
 *   app(PasswordSessions::class)->afterChange($request, $user);
 *
 *   // 再設定の後（ログインしていない要求）
 *   app(PasswordSessions::class)->afterReset($user);
 *
 * - ほかの端末のセッションの行を消せるのは、セッションが database のときだけ。ほかの driver では 0 を返し、止めない
 *   （パスワードは書いた後なので、失敗にしない）。auth.session（AuthenticateSession）を入れれば、ほかの端末は
 *   パスワードのハッシュが合わなくなった次の要求でログアウトする。点検の password_sessions がどちらかを見る
 * - Auth::logoutOtherDevices() は使わない：渡した平文でパスワードをハッシュし直して保存する（新しいパスワードを書いた後だと、計算と書き込みが2回）
 * - 入れただけでは何もしない。呼んだときだけ働く
 */
final class PasswordSessions
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * パスワードを変えた後。remember のトークンを作り直し、この端末は入り直させ（セッション ID・セッションの
     * パスワードのハッシュを新しくする。remember の Cookie は持っていたときだけ作り直す）、ほかのセッションを消す。
     *
     * この端末に入り直させられないとき（要求にセッションがない・ガードがセッションのガードでない・ログイン中の利用者が
     * $user でない）は、afterReset() と同じにする（その利用者のすべてのセッションを消す。今の要求のログインには触らない）。
     *
     * 入り直しは Auth::login() なので、Login の出来事が出る。
     *
     * @return int 消したほかのセッションの行の数（database でなければ 0）
     */
    public function afterChange(Request $request, Authenticatable $user): int
    {
        $guard = $this->auth->guard($this->guardName());

        if (! $request->hasSession() || ! $guard instanceof SessionGuard || ! $this->isSignedInAs($guard, $user)) {
            return $this->afterReset($user);
        }

        // Cookie を読む前に聞く：login() は remember の Cookie を作るかを、渡した値だけで決める
        $hadRemember = $guard->viaRemember() || $request->cookies->has($guard->getRecallerName());

        $this->cycleRememberToken($user);

        // セッション ID を作り直し（古い ID の行は消える）、remember なら新しいトークンで Cookie を作る。ハッシュは計算しない
        $guard->login($user, $hadRemember);

        $this->storePasswordHash($request, $guard, $user);

        return $this->endSessions($user, except: $request->session()->getId());
    }

    /**
     * 再設定の後。remember のトークンを作り直し、その利用者のすべてのセッションを消す。今の要求のログインには触らない。
     *
     * @return int 消したセッションの行の数（database でなければ 0）
     */
    public function afterReset(Authenticatable $user): int
    {
        $this->cycleRememberToken($user);

        return $this->endSessions($user, except: null);
    }

    private function guardName(): string
    {
        $name = config('foundation.accounts.guard');

        return is_string($name) && $name !== '' ? $name : 'web';
    }

    private function isSignedInAs(SessionGuard $guard, Authenticatable $user): bool
    {
        $id = $guard->id();

        return $id !== null && (string) $id === (string) $user->getAuthIdentifier();
    }

    /**
     * 盗まれた remember の Cookie を効かなくする。remember の列を持たないモデルでは何もしない
     */
    private function cycleRememberToken(Authenticatable $user): void
    {
        if ($user->getRememberTokenName() === '') {
            return;
        }

        $this->provider()?->updateRememberToken($user, Str::random(60));
    }

    private function provider(): ?UserProvider
    {
        $guard = $this->auth->guard($this->guardName());

        return method_exists($guard, 'getProvider') ? $guard->getProvider() : null;
    }

    /**
     * セッションのパスワードのハッシュを、新しいパスワードのものにする。auth.session が、次の要求でこの端末をログアウトさせないため。
     * Laravel 12.0 の login() はここに書かない（auth.session が次の要求で書く。書く前に古い値が残っていると合わない）。
     * 値の形は版に合わせる：hashPasswordForCookie() があれば HMAC、なければハッシュそのもの（どちらも auth.session が比べる形）
     */
    private function storePasswordHash(Request $request, SessionGuard $guard, Authenticatable $user): void
    {
        $passwordHash = $user->getAuthPassword();

        if (! is_string($passwordHash) || $passwordHash === '') {
            return;
        }

        $request->session()->put(
            'password_hash_'.$this->guardName(),
            method_exists($guard, 'hashPasswordForCookie') ? $guard->hashPasswordForCookie($passwordHash) : $passwordHash,
        );
    }

    private function endSessions(Authenticatable $user, ?string $except): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $query = DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier());

        if ($except !== null) {
            $query->where('id', '!=', $except);
        }

        return $query->delete();
    }
}
