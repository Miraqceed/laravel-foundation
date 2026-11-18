<?php

namespace Miraqceed\Foundation\Tests;

use Miraqceed\Foundation\FoundationServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FoundationServiceProvider::class];
    }

    /**
     * DB は環境変数 DB_CONNECTION（pgsql・mysql・sqlite）で切り替える。CI の組み合わせが渡す。
     */
    protected function defineEnvironment($app): void
    {
        // 実際のアプリは APP_KEY を持つ（セッション・暗号に要る）。数えの鍵（AttemptKey）が HMAC に使う。作り物の値
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        // 記録の間引きの目印は、試しごとに別の所に置く（storage に残ると、後の試しの記録が間引かれる）
        $app['config']->set('foundation.failures.throttle_path', sys_get_temp_dir().'/foundation-report-throttle-'.bin2hex(random_bytes(8)));

        $connection = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', $connection);

        if ($connection === 'sqlite') {
            $app['config']->set('database.connections.sqlite.database', env('DB_DATABASE', ':memory:'));

            return;
        }

        // ポートは環境変数があるときだけ上書きする（nullで上書きするとドライバーの既定が消える）
        $port = env('DB_PORT') !== null ? ['port' => env('DB_PORT')] : [];

        $app['config']->set("database.connections.{$connection}", array_merge(
            $app['config']->get("database.connections.{$connection}", []),
            $port,
            [
                'host' => env('DB_HOST', '127.0.0.1'),
                'database' => env('DB_DATABASE', 'foundation_test'),
                'username' => env('DB_USERNAME', 'foundation'),
                'password' => env('DB_PASSWORD', 'foundation'),
            ],
        ));
    }
}
