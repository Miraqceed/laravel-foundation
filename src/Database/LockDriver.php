<?php

namespace Miraqceed\Foundation\Database;

use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * @internal ロックの助けが扱う DB の種類。MySQL（MariaDB を含む）・PostgreSQL・SQLite のほかは断る
 */
enum LockDriver
{
    case MySql;
    case Postgres;
    case Sqlite;

    /** @throws LogicException 扱わない driver のとき */
    public static function of(ConnectionInterface $db): self
    {
        $driver = method_exists($db, 'getDriverName') ? $db->getDriverName() : '';

        return match ($driver) {
            'mysql', 'mariadb' => self::MySql,
            'pgsql' => self::Postgres,
            'sqlite' => self::Sqlite,
            default => throw new LogicException("DB のロックの助けは、MySQL・PostgreSQL・SQLite だけを扱います（この接続の driver は {$driver}）"),
        };
    }
}
