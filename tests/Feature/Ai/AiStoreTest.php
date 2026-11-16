<?php

namespace Miraqceed\Foundation\Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Ai\AiStore;
use Miraqceed\Foundation\Ai\AiUsage;
use Miraqceed\Foundation\Tests\TestCase;

/** AI の費用の上限と記録の読み書きの口（日ごとの合計・使用量の行・知らせの印） */
class AiStoreTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as refreshWithoutAiTables;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.ai.enabled', true);
    }

    /**
     * RefreshDatabase は 1 プロセスで 1 回しか migrate:fresh しない。ほかのクラスが AI の表なしで
     * 作った後でも、この試しは AI の表を作り直す。
     */
    protected function refreshTestDatabase(): void
    {
        if (! Schema::hasTable('foundation_ai_totals')) {
            RefreshDatabaseState::$migrated = false;
        }

        $this->refreshWithoutAiTables();
    }

    protected function tearDown(): void
    {
        // 別の接続の書き込みは RefreshDatabase で巻き戻らない。行だけを消す（DROP は MySQL で暗黙にコミットする）
        if (AiStore::$separateInTests) {
            foreach (['foundation_ai_usages', 'foundation_ai_totals', 'foundation_ai_notices'] as $table) {
                DB::connection(AiStore::connectionName())->table($table)->delete();
            }
        }
        AiStore::$separateInTests = false;
        $this->travelBack();

        parent::tearDown();
    }

    private function row(): array
    {
        return [
            'kind' => 'text',
            'status' => 'completed',
            'invocation_id' => 'inv-1',
            'provider_name' => 'anthropic',
            'provider_driver' => 'anthropic',
            'requested_model' => 'm',
            'cost_micro_yen' => 10,
            'priced' => true,
            'created_at' => now(),
        ];
    }

    public function test_同じ日に2回足すと2回分になる(): void
    {
        $store = app(AiStore::class);
        $store->addCall();
        $store->addCall();
        $store->addCost(1_500_000);
        $store->addCost(1_500_000);

        $this->assertSame(['day_cost' => 3_000_000, 'month_cost' => 3_000_000, 'day_calls' => 2], $store->totals());
    }

    public function test_月の合計は同じ月の日だけを足す(): void
    {
        $store = app(AiStore::class);

        $this->travelTo('2026-10-31 12:00');
        $store->addCost(1_000_000);
        $this->travelTo('2026-11-01 12:00');
        $store->addCost(2_000_000);

        $totals = $store->totals();
        $this->assertSame(2_000_000, $totals['month_cost']);
        $this->assertSame(2_000_000, $totals['day_cost']);
    }

    public function test_SQLiteとテストの実行中は既定の接続(): void
    {
        $this->assertFalse(AiStore::$separateInTests);
        $this->assertSame(config('database.default'), AiStore::connectionName());
    }

    public function test_別の接続なら巻き戻しても記録が残る(): void
    {
        if (config('database.connections.'.config('database.default').'.driver') === 'sqlite') {
            $this->markTestSkipped('SQLite は別の接続を使わない');
        }

        AiStore::$separateInTests = true;
        $store = app(AiStore::class);

        DB::beginTransaction();
        $store->addCall();
        $store->record($this->row());
        DB::rollBack();

        $this->assertSame('foundation_ai', AiStore::connectionName());
        $this->assertSame(1, $store->totals()['day_calls']);
        $this->assertSame(1, DB::connection(AiStore::connectionName())->table('foundation_ai_usages')->count());
    }

    public function test_知らせの印は1回だけ立つ(): void
    {
        $store = app(AiStore::class);

        $this->assertTrue($store->claimNotice('daily:2026-11-16'));
        $this->assertFalse($store->claimNotice('daily:2026-11-16'));
    }

    public function test_memoryのSQLiteでも合計を読める(): void
    {
        if (config('database.connections.'.config('database.default').'.driver') !== 'sqlite') {
            $this->markTestSkipped('SQLite の試し');
        }

        $this->assertSame(['day_cost' => 0, 'month_cost' => 0, 'day_calls' => 0], app(AiStore::class)->totals());
    }

    public function test_古い使用量の行を消す(): void
    {
        config(['foundation.ai.prune_days' => 90]);
        $store = app(AiStore::class);
        $store->record(['invocation_id' => 'old'] + ['created_at' => now()->subDays(91)] + $this->row());
        $store->record(['invocation_id' => 'new'] + ['created_at' => now()->subDays(89)] + $this->row());

        Artisan::call('model:prune', ['--model' => [AiUsage::class]]);

        $this->assertSame(['new'], AiUsage::query()->pluck('invocation_id')->all());
    }
}
