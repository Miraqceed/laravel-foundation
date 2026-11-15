<?php

namespace Miraqceed\Foundation\TestSafety;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;

/**
 * テストの中でつなぐ DB は、名前にテスト用の印（既定は test・testing）を語として含むものだけにする。
 *
 * コンテナの環境変数は `$_SERVER` に入り、Laravel は `$_SERVER` を先に読むので、phpunit.xml の
 * `<env name="DB_DATABASE" … force="true"/>`（phpunit は `$_ENV` と putenv にしか入れない）より勝つ。
 * `docker run --env-file .env … php artisan test --filter=…` で1本だけ流すと、テストが開発用 DB に向き、
 * RefreshDatabase が中身をすべて消す。
 *
 * 確かめるのは接続を作る前（db.factory を包んだ GuardedConnectionFactory::make）で、PDO を開く前に止まる。
 * DB::connection・reconnect・connectUsing・build・`::read` はどれも factory を通り、Event::fake() にも左右されない。
 * factory を通らない接続（DB::extend の独自のドライバーなど）のため、接続ができたときの知らせ（ConnectionEstablished）でも確かめる。
 * TestCase を通さないので、アプリの TestCase の形・Pest・paratest の親プロセスのどれでも同じに働く。
 */
final class TestDatabaseGuard
{
    /**
     * テストの中か。PHPUnit（Pest も）はテストを流すとき必ず TestCase を読み込み、本番には入っていない。
     * paratest の親プロセスはテストのクラスを読む前に DB へつなぐことがあるので、`artisan test --parallel` が渡す印も見る。
     * APP_ENV では決めない（事故の形では APP_ENV も環境変数で上書きされている）。
     */
    public static function runningTests(): bool
    {
        if (class_exists(\PHPUnit\Framework\TestCase::class, false)) {
            return true;
        }

        $parallel = getenv('LARAVEL_PARALLEL_TESTING');
        if ($parallel === false) {
            $parallel = $_SERVER['LARAVEL_PARALLEL_TESTING'] ?? $_ENV['LARAVEL_PARALLEL_TESTING'] ?? '';
        }

        return ! in_array((string) $parallel, ['', '0', 'false'], true);
    }

    /**
     * 名前にテスト用の印が語として入っているか。sqlite の `:memory:` は真。sqlite のファイルはファイル名（拡張子を除く）で見る。
     *
     * @param  list<string>  $markers
     */
    public static function isTestDatabase(string $driver, string $database, array $markers): bool
    {
        if ($driver === 'sqlite') {
            if ($database === ':memory:') {
                return true;
            }
            $database = pathinfo($database, PATHINFO_FILENAME);
        }

        $markers = array_values(array_filter(array_map('strval', $markers), fn (string $marker) => $marker !== ''));
        if ($markers === [] || $database === '') {
            return false;
        }

        $words = implode('|', array_map(fn (string $marker) => preg_quote($marker, '/'), $markers));

        return preg_match('/(^|[_.\-])('.$words.')([_.\-]|$)/i', $database) === 1;
    }

    /**
     * 接続を作る前の設定の配列で確かめ、印がなければ投げる（GuardedConnectionFactory::make から呼ぶ。主な口）。
     * `url` は読んだ後の値で見る。`read`・`write`・`direct` の下位の設定に database があればそれも見る。
     * 接続を作るたびに毎回確かめる（DB::reconnect でつなぎ先を替えたときも factory を通る）。
     *
     * @param  array<string, mixed>  $config
     */
    public static function checkConfig(array $config, string $name): void
    {
        if (in_array($name, (array) config('foundation.test_safety.ignore_connections', []), true)) {
            return;
        }

        $markers = (array) config('foundation.test_safety.markers', ['test', 'testing']);
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        $driver = (string) ($config['driver'] ?? '');

        foreach (self::targets($config) as $target) {
            $database = (string) ($target['database'] ?? '');
            if (! self::isTestDatabase($driver, $database, $markers)) {
                self::purge($name);

                throw new UnsafeTestDatabase(self::message($target, $name, $driver, $database, $markers));
            }
        }
    }

    /**
     * できた接続で確かめる控え（ConnectionEstablished の聞き手から呼ぶ）。DB::extend の独自のドライバーなど、
     * factory を通らずに作られた接続のため。
     */
    public static function check(Connection $connection): void
    {
        self::checkConfig((array) $connection->getConfig(), (string) $connection->getName());
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    private static function targets(array $config): array
    {
        $targets = [$config];
        foreach (['read', 'write', 'direct'] as $type) {
            $sub = $config[$type] ?? null;
            if (! is_array($sub) || $sub === []) {
                continue;
            }
            foreach (array_is_list($sub) ? $sub : [$sub] as $item) {
                if (is_array($item) && array_key_exists('database', $item)) {
                    $targets[] = $item + $config;
                }
            }
        }

        return $targets;
    }

    /**
     * 手元に残った同じ名前の接続を捨てる。DB::reconnect では古い接続が手元に残っており、
     * 知らせの控えの経路では枠組みが接続を手元に入れてから知らせを出す。捨てないと、例外を受け止めて続ける経路で、
     * 次の DB::connection() がその接続を返す
     */
    private static function purge(string $name): void
    {
        if (! Container::getInstance()->resolved('db')) {
            return;
        }
        foreach ([$name, "{$name}::read", "{$name}::write"] as $connectionName) {
            DB::purge($connectionName);
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  list<string>  $markers
     */
    private static function message(array $target, string $name, string $driver, string $database, array $markers): string
    {
        $host = $target['host'] ?? '';
        $where = $driver === 'sqlite'
            ? $database
            : sprintf('%s:%s/%s', is_array($host) ? implode(',', $host) : (string) $host, (string) ($target['port'] ?? ''), $database);

        return sprintf(
            'テストが、名前にテスト用の印（%s）のない DB につなごうとしています。DB に触れる前に止めました（接続 %s：%s %s）。'
            .'テスト用の DB の名前には印を語として入れてください（例：app_test、Laravel Sail の testing）。'
            .'環境変数（DB_DATABASE・DB_URL など）は phpunit.xml の force より勝ちます。docker の --env-file・-e や compose の environment で接続先を渡さず、'
            .'.env.testing などのファイルか phpunit.xml で設定してから流してください。'
            .'テストで読み取りだけに使う外の DB は、名前を偽らずに foundation.test_safety.ignore_connections に接続の名前を挙げてください。',
            implode('・', $markers), $name, $driver, $where,
        );
    }
}
