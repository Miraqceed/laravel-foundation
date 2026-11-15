<?php

namespace Miraqceed\Foundation\Login\Fortify;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Miraqceed\Foundation\Login\LoginLock;
use Miraqceed\Foundation\Login\LoginLockedOut;

/**
 * Fortify::authenticateUsing() に渡す。
 *
 *   Fortify::authenticateUsing(new AuthenticateWithLoginLock(new LoginLock(new NormalizedEmailKey)));
 *
 * Fortify 自身の回数の制限は外す（二重に数えない）：config('fortify.limiters.login') を null にする。
 *
 * 二段階認証を有効にすると、Fortify はこの関数を RedirectIfTwoFactorAuthenticatable と
 * AttemptToAuthenticate で続けて2回呼ぶ（二段階認証のない利用者）。失敗は1回目で終わるので1回しか数えず、
 * 成功は呼び出しのたびに数えを戻すので何も残らない。
 */
final class AuthenticateWithLoginLock
{
    public function __construct(private readonly LoginLock $lock) {}

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
