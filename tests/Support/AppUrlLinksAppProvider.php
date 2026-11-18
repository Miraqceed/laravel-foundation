<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

/** アプリの AppServiceProvider に当たる。パッケージの後に起動して、自分の createUrlUsing を書く */
class AppUrlLinksAppProvider extends ServiceProvider
{
    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token) => 'https://app-own.example.com/reset-password/'.$token);
    }
}
