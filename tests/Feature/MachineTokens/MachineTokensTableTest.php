<?php

namespace Miraqceed\Foundation\Tests\Feature\MachineTokens;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Retention\ResolvedMap;
use Miraqceed\Foundation\Tests\Support\RefreshesMachineTokenDatabase;
use Miraqceed\Foundation\Tests\Support\Retention\ShopDataMap;
use Miraqceed\Foundation\Tests\TestCase;

/** 有効にしたときの表：列・一意の索引・字のとおりの比べ・台帳 */
class MachineTokensTableTest extends TestCase
{
    use RefreshesMachineTokenDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.machine_tokens.enabled', true);
    }

    public function test_the_table_has_the_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('foundation_machine_tokens', [
            'id', 'kind', 'subject', 'current_hash', 'current_issued_at', 'pending_hash', 'pending_issued_at',
            'previous_hash', 'previous_until', 'last_used_at', 'created_at',
        ]));
    }

    public function test_kind_and_subject_are_unique_and_compared_exactly(): void
    {
        $this->insertRow('server:A', str_repeat('a', 64));
        // 大文字・小文字の違う対象は別の行（MySQL の既定の照合順序では同じと見なされる）
        $this->insertRow('server:a', str_repeat('b', 64));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertRow('server:A', str_repeat('c', 64));
    }

    public function test_each_hash_column_is_unique_but_nulls_are_not(): void
    {
        $this->insertRow('server:1', str_repeat('a', 64));
        $this->insertRow('server:2', str_repeat('b', 64));
        $this->assertSame(2, DB::table('foundation_machine_tokens')->whereNull('pending_hash')->whereNull('previous_hash')->count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertRow('server:3', str_repeat('a', 64));
    }

    public function test_the_data_map_counts_the_table_when_enabled(): void
    {
        $map = ResolvedMap::from(new ShopDataMap);

        $this->assertSame('対象を消すとき、アプリが MachineTokens::revoke() を呼ぶ', $map->tables()['foundation_machine_tokens']->handledBy());
    }

    private function insertRow(string $subject, string $hash): void
    {
        DB::table('foundation_machine_tokens')->insert([
            'kind' => 'server-agent',
            'subject' => $subject,
            'current_hash' => $hash,
            'current_issued_at' => '2026-03-01 00:00:00',
            'created_at' => '2026-03-01 00:00:00',
        ]);
    }
}
