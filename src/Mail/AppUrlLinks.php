<?php

namespace Miraqceed\Foundation\Mail;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;

/**
 * 再設定・確認のメールのリンクを、要求の Host でなく APP_URL から作る（foundation.mail.links_from_app_url）。
 *
 * Laravel の既定は要求の Host から作るので、Host を偽った要求で再設定を頼むと、
 * 本人に届くメールのリンク（トークンつき）が別のサイトに向く。
 * 全体の URL の状態（forceRootUrl・forceScheme）は変えない。複製した UrlGenerator で作る。
 */
final class AppUrlLinks
{
    /**
     * ResetPassword・VerifyEmail の createUrlUsing に登録する。
     * プロバイダーの起動はパッケージ → アプリの順なので、アプリが自分で createUrlUsing を書けばアプリが勝つ
     */
    public static function register(): void
    {
        ResetPassword::createUrlUsing(self::resetUrl(...));
        VerifyEmail::createUrlUsing(self::verifyUrl(...));
    }

    /** Laravel の既定と同じ経路・引数で、根だけを APP_URL にする */
    public static function resetUrl(object $notifiable, string $token): string
    {
        return self::generator()->route('password.reset', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    /**
     * 署名は APP_URL の根の絶対の URL で作る（Laravel の signed の中間がそのまま通る。Fortify・Breeze の経路を変えなくてよい）
     */
    public static function verifyUrl(object $notifiable): string
    {
        return self::generator()->temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }

    /**
     * 根と scheme を APP_URL に決めた UrlGenerator。全体の url を複製し、要求から切り離す
     * （複製は経路の URL の作り手を元と分け合うので、setRequest で作り直させる）。
     * APP_URL が空なら Laravel の既定のまま（要求の Host）
     */
    private static function generator(): UrlGenerator
    {
        /** @var UrlGenerator $url */
        $url = app('url');
        $root = rtrim((string) config('app.url'), '/');

        if ($root === '') {
            return $url;
        }

        $generator = clone $url;
        $generator->setRequest(Request::create($root));
        $generator->forceRootUrl($root);
        $generator->forceScheme((string) parse_url($root, PHP_URL_SCHEME));

        return $generator;
    }
}
