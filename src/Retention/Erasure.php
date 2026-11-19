<?php

namespace Miraqceed\Foundation\Retention;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Miraqceed\Foundation\Retention\Events\OwnerErased;

/**
 * 台帳のとおりに、持ち主（退会・解約したアカウント）を消す。1件ずつ、1つのトランザクションで。
 *
 * - 持ち主の行を握って読み直し、when が真のときだけ消す（読んだ後に解約が取り消された・2つの経路で2度消す、を止める）
 * - erasedWith の表の行を消し（外部キーの子から）、anonymizedWith の表の行を書き換える。kept・handledByApp の表には触れない
 * - 途中で投げたら全部が巻き戻る（投げ直す。日次のループで1件ずつ捕まえるのはアプリ）
 * - 消した後、出来事 OwnerErased（コミットの後）。表は既定の接続に置く
 */
final class Erasure
{
    /** トランザクションを試す回数（行き詰まり・直列化の失敗で試し直す） */
    private const ATTEMPTS = 3;

    /**
     * @param  callable(object): bool  $when  握って読み直した持ち主の行を受け、消してよいかを返す
     * @return bool 消したか（持ち主がない・when が偽なら false で、何も消さない）
     *
     * @throws LogicException 台帳がない・知らない持ち主・外部キーが輪になるとき
     */
    public function erase(string $owner, int|string $id, callable $when): bool
    {
        $map = ResolvedMap::fromConfig() ?? throw new LogicException('台帳がありません。foundation.retention.map に DataMap のクラスを書いてください');
        $definition = $map->owner($owner);

        $deletes = [];
        $anonymize = [];
        foreach ($map->tables() as $name => $rule) {
            if ($rule->erasures($owner) !== []) {
                $deletes[$name] = $rule;
            }
            if (($rule->anonymization()['owner'] ?? null) === $owner) {
                $anonymize[$name] = $rule;
            }
        }

        $db = DB::connection();
        $order = self::deleteOrder($db, $deletes, $owner);

        return $db->transaction(function () use ($db, $owner, $id, $when, $definition, $deletes, $order, $anonymize): bool {
            $row = $db->table($definition['table'])->where($definition['key'], $id)->lockForUpdate()->first();
            if ($row === null || ! $when($row)) {
                return false;
            }

            $counts = [];
            foreach ($order as $name) {
                foreach ($deletes[$name]->erasures($owner) as $erasure) {
                    $counts[$name] = ($counts[$name] ?? 0) + self::deleteRows($db, $name, $erasure, $id, $definition['table']);
                }
            }
            foreach ($anonymize as $name => $rule) {
                $counts[$name] = ($counts[$name] ?? 0) + self::anonymizeRows($db, $name, (array) $rule->anonymization(), $id);
            }

            // 外のトランザクションの中で呼ばれたら、その確定の後
            $db->afterCommit(fn () => event(new OwnerErased($owner, $id, $counts)));

            return true;
        }, self::ATTEMPTS);
    }

    /**
     * 消す順：外部キー（と via）で親を指す表を、親より先に
     *
     * @param  array<string, TableRule>  $deletes
     * @return list<string>
     */
    private static function deleteOrder(Connection $db, array $deletes, string $owner): array
    {
        $schema = $db->getSchemaBuilder();

        /** @var array<string, array<string, true>> $parents 表 => その表より後に消す表 */
        $parents = [];
        foreach ($deletes as $name => $rule) {
            $parents[$name] = [];
            foreach ($schema->getForeignKeys($name) as $foreignKey) {
                $parent = (string) $foreignKey['foreign_table'];
                if ($parent !== $name && isset($deletes[$parent])) {
                    $parents[$name][$parent] = true;
                }
            }
            foreach ($rule->erasures($owner) as $erasure) {
                $parent = $erasure['via']['table'] ?? null;
                if ($parent !== null && $parent !== $name && isset($deletes[$parent])) {
                    $parents[$name][$parent] = true;
                }
            }
        }

        // 子のない表（どの表からも指されない表）から順に取り出す
        $order = [];
        while ($parents !== []) {
            $pointedAt = [];
            foreach ($parents as $refs) {
                $pointedAt += $refs;
            }
            $leaves = array_values(array_filter(array_keys($parents), fn ($name) => ! isset($pointedAt[$name])));
            if ($leaves === []) {
                throw new LogicException('外部キーが輪になっていて、消す順を決められません：'.implode('・', array_keys($parents)));
            }
            sort($leaves);
            foreach ($leaves as $leaf) {
                $order[] = $leaf;
                unset($parents[$leaf]);
            }
        }

        return $order;
    }

    /** @param  array{owner: string, column: ?string, via: ?array{table: string, column: string, ownerColumn: string, key: string}, morph: ?string}  $erasure */
    private static function deleteRows(Connection $db, string $table, array $erasure, int|string $id, string $ownerTable): int
    {
        $query = $db->table($table);

        if ($erasure['column'] !== null) {
            return $query->where($erasure['column'], $id)->delete();
        }

        if ($erasure['via'] !== null) {
            $via = $erasure['via'];

            return $query->whereIn($via['column'], fn (Builder $parent) => $parent->select($via['key'])->from($via['table'])->where($via['ownerColumn'], $id))->delete();
        }

        // 多態の列：種類が、持ち主の表のモデルの行だけ（同じ id の、別の表の持ち主の行に触れない）
        $type = $erasure['morph'].'_type';
        $idColumn = $erasure['morph'].'_id';
        $types = array_values(array_filter(
            $db->table($table)->where($idColumn, (string) $id)->distinct()->pluck($type)->all(),
            fn ($morphType) => is_string($morphType) && self::morphTable($morphType) === $ownerTable,
        ));
        if ($types === []) {
            return 0;
        }

        return $query->where($idColumn, (string) $id)->whereIn($type, $types)->delete();
    }

    /** 多態の種類（モデルのクラスか、morphMap の名前）のモデルの表 */
    private static function morphTable(string $morphType): ?string
    {
        $class = Relation::getMorphedModel($morphType) ?? $morphType;
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return (new $class)->getTable();
    }

    /** @param  array{owner: string, key: string, erase: array<string, mixed>, keep: list<string>, reason: string}  $anonymize */
    private static function anonymizeRows(Connection $db, string $table, array $anonymize, int|string $id): int
    {
        $values = [];
        foreach ($anonymize['erase'] as $column => $value) {
            $values[$column] = $value instanceof Anonymize ? $value->expression($db) : $value;
        }

        return $db->table($table)->where($anonymize['key'], $id)->update($values);
    }
}
