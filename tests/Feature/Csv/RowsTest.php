<?php

namespace Miraqceed\Foundation\Tests\Feature\Csv;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Csv\Rows;
use Miraqceed\Foundation\Tests\Support\RowsChild;
use Miraqceed\Foundation\Tests\Support\RowsParent;
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

    public function test_cursor_does_not_duplicate_rows_inserted_while_reading(): void
    {
        $this->seedParents(1200);

        $seen = [];
        $inserted = false;
        foreach (Rows::cursor(RowsParent::query()->orderBy('id'), 100) as $row) {
            $seen[] = $row->id;
            if (! $inserted && count($seen) === 50) {
                RowsParent::create(['name' => 'late']);
                $inserted = true;
            }
        }

        $this->assertSame(range(1, 1200), array_slice($seen, 0, 1200));
        $this->assertSame(count($seen), count(array_unique($seen)));
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
