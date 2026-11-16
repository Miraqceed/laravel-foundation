<?php

namespace Miraqceed\Foundation\Tests\Feature\Ai;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Ai\AiStore;
use Miraqceed\Foundation\Ai\AiUsage;
use Miraqceed\Foundation\Tests\Support\RefreshesAiDatabase;
use Miraqceed\Foundation\Tests\TestCase;

/** AI の費用の上限と記録の読み書きの口（日ごとの合計・使用量の行・知らせの印） */
class AiStoreTest extends TestCase
{
    use RefreshesAiDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.ai.enabled', true);
    }

    protected function tearDown(): void
    {
        $this->forgetSeparateAiRows();
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

    public function test_アプリが定めた接続を使い_合計は書き込みの接続から読む(): void
    {
        if (config('database.connections.'.config('database.default').'.driver') === 'sqlite') {
            $this->markTestSkipped('SQLite は別の接続を使わない');
        }

        // アプリが foundation_ai を自分で定める。読み込みは遅れる複製（ここでは届かない先）へ向いている
        $default = config('database.connections.'.config('database.default'));
        config(['database.connections.foundation_ai' => [...$default, 'read' => ['host' => '127.0.0.1', 'port' => 1], 'write' => []]]);
        AiStore::$separateInTests = true;
        $store = app(AiStore::class);

        $this->assertSame('foundation_ai', AiStore::connectionName());
        $this->assertSame(['host' => '127.0.0.1', 'port' => 1], config('database.connections.foundation_ai.read'), '写しで上書きしない');

        // 書き込みの前に読む（送る前の確かめは書く前に読むので、sticky では書き込みの接続に戻らない）
        $this->assertSame(['day_cost' => 0, 'month_cost' => 0, 'day_calls' => 0], $store->totals());
        $store->addCost(1_500_000);
        $store->addCall();
        $this->assertSame(['day_cost' => 1_500_000, 'month_cost' => 1_500_000, 'day_calls' => 1], $store->totals());
        $this->assertTrue($store->claimNotice('daily:2026-11-16'));
        $this->assertFalse($store->claimNotice('daily:2026-11-16'));
    }

    public function test_既定の接続で書くとき_失敗してもアプリのトランザクションを壊さない(): void
    {
        $this->assertSame(config('database.default'), AiStore::connectionName());
        $store = app(AiStore::class);

        // RefreshDatabase のトランザクションの中。PostgreSQL は失敗した文でトランザクションごと使えなくなる
        DB::transaction(function () use ($store) {
            try {
                $store->record(['no_such_column' => 1]);
                $this->fail('書けてしまった');
            } catch (QueryException) {
            }

            $this->assertSame(0, DB::table('foundation_ai_usages')->count());
            $store->addCall();
            $this->assertSame(1, $store->totals()['day_calls']);
        });
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
