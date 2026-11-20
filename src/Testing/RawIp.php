<?php

namespace Miraqceed\Foundation\Testing;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Failures\IpSpellings;
use PHPUnit\Framework\Assert;

/**
 * 試しの助け：試しの DB に、送った IP がそのまま残っていないかを確かめる。本番のコードからは呼ばない。
 *
 * - 今の接続の schema のすべての表の、文字の列（char・varchar・text の類・json、PostgreSQL の inet・cidr）を探す。migrations は見ない
 * - IP の書き方の揺れ（IPv6 の大文字・:: の省略・全部の形、IPv4 を埋め込んだ IPv6）も見つける。長い数の一部（203.0.113.50 の中の 203.0.113.5）は当てない
 * - 落ちた文には表と列の名前だけを出す（値は出さない）
 *
 * 見つけないもの：秘密のない sha1・md5 にした値、base64 にした値（sessions.payload の中など）、文字でない列（blob・bytea）
 */
final class RawIp
{
    /** 1つの列で、PHP で確かめ直す行の上限（LIKE で拾った候補） */
    private const ROWS_PER_COLUMN = 1000;

    /**
     * @throws \InvalidArgumentException IP として読めないとき
     */
    public static function assertNotStored(string $ip, ?string $connection = null): void
    {
        $forms = IpSpellings::forms($ip);
        if ($forms === null) {
            throw new \InvalidArgumentException('IP として読めない値です（RawIp::assertNotStored() には IP を渡す）');
        }
        $forms = array_values(array_unique(array_map('strtolower', [...$forms['ipv6'], ...$forms['ipv4']])));

        $db = DB::connection($connection);
        $found = [];
        foreach (self::tables($db) as $table) {
            foreach ($db->getSchemaBuilder()->getColumns($table) as $column) {
                if (! self::isText((string) $column['type_name'])) {
                    continue;
                }
                if (self::columnHolds($db, $table, (string) $column['name'], $forms, $ip)) {
                    $found[] = "{$table}.{$column['name']}";
                }
            }
        }

        // 見つからなくても、確かめの1回として数える（確かめのない試しにしない）
        Assert::assertSame([], $found, '生の IP が DB に残っている（表.列。値は出さない）：'.implode('、', $found));
    }

    /** @param  list<string>  $forms  小文字にした書き方 */
    private static function columnHolds(Connection $db, string $table, string $column, array $forms, string $ip): bool
    {
        $grammar = $db->getQueryGrammar();
        $text = match ($db->getDriverName()) {
            'mysql', 'mariadb' => 'CAST('.$grammar->wrap($column).' AS CHAR)',
            default => 'CAST('.$grammar->wrap($column).' AS TEXT)',
        };

        $query = $db->table($table)->select($column)->limit(self::ROWS_PER_COLUMN);
        $query->where(function ($where) use ($forms, $text): void {
            foreach ($forms as $form) {
                // ! で LIKE の %・_・! を逃がす（どの DB でも同じ書き方になる）
                $where->orWhereRaw("LOWER({$text}) LIKE ? ESCAPE '!'", ['%'.self::escapeLike($form).'%']);
            }
        });

        foreach ($query->pluck($column) as $value) {
            $value = (string) $value;
            // LIKE は長い数の一部でも当たる。境目は IpSpellings と同じ決まりで確かめ直す
            if (IpSpellings::redact($value, $ip, "\0") !== $value) {
                return true;
            }
        }

        return false;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private static function isText(string $type): bool
    {
        $type = strtolower($type);

        return str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'clob')
            || in_array($type, ['json', 'jsonb', 'inet', 'cidr'], true);
    }

    /**
     * 今の接続の schema の表（PostgreSQL・MySQL では、ほかの schema の表を見ない）。migrations の表は除く
     *
     * @return list<string>
     */
    private static function tables(Connection $db): array
    {
        $current = match ($db->getDriverName()) {
            'pgsql' => (string) $db->selectOne('select current_schema() as name')->name,
            'mysql', 'mariadb' => $db->getDatabaseName(),
            default => null,
        };

        $migrations = config('database.migrations');
        $migrations = is_array($migrations) ? ($migrations['table'] ?? 'migrations') : ($migrations ?? 'migrations');

        $tables = [];
        foreach ($db->getSchemaBuilder()->getTables() as $table) {
            if (($current === null || ($table['schema'] ?? $current) === $current) && $table['name'] !== $migrations) {
                $tables[] = (string) $table['name'];
            }
        }
        sort($tables);

        return array_values(array_unique($tables));
    }
}
