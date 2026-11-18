<?php

namespace Miraqceed\Foundation\Monitoring;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * 既定の接続の DB の大きさ（バイト）。foundation:watch が容量と比べる。MySQL・MariaDB・PostgreSQL。ほかは null。
 *
 * - MySQL 8：information_schema.TABLES の値は既定で24時間キャッシュされる。読む前に information_schema_stats_expiry を 0 にする
 * - MariaDB：その変数がない（SET が誤りになる）ので飛ばす。値は InnoDB の統計の更新に従い、少し古いことがある
 * - 共有の表領域（innodb_file_per_table が偽）では、どの表もファイル全体の空きを返すので、空きは一番大きい1つだけを足す
 */
class DatabaseSize
{
    public function bytes(): ?int
    {
        /** @var Connection $db */
        $db = DB::connection();

        return match ($db->getDriverName()) {
            'mysql', 'mariadb' => $this->mysql($db),
            'pgsql' => (int) $db->scalar('select pg_database_size(current_database())'),
            default => null,
        };
    }

    /**
     * 表のデータと索引の和に、空きを足す。ファイルが表ごと（MySQL の既定）なら各表の空き、共有の表領域なら一番大きい1つだけ
     *
     * @param  list<array{int, int, int}>  $tables  表ごとのデータ・索引・空き
     */
    public static function total(array $tables, bool $filePerTable): int
    {
        $used = array_sum(array_map(fn (array $table) => $table[0] + $table[1], $tables));
        $free = array_column($tables, 2);

        return $used + ($filePerTable ? array_sum($free) : (int) max([0, ...$free]));
    }

    private function mysql(Connection $db): int
    {
        if (! self::isMariaDb($db)) {
            $db->statement('SET SESSION information_schema_stats_expiry = 0');
        }

        $tables = $db->select('SELECT DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, DATA_FREE AS free_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

        return self::total(
            array_map(fn (object $table) => [(int) $table->data_bytes, (int) $table->index_bytes, (int) $table->free_bytes], $tables),
            (bool) $db->scalar('SELECT @@innodb_file_per_table'),
        );
    }

    /** mysql の driver で MariaDB につなぐアプリもあるので、版の文字列でも見分ける */
    private static function isMariaDb(Connection $db): bool
    {
        return $db->getDriverName() === 'mariadb'
            || str_contains((string) $db->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB');
    }
}
