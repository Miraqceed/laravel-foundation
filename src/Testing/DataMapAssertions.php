<?php

namespace Miraqceed\Foundation\Testing;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Retention\ResolvedMap;
use Miraqceed\Foundation\Retention\ScheduledPruning;
use Miraqceed\Foundation\Retention\TableRule;
use PHPUnit\Framework\Assert;

/**
 * 試しの助け：消す決まりの台帳（foundation.retention.map）の書き忘れを見つける。本番のコードからは呼ばない。
 *
 * - assertCoversSchema()：試しの DB（マイグレーションの後）のすべての表が台帳にあり、行を残す表のすべての列が分けてある
 * - assertScheduled()：台帳の期限の消し方（prunedBy のモデル・prunedByCommand のコマンド）が schedule にある
 *
 * 落ちた文に、足りないものと、書き足す1行の例を出す
 */
final class DataMapAssertions
{
    public static function assertCoversSchema(): void
    {
        $map = self::map();
        $db = DB::connection();
        $schema = $db->getSchemaBuilder();

        $migrations = config('database.migrations');
        $migrations = is_array($migrations) ? ($migrations['table'] ?? 'migrations') : ($migrations ?? 'migrations');
        $tables = array_values(array_diff(self::tablesInDatabase($db), [$migrations]));
        $rules = $map->tables();
        $columns = fn (string $table): array => $schema->getColumnListing($table);

        $problems = [];
        foreach ($tables as $table) {
            if (! isset($rules[$table])) {
                $problems[] = "台帳にない表：{$table}\n  足す1行の例：".self::suggestTable($table, $columns($table), array_keys($map->owners()));
            }
        }

        foreach ($map->owners() as $name => $owner) {
            if (! in_array($owner['table'], $tables, true)) {
                $problems[] = "持ち主 {$name} の表 {$owner['table']} が DB にない";
            } elseif (! in_array($owner['key'], $columns($owner['table']), true)) {
                $problems[] = "持ち主 {$name} の鍵 {$owner['table']}.{$owner['key']} が DB にない";
            }
        }

        foreach ($rules as $table => $rule) {
            if (! in_array($table, $tables, true)) {
                $problems[] = "台帳の表 {$table} が DB にない（名前の書き損じか、消した表）";

                continue;
            }
            $problems = array_merge($problems, self::columnProblems($table, $rule, $columns($table), $columns));
        }

        if ($problems !== []) {
            Assert::fail("消す決まりの台帳（foundation.retention.map）が DB の作りと合わない：\n- ".implode("\n- ", $problems));
        }

        Assert::assertTrue(true);
    }

    public static function assertScheduled(): void
    {
        $problems = ScheduledPruning::missing(self::map(), lookAtDatabase: true, currentEnvironmentOnly: false);

        if ($problems !== []) {
            Assert::fail("台帳の期限の消し方が schedule にない：\n- ".implode("\n- ", $problems));
        }

        Assert::assertTrue(true);
    }

    private static function map(): ResolvedMap
    {
        $map = ResolvedMap::fromConfig();
        if ($map === null) {
            Assert::fail('消す決まりの台帳がない。foundation.retention.map に Miraqceed\Foundation\Retention\DataMap のクラスを書く（docs/retention.md）');
        }

        return $map;
    }

    /**
     * 今の接続の schema の表（PostgreSQL・MySQL では、ほかの schema の表を数えない）
     *
     * @return list<string>
     */
    private static function tablesInDatabase(Connection $db): array
    {
        $current = match ($db->getDriverName()) {
            'pgsql' => (string) $db->selectOne('select current_schema() as name')->name,
            'mysql', 'mariadb' => $db->getDatabaseName(),
            default => null,
        };

        $tables = [];
        foreach ($db->getSchemaBuilder()->getTables() as $table) {
            if ($current === null || ($table['schema'] ?? $current) === $current) {
                $tables[] = (string) $table['name'];
            }
        }
        sort($tables);

        return array_values(array_unique($tables));
    }

    /**
     * @param  list<string>  $columns
     * @param  \Closure(string): list<string>  $columnsOf
     * @return list<string>
     */
    private static function columnProblems(string $table, TableRule $rule, array $columns, \Closure $columnsOf): array
    {
        $problems = [];
        $has = fn (string $column, ?array $in = null): bool => in_array($column, $in ?? $columns, true);

        foreach ($rule->erasures() as $erasure) {
            if ($erasure['column'] !== null && ! $has($erasure['column'])) {
                $problems[] = "{$table}：erasedWith の列 {$erasure['column']} が DB にない";
            }
            if ($erasure['via'] !== null) {
                $via = $erasure['via'];
                if (! $has($via['column'])) {
                    $problems[] = "{$table}：via の列 {$via['column']} が DB にない";
                }
                $parentColumns = $columnsOf($via['table']);
                if ($parentColumns === []) {
                    $problems[] = "{$table}：via の表 {$via['table']} が DB にない";
                } else {
                    foreach ([$via['ownerColumn'], $via['key']] as $column) {
                        if (! $has($column, $parentColumns)) {
                            $problems[] = "{$table}：via の列 {$via['table']}.{$column} が DB にない";
                        }
                    }
                }
            }
            if ($erasure['morph'] !== null) {
                foreach ([$erasure['morph'].'_type', $erasure['morph'].'_id'] as $column) {
                    if (! $has($column)) {
                        $problems[] = "{$table}：morph の列 {$column} が DB にない";
                    }
                }
            }
        }

        $anonymize = $rule->anonymization();
        if ($anonymize !== null) {
            if (! $has($anonymize['key'])) {
                $problems[] = "{$table}：anonymizedWith の key の列 {$anonymize['key']} が DB にない";
            }

            $written = array_merge(array_keys($anonymize['erase']), $anonymize['keep']);
            $unsorted = array_values(array_diff($columns, $written));
            if ($unsorted !== []) {
                $problems[] = "{$table}：行を残す表の、分けていない列：".implode('・', $unsorted)
                    ."\n  erase か keep に足す。例：erase: [".implode(', ', array_map(fn ($column) => "'{$column}' => null", $unsorted)).']'
                    .'、keep: ['.implode(', ', array_map(fn ($column) => "'{$column}'", $unsorted)).']';
            }
            foreach (array_diff($written, $columns) as $column) {
                $problems[] = "{$table}：台帳の列 {$column} が DB にない（名前の書き損じか、消した列）";
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $owners
     */
    private static function suggestTable(string $table, array $columns, array $owners): string
    {
        foreach ($owners as $owner) {
            if (in_array("{$owner}_id", $columns, true)) {
                return "\$map->table('{$table}')->erasedWith('{$owner}', column: '{$owner}_id');";
            }
        }

        return "\$map->table('{$table}')->kept('残す理由');（ほかに ->erasedWith()・->anonymizedWith()・->prunedBy()・->prunedByCommand()・->handledByApp()）";
    }
}
