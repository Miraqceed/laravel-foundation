<?php

namespace Miraqceed\Foundation;

use Illuminate\Support\ServiceProvider;

class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/foundation.php', 'foundation');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/foundation.php' => config_path('foundation.php'),
            ], 'foundation-config');
        }

        // マイグレーションは monitoring が有効なときだけ読み込む。
        // 無効でも `vendor:publish --tag=foundation-migrations` で写せる（Task 7 で中身を足す）。
        if (config('foundation.monitoring.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
