<?php

namespace Miraqceed\Foundation\Tests\Feature\Csv;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Csv\Rows;
use Miraqceed\Foundation\Tests\Support\RowsChild;
use Miraqceed\Foundation\Tests\Support\RowsParent;
use Miraqceed\Foundation\Tests\Support\RowsScoped;
use Miraqceed\Foundation\Tests\TestCase;

class RowsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('rows_children');
        Schema::dropIfExists('rows_parents');
        Schema::create('rows_parents', function ($t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('rows_children', function ($t) {
            $t->id();
            $t->unsignedBigInteger('parent_id');
            $t->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('rows_children');
        Schema::dropIfExists('rows_parents');

        parent::tearDown();
    }

    private function seedParents(int $count): void
    {
        foreach (array_chunk(range(1, $count), 200) as $ids) {
            RowsParent::insert(array_map(fn ($i) => ['name' => "p{$i}"], $ids));
        }
    }

    public function test_cursor_does_not_skip_or_duplicate_rows_when_an_earlier_row_is_deleted_while_reading(): void
    {
        $this->seedParents(1200);

        $seen = [];
        $deleted = false;
        foreach (Rows::cursor(RowsParent::query()->orderBy('id'), 100) as $row) {
            $seen[] = $row->id;
            if (! $deleted && count($seen) === 250) {
                // すでに読んだ側の行が消えても、OFFSET 方式のように後ろがずれて飛ばない
                RowsParent::whereKey(1)->delete();
                $deleted = true;
            }
        }

        $this->assertSame(range(1, 1200), $seen);
    }

    public function test_keyset_does_not_skip_rows_when_an_earlier_row_is_deleted_while_reading(): void
    {
        $this->seedParents(1200);

        $seen = [];
        $deleted = false;
        foreach (Rows::keyset(RowsParent::query(), 'id', 100) as $row) {
            $seen[] = $row->id;
            if (! $deleted && count($seen) === 250) {
                RowsParent::whereKey(1)->delete();
                $deleted = true;
            }
        }

        $this->assertSame(range(1, 1200), $seen);
    }

    public function test_keyset_reads_a_joined_query(): void
    {
        $this->seedParents(30);
        foreach (RowsParent::query()->pluck('id') as $id) {
            RowsChild::create(['parent_id' => $id, 'name' => "c{$id}"]);
        }

        $query = RowsParent::query()
            ->join('rows_children', 'rows_children.parent_id', '=', 'rows_parents.id')
            ->select('rows_parents.*');

        $ids = [];
        foreach (Rows::keyset($query, 'id', 10) as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame(range(1, 30), $ids);
    }

    public function test_keyset_throws_when_the_column_is_not_selected(): void
    {
        $this->seedParents(5);

        $this->expectException(\LogicException::class);

        foreach (Rows::keyset(RowsParent::query()->select('name'), 'id', 2) as $_) {
        }
    }

    public function test_keyset_ignores_offset_and_limit_on_the_given_query(): void
    {
        $this->seedParents(20);

        $ids = [];
        foreach (Rows::keyset(RowsParent::query()->offset(5)->limit(3), 'id', 4) as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame(range(1, 20), $ids);
    }

    public function test_cursor_loads_relations_in_chunks(): void
    {
        $this->seedParents(1200);
        foreach (RowsParent::query()->pluck('id') as $id) {
            RowsChild::create(['parent_id' => $id, 'name' => "c{$id}"]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $count = 0;
        foreach (Rows::cursor(RowsParent::query()->with('children')->orderBy('id')) as $row) {
            $this->assertCount(1, $row->children);
            $count++;
        }

        $this->assertSame(1200, $count);
        $this->assertLessThanOrEqual(1 + (int) ceil(1200 / 500) * 1, $queries);
    }

    /** 終わらないときに試しが止まらなくならないよう、$limit 行で打ち切って集める */
    private function collectIds(iterable $rows, int $limit = 1000): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->id;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    public function test_keyset_with_or_where_reads_each_row_once_and_ends(): void
    {
        $this->seedParents(30);

        // 括弧でくくらずに id > ? を足すと「name like p1% or (name like p% and id > ?)」になる。
        // p1・p10〜p19 の11行（$page 以上）が毎ページ返り、最後の id が進まず終わらない
        $query = RowsParent::query()->where('name', 'like', 'p1%')->orWhere('name', 'like', 'p%');

        $this->assertSame(range(1, 30), $this->collectIds(Rows::keyset($query, 'id', 10)));
    }

    public function test_keyset_with_or_where_on_a_model_with_global_scope(): void
    {
        $this->seedParents(30);
        RowsParent::insert([['name' => 'x1'], ['name' => 'x2']]);

        $query = RowsScoped::query()->where('name', 'p1')->orWhere('name', 'like', 'p%');

        $this->assertSame(range(1, 30), $this->collectIds(Rows::keyset($query, 'id', 10)));
    }

    public function test_keyset_does_not_change_the_given_query(): void
    {
        $query = RowsParent::query()->where('name', 'p1')->orWhere('name', 'p2');
        $before = [$query->toSql(), $query->getBindings()];

        foreach (Rows::keyset($query, 'id', 10) as $_) {
        }

        $this->assertSame($before, [$query->toSql(), $query->getBindings()]);
    }

    public function test_keyset_reads_all_rows_once(): void
    {
        $this->seedParents(1001);

        $ids = [];
        foreach (Rows::keyset(RowsParent::query(), 'id', 500) as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame(range(1, 1001), $ids);
    }
}
