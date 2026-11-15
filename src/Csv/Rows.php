<?php

namespace Miraqceed\Foundation\Csv;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 問い合わせの全件を1行ずつ返す（書き出しの元）。`lazy()` と `chunk()` は使わない：
 * LIMIT/OFFSET でページを繰るので、読んでいる間に行が増減すると境目の行が重複・欠落する。
 */
class Rows
{
    /**
     * 1回の問い合わせで読む（ずれない）。`cursor()` は `with()` を無視するので、
     * クエリの eager load の指定を `$chunk` 行ずつ `loadMissing` でまとめて読む。
     *
     * @return Generator<int, \Illuminate\Database\Eloquent\Model>
     */
    public static function cursor(Builder $query, int $chunk = 500): Generator
    {
        $relations = $query->getEagerLoads();

        foreach ($query->cursor()->chunk($chunk) as $rows) {
            $models = new Collection($rows->values()->all());

            if ($relations !== []) {
                $models->loadMissing($relations);
            }

            foreach ($models as $model) {
                yield $model;
            }
        }
    }

    /**
     * `$column > 前の最後` で読み進める。DB のカーソルを長く開けない代わりに、
     * 読んでいる間に増えた行（`$column` の大きい側）も読む。
     *
     * `$column` は一意で並べられる列であること（重複があると行を飛ばす）。
     * 並びは `$column` の昇順に置き換える。
     *
     * @return Generator<int, \Illuminate\Database\Eloquent\Model>
     */
    public static function keyset(Builder $query, string $column = 'id', int $page = 500): Generator
    {
        $last = null;

        while (true) {
            $q = clone $query;
            $q->reorder($column);
            if ($last !== null) {
                $q->where($column, '>', $last);
            }

            $models = $q->limit($page)->get();

            if ($models->isEmpty()) {
                return;
            }

            foreach ($models as $model) {
                yield $model;
            }

            if ($models->count() < $page) {
                return;
            }

            $last = $models->last()->getAttribute($column);
        }
    }
}
