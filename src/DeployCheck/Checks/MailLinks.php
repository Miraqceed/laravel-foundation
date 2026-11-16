<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\Mail\AppUrlLinks;
use ReflectionFunction;

/**
 * 再設定・確認のメールのリンクを APP_URL から作る設定（foundation.mail.links_from_app_url）が効いているか。値（URL）は出さない。
 *
 * - 設定が偽なら skip（Laravel のまま、要求の Host から作る）
 * - 真なら、ResetPassword・VerifyEmail の createUrlUsing がパッケージのものなら ok
 * - アプリが自分の createUrlUsing・toMailUsing で作っていれば info（アプリが勝つ。その旨だけを出す）
 */
final class MailLinks implements Check
{
    public function id(): string
    {
        return 'mail_links';
    }

    public function label(): string
    {
        return '再設定・確認のメールのリンクを APP_URL から作る（foundation.mail.links_from_app_url が真のとき）';
    }

    public function run(): Result
    {
        if (! config('foundation.mail.links_from_app_url')) {
            return Result::skip('foundation.mail.links_from_app_url が偽');
        }

        $missing = [];
        $byApp = [];
        foreach (['再設定' => ResetPassword::class, '確認' => VerifyEmail::class] as $name => $notification) {
            $createUrl = $notification::$createUrlCallback;

            if ($notification::$toMailCallback !== null) {
                $byApp[] = "{$name}（toMailUsing でメールを作る）";
            } elseif ($createUrl === null) {
                $missing[] = $name;
            } elseif (! self::isPackageCallback($createUrl)) {
                $byApp[] = "{$name}（createUrlUsing）";
            }
        }

        if ($missing !== []) {
            return Result::fail('設定は真だが、リンクの作り方が登録されていない：'.implode('・', $missing));
        }

        if ($byApp !== []) {
            return Result::info('アプリが自分で作っている：'.implode('・', $byApp));
        }

        return Result::ok();
    }

    private static function isPackageCallback(mixed $callback): bool
    {
        return $callback instanceof Closure
            && (new ReflectionFunction($callback))->getClosureScopeClass()?->getName() === AppUrlLinks::class;
    }
}
