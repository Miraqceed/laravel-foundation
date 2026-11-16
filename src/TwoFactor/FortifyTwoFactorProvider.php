<?php

namespace Miraqceed\Foundation\TwoFactor;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\ConsecutiveLimit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;

/**
 * Fortify の確かめ（TwoFactorAuthenticationProvider）の差し替え。アプリが容器の結び付けを替えたときだけ効く。
 *
 * Fortify の元の作りは、使ったコードの印をキャッシュから get してから確かめ、put する。原子的でなく、同じコードの2つの要求が
 * 揃うと両方が通る。印の鍵はコードだけ（md5）で、利用者によらない。ここでは:
 * - 合った刻みの印を Cache::add（原子的）で立てられたときだけ通す。印の鍵は秘密の鍵と刻みの HMAC（鍵もコードも入れない）
 * - 印の有効の時間は、そのコードが受けられる間の全体より長い (2 × window + 2) × 30 秒
 * - 通ったら、窓の中の前の刻み（刻み − 2 × window から）にも印を置く（通った後に、前の刻みのコードを受けない）
 * - 間違いを、秘密の鍵の HMAC ごとに続けた失敗で数える（Fortify の契約は利用者を渡さないが、鍵は利用者ごとに違う）。
 *   ロック中は確かめず、429 の誤り（code の欄）で断る
 *
 * 限界：前の刻みに印を置く前に、前の刻みの別の正しいコードがほぼ同時に届くと、それも通りうる（キャッシュには「より大きいときだけ書く」
 * 原子的な操作がない）。完全な守りが要るアプリは、手書きの TwoFactor（条件つきの UPDATE）を使う
 */
final class FortifyTwoFactorProvider implements TwoFactorAuthenticationProvider
{
    public function __construct(private readonly Attempts $attempts) {}

    /**
     * Fortify が渡す長さ（Base32 の字数）の秘密の鍵
     *
     * @param  int  $secretLength
     * @return string
     */
    public function generateSecretKey(int $secretLength = 16)
    {
        return substr(Totp::base32Encode(random_bytes((int) ceil($secretLength * 5 / 8))), 0, $secretLength);
    }

    /**
     * 認証アプリに登録する URI（ラベルと issuer を URL エンコードする）
     *
     * @param  string  $companyName
     * @param  string  $companyEmail
     * @param  string  $secret
     * @return string
     */
    public function qrCodeUrl($companyName, $companyEmail, $secret)
    {
        return Totp::uri((string) $companyName, (string) $companyEmail, (string) $secret);
    }

    /**
     * @param  string  $secret
     * @param  string  $code
     * @return bool
     *
     * @throws ValidationException 間違いが続いてロック中のとき（429）
     */
    public function verify($secret, $code)
    {
        $secret = (string) $secret;
        $limit = ConsecutiveLimit::fromConfig(AttemptKey::for('two-factor-fortify', $secret));

        try {
            return $this->attempts->attemptConsecutive($limit, fn (): bool => $this->take($secret, (string) $code));
        } catch (TooManyAttempts $e) {
            throw ValidationException::withMessages([
                'code' => [trans('auth.throttle', [
                    'seconds' => $e->retryAfter,
                    'minutes' => (int) ceil($e->retryAfter / 60),
                ])],
            ])->status(429)->errorBag($this->errorBag());
        }
    }

    /** コードが合い、その刻みの印を初めて立てられたときだけ真 */
    private function take(string $secret, string $code): bool
    {
        $window = $this->window();
        $step = Totp::verify($secret, $code, now()->getTimestamp(), null, $window);
        if ($step === null) {
            return false;
        }

        $ttl = (2 * $window + 2) * Totp::PERIOD_SECONDS;
        if (! Cache::add(self::usedKey($secret, $step), true, $ttl)) {
            return false;
        }
        // 窓の中で、この刻みより前のコードは、どれも受けない。先の刻み（今 + window）で通ったときは、今 − window まで窓に残るので、
        // 刻み − 2 × window から置く
        for ($earlier = $step - 2 * $window; $earlier < $step; $earlier++) {
            Cache::put(self::usedKey($secret, $earlier), true, $ttl);
        }

        return true;
    }

    /** Fortify の登録の確かめ（two-factor.confirm）は、Fortify と同じ袋に誤りを入れる */
    private function errorBag(): string
    {
        return request()->routeIs('two-factor.confirm') ? 'confirmTwoFactorAuthentication' : 'default';
    }

    private static function usedKey(string $secret, int $step): string
    {
        return AttemptKey::for('two-factor-used', $secret, (string) $step);
    }

    /** Fortify の窓の設定（fortify-options.two-factor-authentication.window）。無ければ 1 */
    private function window(): int
    {
        $window = config('fortify-options.two-factor-authentication.window');

        return is_int($window) ? $window : 1;
    }
}
