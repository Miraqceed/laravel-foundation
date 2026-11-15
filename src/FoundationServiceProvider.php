<?php

namespace Miraqceed\Foundation;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Miraqceed\Foundation\Monitoring\Commands\RetryAlertsCommand;
use Miraqceed\Foundation\Security\CspReportController;
use Miraqceed\Foundation\Security\Nonce;
use Miraqceed\Foundation\Security\SecurityHeaders;

class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Nonce::class);

        // mergeConfigFrom は浅い合併なので、アプリが一部だけ書いた配列（login の一部など）が
        // パッケージの既定を丸ごと消す。入れ子の鍵は再帰して合併する（アプリの値が勝つ）
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            $config->set('foundation', self::mergeDefaults(
                require __DIR__.'/../config/foundation.php',
                $config->get('foundation', []),
            ));
        }
    }

    /**
     * 連想配列は再帰して合併し、リスト（markers など）はアプリの値で置き換える。
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function mergeDefaults(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            // 空の配列（雛形の `'login' => []` など）は、既定が連想配列なら既定を保つ
            if ($value === [] && is_array($defaults[$key] ?? null) && ! array_is_list($defaults[$key])) {
                continue;
            }

            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])
                && ! array_is_list($value) && ! array_is_list($defaults[$key])) {
                $defaults[$key] = self::mergeDefaults($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    public function boot(): void
    {
        // 別名はいつも登録する（無効のあいだは何もしない）。グループへは足さない
        $this->app['router']->aliasMiddleware('foundation.security-headers', SecurityHeaders::class);

        // 報告の口は web のグループの外（セッション・CSRF なし）。決めたときだけ登録する
        $report = config('foundation.security.report_route');
        if (is_string($report) && $report !== '' && ! $this->app->routesAreCached()) {
            Route::post($report, CspReportController::class)->name('foundation.csp-report');

            // 全体の TrimStrings・ConvertEmptyStringsToNull は JSON の本文を丸ごと解く（$request->json()）。
            // 8KB の上限より前に大きな本文を読ませないよう、この経路は飛ばす
            $skip = fn (Request $request) => $request->is(ltrim($report, '/'));
            TrimStrings::skipWhen($skip);
            ConvertEmptyStringsToNull::skipWhen($skip);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/foundation.php' => config_path('foundation.php'),
            ], 'foundation-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'foundation-migrations');

            // コマンドはいつも登録する（monitoring が無効なら何もしない）
            $this->commands([RetryAlertsCommand::class]);
        }

        // マイグレーションは monitoring が有効なときだけ読み込む。
        // 無効でも `vendor:publish --tag=foundation-migrations` で写せる。
        if (config('foundation.monitoring.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
