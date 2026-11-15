<?php

namespace Miraqceed\Foundation\TestSafety;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * テストの中でつなぐ DB は、名前にテスト用の印（既定は test・testing）を語として含むものだけにする。
 *
 * コンテナの環境変数は `$_SERVER` に入り、Laravel は `$_SERVER` を先に読むので、phpunit.xml の
 * `<env name="DB_DATABASE" … force="true"/>`（phpunit は `$_ENV` と putenv にしか入れない）より勝つ。フォーム担当で、
 * `docker run --env-file .env … php artisan test --filter=…` で1本だけ流したら、テストが開発用 DB（`otonai`）に向き、
 * RefreshDatabase が中身をすべて消した（2026-11-07。#834）。
 *
 * 確かめるのは接続ができたときの知らせ（ConnectionEstablished）で、PDO を作る前（接続の設定ができた時点）。
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
     * 接続の設定（DB_URL を読んだ後の値）で確かめ、印がなければ接続を捨ててから投げる。
     * 接続ができるたびに毎回確かめる（つなぎ先を替えてつなぎ直したときも）。
     */
    public static function check(Connection $connection): void
    {
        $name = (string) $connection->getName();
        if (in_array($name, (array) config('foundation.test_safety.ignore_connections', []), true)) {
            return;
        }

        $markers = (array) config('foundation.test_safety.markers', ['test', 'testing']);
        $driver = (string) $connection->getDriverName();
        $database = (string) $connection->getConfig('database');

        if (self::isTestDatabase($driver, $database, $markers)) {
            return;
        }

        // 枠組みは接続を手元に入れてから知らせを出す。捨てないと、例外を受け止めて続ける経路で、
        // 次の DB::connection() が知らせなしにこの接続を返す
        foreach ([$name, "{$name}::read", "{$name}::write"] as $connectionName) {
            DB::purge($connectionName);
        }

        throw new UnsafeTestDatabase(self::message($connection, $name, $driver, $database, $markers));
    }

    /** @param  list<string>  $markers */
    private static function message(Connection $connection, string $name, string $driver, string $database, array $markers): string
    {
        $where = $driver === 'sqlite'
            ? $database
            : sprintf('%s:%s/%s', (string) $connection->getConfig('host'), (string) $connection->getConfig('port'), $database);

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
