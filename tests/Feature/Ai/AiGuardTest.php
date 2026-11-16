<?php

namespace Miraqceed\Foundation\Tests\Feature\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Exceptions\FailoverableException;
use Miraqceed\Foundation\Ai\AiLimitReached;
use Miraqceed\Foundation\Ai\AiStore;
use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Tests\Support\AiLookupTool;
use Miraqceed\Foundation\Tests\Support\RefreshesAiDatabase;
use Miraqceed\Foundation\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;

use function Laravel\Ai\agent;

/** 送る前の守り：上限以上なら AI の会社へ送らない */
class AiGuardTest extends TestCase
{
    use RefreshesAiDatabase;

    private const ANTHROPIC = 'api.anthropic.com/*';

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    /** 月の境を確かめるため、アプリのタイムゾーンを UTC 以外にする（PHP のタイムゾーンはここで決まる） */
    protected function getApplicationTimezone($app)
    {
        return 'Asia/Tokyo';
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.ai.enabled', true);
        $app['config']->set('foundation.ai.prices', [
            'anthropic' => ['claude-test' => ['input' => 3, 'output' => 15, 'cache_write' => 3.75, 'cache_read' => 0.3]],
        ]);
        $app['config']->set('ai.providers.anthropic.key', 'test-key');
        $app['config']->set('ai.providers.openai.key', 'test-key');
    }

    protected function withMonitoring($app): void
    {
        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log']);
    }

    protected function tearDown(): void
    {
        $this->forgetSeparateAiRows();
        $this->travelBack();
        AiLookupTool::$calls = 0;

        parent::tearDown();
    }

    /** Anthropic の messages API の答え（文だけ） */
    private static function textReply(string $text = 'ok'): array
    {
        return [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-test',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /** Anthropic の messages API の答え（道具の呼び出し） */
    private static function toolUseReply(): array
    {
        return [
            'id' => 'msg_02',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-test',
            'content' => [
                ['type' => 'text', 'text' => 'Let me look that up.'],
                ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'AiLookupTool', 'input' => (object) []],
            ],
            'stop_reason' => 'tool_use',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
        ];
    }

    private function fakeAnthropic(): void
    {
        Http::fake([self::ANTHROPIC => Http::response(self::textReply())]);
    }

    /** 本物の流れで1回呼ぶ。止められたら例外を返す */
    private function attempt(): ?AiLimitReached
    {
        try {
            agent('You are a test.')->prompt('hello', provider: 'anthropic', model: 'claude-test');
        } catch (AiLimitReached $e) {
            return $e;
        }

        return null;
    }

    private function store(): AiStore
    {
        return app(AiStore::class);
    }

    private function resetTotals(): void
    {
        DB::connection(AiStore::connectionName())->table('foundation_ai_totals')->delete();
    }

    private function limits(mixed $day = null, mixed $month = null, mixed $calls = null): void
    {
        config([
            'foundation.ai.daily_limit_yen' => $day,
            'foundation.ai.monthly_limit_yen' => $month,
            'foundation.ai.daily_call_limit' => $calls,
        ]);
    }

    public function test_上限以上なら送らない(): void
    {
        $this->fakeAnthropic();
        $this->limits(day: 1);
        $this->store()->addCost(1_000_000);

        $e = $this->attempt();

        $this->assertInstanceOf(AiLimitReached::class, $e);
        $this->assertSame('day_cost', $e->reason);
        Http::assertNothingSent();
        $this->assertSame(0, $this->store()->totals()['day_calls']);
    }

    public function test_月の上限と回数の上限でも止まる(): void
    {
        $this->fakeAnthropic();

        $this->limits(month: 1);
        $this->store()->addCost(1_000_000);
        $this->assertSame('month_cost', $this->attempt()?->reason);

        $this->resetTotals();
        $this->limits(calls: 1);
        $this->store()->addCall();
        $this->assertSame('day_calls', $this->attempt()?->reason);

        Http::assertNothingSent();
    }

    public function test_分類も止まる(): void
    {
        Http::fake();
        $this->limits(day: 1);
        $this->store()->addCost(1_000_000);

        try {
            Classification::of('Buy cheap pills now')
                ->question('spam', new Boolean('Is this spam?'))
                ->classify('openai', 'gpt-test');
            $this->fail('止まらなかった');
        } catch (AiLimitReached $e) {
            $this->assertSame('day_cost', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_上限の例外で会社を切り替えない(): void
    {
        Http::fake();
        Event::fake([AgentFailedOver::class]);
        $this->limits(day: 1);
        $this->store()->addCost(1_000_000);

        try {
            agent('You are a test.')->prompt('hello', provider: ['anthropic' => 'claude-test', 'openai' => 'gpt-test']);
            $this->fail('止まらなかった');
        } catch (AiLimitReached $e) {
            $this->assertNotInstanceOf(FailoverableException::class, $e);
        }

        Event::assertNotDispatched(AgentFailedOver::class);
        Http::assertNothingSent();
    }

    public function test_道具を使う会話の途中の段でも止まる(): void
    {
        Http::fake([self::ANTHROPIC => Http::sequence()
            ->push(self::toolUseReply())
            ->push(self::textReply('done'))]);
        $this->limits(calls: 1);

        try {
            agent('You are a test.', tools: [new AiLookupTool])->prompt('look it up', provider: 'anthropic', model: 'claude-test');
            $this->fail('止まらなかった');
        } catch (AiLimitReached $e) {
            $this->assertSame('day_calls', $e->reason);
        }

        $this->assertSame(1, AiLookupTool::$calls, '1つ目の段の道具は動いた');
        Http::assertSentCount(1);
    }

    public function test_数えられないとき_block_は止め_allow_は通す(): void
    {
        Exceptions::fake();
        $this->fakeAnthropic();
        $this->limits(day: 100);
        config(['foundation.ai.prices' => []]);

        $this->assertSame('uncountable', $this->attempt()?->reason);
        Http::assertNothingSent();

        config(['foundation.ai.when_uncountable' => 'allow']);
        $this->assertNull($this->attempt());
        Http::assertSentCount(1);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_金額の上限がなければ単価の表が空でも止めない(): void
    {
        $this->fakeAnthropic();
        $this->limits(calls: 10);
        config(['foundation.ai.prices' => []]);

        $this->assertNull($this->attempt());
        Http::assertSentCount(1);
    }

    public function test_空文字は上限なし・0はすべて止める・数でない値は数えられない(): void
    {
        Exceptions::fake();
        $this->fakeAnthropic();
        $sent = 0;

        // 空文字（.env の `FOUNDATION_AI_DAILY_LIMIT_YEN=`）は上限なし。単価の表が空でも止めない
        config(['foundation.ai.prices' => []]);
        $this->limits(day: '', month: '', calls: '');
        $this->assertNull($this->attempt());
        Http::assertSentCount(++$sent);
        $this->limits();
        $this->assertNull($this->attempt());
        Http::assertSentCount(++$sent);
        config(['foundation.ai.prices' => ['anthropic' => ['claude-test' => ['input' => 1, 'output' => 1, 'cache_write' => 1, 'cache_read' => 1]]]]);

        // 0 はすべて止める
        $this->resetTotals();
        $this->limits(day: '0');
        $this->assertSame('day_cost', $this->attempt()?->reason);
        $this->limits(month: 0);
        $this->assertSame('month_cost', $this->attempt()?->reason);
        $this->limits(calls: '0');
        $this->assertSame('day_calls', $this->attempt()?->reason);

        // 数でない・負の値は数えられない（既定の block で止める）
        foreach ([['day' => 'abc'], ['month' => '-1'], ['calls' => 'many'], ['calls' => -1], ['day' => true]] as $limit) {
            $this->limits(...$limit);
            $this->assertSame('uncountable', $this->attempt()?->reason, json_encode($limit));
        }
        Http::assertSentCount($sent);

        // 金額は小数も受ける（マイクロ円で比べる）
        $this->resetTotals();
        $this->limits(day: '1000.5');
        $this->store()->addCost(1_000_499_999);
        $this->assertNull($this->attempt());
        Http::assertSentCount(++$sent);
        $this->store()->addCost(1);
        $this->assertSame('day_cost', $this->attempt()?->reason);

        // 回数は小数を切り捨てる（1.9 は 1 回）
        $this->resetTotals();
        $this->limits(calls: '1.9');
        $this->assertNull($this->attempt());
        Http::assertSentCount(++$sent);
        $this->assertSame('day_calls', $this->attempt()?->reason);
        Http::assertSentCount($sent);
    }

    public function test_月が変わると月の合計は数え直す(): void
    {
        $this->assertSame('Asia/Tokyo', now()->tzName);
        $this->fakeAnthropic();
        $this->limits(month: 1);

        $this->travelTo('2026-11-30 23:30');
        $this->store()->addCost(1_000_000);
        $this->assertSame('month_cost', $this->attempt()?->reason);

        // UTC ではまだ 11/30 15:30。アプリのタイムゾーンでは 12 月なので数え直す
        $this->travelTo('2026-12-01 00:30');
        $this->assertNull($this->attempt());
        Http::assertSentCount(1);
    }

    #[DefineEnvironment('withMonitoring')]
    public function test_知らせは同じ日に1回(): void
    {
        $this->fakeAnthropic();
        $this->limits(day: 1);

        $this->travelTo('2026-11-16 10:00');
        $this->store()->addCost(1_000_000);
        $this->assertSame('day_cost', $this->attempt()?->reason);
        // 知らせの部品の間引き（約1時間）を越えても、同じ日には2回目を知らせない
        $this->travelTo('2026-11-16 12:00');
        $this->assertSame('day_cost', $this->attempt()?->reason);

        $this->assertSame(1, Alert::where('key', 'foundation.ai.limit:day_cost:2026-11-16')->count());
        $alert = Alert::where('key', 'foundation.ai.limit:day_cost:2026-11-16')->first();
        $this->assertSame('AI の上限に達しました（日の金額）', $alert->subject);
        $this->assertStringContainsString('1 円', $alert->body);

        $this->travelTo('2026-11-17 10:00');
        $this->store()->addCost(1_000_000);
        $this->assertSame('day_cost', $this->attempt()?->reason);

        $this->assertSame(1, Alert::where('key', 'foundation.ai.limit:day_cost:2026-11-17')->count());
        $this->assertSame(2, Alert::where('key', 'like', 'foundation.ai.limit:%')->count());
    }

    public function test_知らせを止めていても上限で止まる(): void
    {
        $this->assertFalse((bool) config('foundation.monitoring.enabled'));
        $this->fakeAnthropic();
        $this->limits(day: 1);
        $this->store()->addCost(1_000_000);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->assertSame('day_cost', $this->attempt()?->reason);

        Http::assertNothingSent();
        $touched = array_filter($queries, fn ($sql) => str_contains($sql, 'foundation_alert') || str_contains($sql, 'foundation_ai_notices'));
        $this->assertSame([], array_values($touched), '知らせの表にも印の表にも触れない');
    }

    public function test_無効なら聞き手を登録しない(): void
    {
        $this->assertTrue(Event::hasListeners(StartingStep::class), '有効なら登録する');
        $this->assertTrue(Event::hasListeners(Classifying::class));

        $this->forgetAiListeners();
        config(['foundation.ai.enabled' => false]);
        (new FoundationServiceProvider($this->app))->boot();

        $this->assertFalse(Event::hasListeners(StartingStep::class));
        $this->assertFalse(Event::hasListeners(Classifying::class));
    }

    public function test_SDK_がなければ聞き手を登録しない(): void
    {
        $this->forgetAiListeners();
        $provider = new class($this->app) extends FoundationServiceProvider
        {
            protected static function aiSdkInstalled(): bool
            {
                return false;
            }
        };
        $provider->boot();

        $this->assertFalse(Event::hasListeners(StartingStep::class));
        $this->assertFalse(Event::hasListeners(Classifying::class));
    }

    private function forgetAiListeners(): void
    {
        Event::forget(StartingStep::class);
        Event::forget(Classifying::class);
    }

    public function test_偽の答えでも数える(): void
    {
        AnonymousAgent::fake(['ok']);

        agent('You are a test.')->prompt('hello');

        $this->assertSame(1, $this->store()->totals()['day_calls']);
    }

    public function test_回数を足せなければ数えられないときと同じ(): void
    {
        Exceptions::fake();
        $this->fakeAnthropic();
        $this->app->instance(AiStore::class, new class extends AiStore
        {
            public function addCall(): void
            {
                throw new RuntimeException('書けません');
            }
        });

        $this->assertSame('uncountable', $this->attempt()?->reason);
        Http::assertNothingSent();

        config(['foundation.ai.when_uncountable' => 'allow']);
        $this->assertNull($this->attempt());
        Http::assertSentCount(1);
        Exceptions::assertReported(RuntimeException::class);
    }

    #[DefineEnvironment('withMonitoring')]
    public function test_トランザクションの中で止めても知らせは1回(): void
    {
        if (config('database.connections.'.config('database.default').'.driver') === 'sqlite') {
            $this->markTestSkipped('SQLite は別の接続を使わない');
        }

        AiStore::$separateInTests = true;
        Http::fake();
        Log::spy();
        $this->limits(day: 1);
        $this->travelTo('2026-11-16 10:00');
        $this->store()->addCost(1_000_000);

        foreach (['2026-11-16 10:00', '2026-11-16 12:00'] as $at) {
            $this->travelTo($at);
            try {
                DB::transaction(fn () => agent('You are a test.')->prompt('hello', provider: 'anthropic', model: 'claude-test'));
                $this->fail('止まらなかった');
            } catch (AiLimitReached $e) {
                $this->assertSame('day_cost', $e->reason);
            }
        }

        // 知らせの行は巻き戻ったが、印は別の接続に残るので2回目は送らない
        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('channel')->once();
        Http::assertNothingSent();
    }

    public function test_送る前に使用量の表を読まない(): void
    {
        AnonymousAgent::fake(['ok']);
        $this->limits(day: 100, month: 1000, calls: 10);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        agent('You are a test.')->prompt('hello');

        $this->assertNotEmpty(array_filter($queries, fn ($sql) => str_contains($sql, 'foundation_ai_totals')), '合計の表は読む');
        $reads = array_filter($queries, fn ($sql) => str_contains($sql, 'foundation_ai_usages') && stripos(ltrim($sql), 'select') === 0);
        $this->assertSame([], array_values($reads));
    }
}
