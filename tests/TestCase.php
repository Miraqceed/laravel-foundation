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
