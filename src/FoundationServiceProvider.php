<?php

namespace Miraqceed\Foundation;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\ClassificationFailed;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Miraqceed\Foundation\Ai\AiGuard;
use Miraqceed\Foundation\Ai\AiRecorder;
use Miraqceed\Foundation\Ai\AiStore;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Commands\CachePruneCommand;
use Miraqceed\Foundation\Commands\DbTimezoneCommand;
use Miraqceed\Foundation\Commands\DeployCheckCommand;
use Miraqceed\Foundation\Commands\InstallCommand;
use Miraqceed\Foundation\Failures\FailureReporter;
use Miraqceed\Foundation\Health\ConfiguredChecks;
use Miraqceed\Foundation\Mail\AppUrlLinks;
use Miraqceed\Foundation\Mail\DropReservedRecipients;
use Miraqceed\Foundation\Mail\MarkAutoSubmitted;
use Miraqceed\Foundation\Monitoring\Commands\RecordCommand;
use Miraqceed\Foundation\Monitoring\Commands\RetryAlertsCommand;
use Miraqceed\Foundation\Monitoring\Commands\WatchCommand;
use Miraqceed\Foundation\Monitoring\TaskRuns;
use Miraqceed\Foundation\Monitoring\WatchList;
use Miraqceed\Foundation\Queue\CronWorkers;
use Miraqceed\Foundation\Redaction\Redactor;
use Miraqceed\Foundation\RequestId\AssignRequestId;
use Miraqceed\Foundation\Security\CspReportController;
use Miraqceed\Foundation\Security\Nonce;
use Miraqceed\Foundation\Security\SecurityHeaders;
use Miraqceed\Foundation\TestSafety\GuardedConnectionFactory;
use Miraqceed\Foundation\TestSafety\TestDatabaseGuard;
use Miraqceed\Foundation\TwoFactor\TwoFactor;

class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Nonce::class);
        $this->app->singleton(WatchList::class);
        $this->app->singleton(CronWorkers::class);
        $this->app->singleton(AiStore::class);
        // 設定の書き違いは、初めて取り出したときに InvalidRedactionConfig で分かる
        $this->app->singleton(Redactor::class, fn () => Redactor::fromConfig());
        $this->app->singleton(Attempts::class);
        $this->app->singleton(TwoFactor::class);
        $this->app->singleton(FailureReporter::class);

        // mergeConfigFrom は浅い合併なので、アプリが一部だけ書いた配列（login の一部など）が
        // パッケージの既定を丸ごと消す。入れ子の鍵は再帰して合併する（アプリの値が勝つ）
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            $config->set('foundation', self::mergeDefaults(
                require __DIR__.'/../config/foundation.php',
                $config->get('foundation', []),
            ));
        }

        // テストの DB の守り。いつも登録し、テストの中のときだけ確かめる。
        // ほかのプロバイダーの boot が DB へつなぐ前に効くよう、register で登録する。
        // 主な口は接続を作る前の factory（reconnect・Event::fake()・::read でも PDO を開く前に止まる）
        $this->app->extend('db.factory', fn (ConnectionFactory $factory, $app) => new GuardedConnectionFactory($app, $factory));
        // 控え：factory を通らない接続（DB::extend の独自のドライバーなど）
        $this->app['events']->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            if (TestDatabaseGuard::runningTests()) {
                TestDatabaseGuard::check($event->connection);
            }
        });
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

    /** Laravel AI SDK が入っているか（試しで差し替えられるよう、ここだけで確かめる） */
    protected static function aiSdkInstalled(): bool
    {
        return class_exists(StartingStep::class);
    }

    /**
     * AI の費用の上限と記録の聞き手（SDK の知らせ → 呼ぶもの）
     *
     * @return array<class-string, array{class-string, string}>
     */
    private static function aiListeners(): array
    {
        return [
            StartingStep::class => [AiGuard::class, 'handleStartingStep'],
            Classifying::class => [AiGuard::class, 'handleClassifying'],
            StepCompleted::class => [AiRecorder::class, 'handleStepCompleted'],
            Classified::class => [AiRecorder::class, 'handleClassified'],
            StepFailed::class => [AiRecorder::class, 'handleStepFailed'],
            ClassificationFailed::class => [AiRecorder::class, 'handleClassificationFailed'],
        ];
    }

    public function boot(): void
    {
        // 別名はいつも登録する（無効のあいだは何もしない）。グループへは足さない
        $this->app['router']->aliasMiddleware('foundation.security-headers', SecurityHeaders::class);
        $this->app['router']->aliasMiddleware('foundation.request-id', AssignRequestId::class);

        // テストの中だけ、Http::fake() に当たらない外への要求を送る前に止める（既定は偽。入れただけでは掛けない）。
        // 判定は DB の守りと同じ関数。試しごとにアプリを作り直すので、起動のたびに掛ける
        if (config('foundation.test_safety.prevent_stray_requests') && TestDatabaseGuard::runningTests()) {
            $this->app->make(HttpFactory::class)->preventStrayRequests();
        }

        // 検証の規則の文（foundation::validation.*）。アプリは lang/vendor/foundation で上書きできる
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'foundation');

        // 実在しない宛先を送る前に外す聞き手。いつも登録し、送るときに設定を読む（偽なら何もしない）
        $this->app['events']->listen(MessageSending::class, [DropReservedRecipients::class, 'handle']);
        // すべての送るメールに Auto-Submitted を足す聞き手。同じく、いつも登録し、送るときに設定を読む（偽なら何もしない）
        $this->app['events']->listen(MessageSending::class, [MarkAutoSubmitted::class, 'handle']);

        // 再設定・確認のリンクを APP_URL から作る。決めたときだけ登録する（既定は Laravel のまま、要求の Host から作る）。
        // アプリのプロバイダーはこの後に起動するので、アプリの createUrlUsing が勝つ
        if (config('foundation.mail.links_from_app_url')) {
            AppUrlLinks::register();
        }

        // 稼働の確かめで見るもの。書いたときだけ聞き手を足す（Laravel の既定の /up も DiagnosingHealth を出すので、そちらでも効く）。
        // 名前は起動のときに見ない（書き損じで本番の全部の要求を落とさない。確かめの中で NG になる）
        if ((array) config('foundation.health.checks', []) !== []) {
            $this->app['events']->listen(DiagnosingHealth::class, [ConfiguredChecks::class, 'handle']);
        }

        // 報告の口は web のグループの外（セッション・CSRF なし）。決めたときだけ登録する
        $report = config('foundation.security.report_route');
        if (is_string($report) && $report !== '') {
            // route:cache の後は経路がキャッシュから読まれるので登録しない
            if (! $this->app->routesAreCached()) {
                Route::post($report, CspReportController::class)->name('foundation.csp-report');
            }

            // 全体の TrimStrings・ConvertEmptyStringsToNull は JSON の本文を丸ごと解く（$request->json()）。
            // 8KB の上限より前に大きな本文を読ませないよう、この経路は飛ばす。
            // これはキャッシュされないので、route:cache のときもいつも登録する
            $skip = fn (Request $request) => $request->is(ltrim($report, '/'));
            TrimStrings::skipWhen($skip);
            ConvertEmptyStringsToNull::skipWhen($skip);
        }

        // watched() はいつも登録する。無効のあいだは印を付けるだけで、記録も見張りもしない
        Event::macro('watched', function (string $name, int $maxAgeMinutes) {
            /** @var Event $this */
            app(WatchList::class)->mark($this, $name, $maxAgeMinutes);

            return $this
                ->before(fn () => TaskRuns::started($name))
                ->onSuccess(fn () => TaskRuns::finished($name, true))
                ->onFailure(fn () => TaskRuns::finished($name, false));
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/foundation.php' => config_path('foundation.php'),
            ], 'foundation-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'foundation-migrations');

            $this->publishes([
                __DIR__.'/../database/ai-migrations' => database_path('migrations'),
            ], 'foundation-ai-migrations');

            // 二段階の認証の列。自動では読み込まない（パッケージが users を勝手に変えない）
            $this->publishes([
                __DIR__.'/../database/two-factor-migrations' => database_path('migrations'),
            ], 'foundation-two-factor-migrations');

            $this->publishes([
                __DIR__.'/../database/one-time-code-migrations' => database_path('migrations'),
            ], 'foundation-one-time-code-migrations');

            $this->publishes([
                __DIR__.'/../database/email-change-migrations' => database_path('migrations'),
            ], 'foundation-email-change-migrations');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/foundation'),
            ], 'foundation-lang');

            // コマンドはいつも登録する（monitoring が無効なら何もしない）
            // foundation:cache-prune は schedule に入れない（入れるのはアプリ。入れただけでは何も消えない）
            $this->commands([InstallCommand::class, CachePruneCommand::class, DbTimezoneCommand::class, DeployCheckCommand::class, RetryAlertsCommand::class, RecordCommand::class, WatchCommand::class]);
        }

        // マイグレーションは monitoring が有効なときだけ読み込む。
        // 無効でも `vendor:publish --tag=foundation-migrations` で写せる。
        // AI の費用の上限と記録の表も、有効なときだけ読み込む
        if (config('foundation.ai.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/ai-migrations');

            // Laravel AI SDK の知らせを聞く。SDK の入っていないアプリでは何もしない
            if (static::aiSdkInstalled()) {
                foreach (self::aiListeners() as $event => $listener) {
                    $this->app['events']->listen($event, $listener);
                }
            }
        }

        // 1回だけ使える合言葉の表も、有効なときだけ読み込む
        if (config('foundation.one_time_codes.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/one-time-code-migrations');
        }

        // メールアドレスの変更の表も、有効なときだけ読み込む（入れただけでは作らない）
        if (config('foundation.email_changes.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/email-change-migrations');
        }

        if (config('foundation.monitoring.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            // 見張りを入れ忘れると見張りが働かないので自動で入れる
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('foundation:watch')
                    ->cron((string) config('foundation.monitoring.watch_cron', '0 * * * *'))
                    // 落ちたとき（kill・OOM）にロックが24時間残らないよう、期限は短くする（分）
                    ->withoutOverlapping(10);
            });
        }
    }
}
