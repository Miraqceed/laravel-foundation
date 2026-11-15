<?php

namespace Miraqceed\Foundation\Login\Fortify;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Miraqceed\Foundation\Login\LoginLock;
use Miraqceed\Foundation\Login\LoginLockedOut;

/**
 * Fortify への差し込み。手順は3つ：
 *
 *   // config/fortify.php：経路の throttle を外す
 *   'limiters' => ['login' => null],
 *
 *   // 同じ回数の制限が Fortify のログインの流れに入らないようにする
 *   // （limiters.login が null だと、Fortify は EnsureLoginIsNotThrottled を流れに入れる）
 *   Fortify::authenticateThrough(fn () => AuthenticateWithLoginLock::loginPipeline());
 *
 *   Fortify::authenticateUsing(new AuthenticateWithLoginLock(new LoginLock(new NormalizedEmailKey)));
 *   // LoginLock と EmailKey をコンテナに結んでいれば app(AuthenticateWithLoginLock::class) でもよい
 *
 * 二段階認証を有効にすると、Fortify はこの関数を RedirectIfTwoFactorAuthenticatable と
 * AttemptToAuthenticate で続けて2回呼ぶ（二段階認証のない利用者）。失敗は1回目で終わるので1回しか数えず、
 * 成功は呼び出しのたびに数えを戻すので何も残らない。
 */
final class AuthenticateWithLoginLock
{
    public function __construct(private readonly LoginLock $lock) {}

    /**
     * Fortify の既定のログインの流れ（AuthenticatedSessionController::loginPipeline と同じ順・同じ条件）から、
     * Fortify 自身の回数の制限 EnsureLoginIsNotThrottled だけを除いたもの。
     *
     * @return list<class-string>
     */
    public static function loginPipeline(): array
    {
        return array_values(array_filter([
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            Features::enabled(Features::twoFactorAuthentication()) ? self::twoFactorRedirect() : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]));
    }

    /**
     * 古い Fortify には差し替え用の契約がなく、動作クラスを直に使う。
     */
    private static function twoFactorRedirect(): string
    {
        return interface_exists(RedirectsIfTwoFactorAuthenticatable::class)
            ? RedirectsIfTwoFactorAuthenticatable::class
            : RedirectIfTwoFactorAuthenticatable::class;
    }

    /**
     * @throws ValidationException ロック中（429。Fortify の auth.throttle の文言）
     */
    public function __invoke(Request $request): ?Authenticatable
    {
        $username = Fortify::username();
        $guard = Auth::guard(config('fortify.guard'));
        $provider = $guard->getProvider();
        $user = null;

        try {
            $this->lock->attempt($request, (string) $request->input($username), function () use ($request, $username, $provider, &$user): bool {
                // 存在しない利用者でも一定の時間をかける（Fortify と同じ）
                return (new Timebox)->call(function () use ($request, $username, $provider, &$user): bool {
                    $candidate = $provider->retrieveByCredentials($request->only($username, 'password'));

                    if (! $candidate || ! $provider->validateCredentials($candidate, ['password' => (string) $request->input('password')])) {
                        return false;
                    }

                    if (config('hashing.rehash_on_login', true) && method_exists($provider, 'rehashPasswordIfRequired')) {
                        $provider->rehashPasswordIfRequired($candidate, ['password' => (string) $request->input('password')]);
                    }

                    $user = $candidate;

                    return true;
                }, 200000);
            });
        } catch (LoginLockedOut $e) {
            throw ValidationException::withMessages([
                $username => [trans('auth.throttle', [
                    'seconds' => $e->retryAfter,
                    'minutes' => ceil($e->retryAfter / 60),
                ])],
            ])->status(429);
        }

        return $user;
    }
}
