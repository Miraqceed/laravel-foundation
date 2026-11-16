<?php

namespace Miraqceed\Foundation\Tests\Feature\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Events\ClassificationFailed;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Miraqceed\Foundation\Ai\AiLimitReached;
use Miraqceed\Foundation\Ai\AiStore;
use Miraqceed\Foundation\Tests\Support\RefreshesAiDatabase;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

use function Laravel\Ai\agent;

/** 送った後の記録：呼び出しごとに使用量の行を残し、上限に数える額を合計に足す */
class AiRecorderTest extends TestCase
{
    use RefreshesAiDatabase;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.ai.enabled', true);
        $app['config']->set('foundation.ai.yen_per_usd', 150);
        $app['config']->set('foundation.ai.prices', [
            'anthropic' => ['claude-test' => ['input' => 3, 'output' => 15, 'cache_write' => 3.75, 'cache_read' => 0.3]],
            'openai' => ['gpt-test' => ['input' => 1, 'output' => 2, 'cache_write' => 1, 'cache_read' => 0.5]],
        ]);
        $app['config']->set('ai.providers.anthropic.key', 'test-key');
        $app['config']->set('ai.providers.openai.key', 'test-key');
        // アプリが付けた名前（name）と種類（driver）が違う会社
        $app['config']->set('ai.providers.claude-main', ['driver' => 'anthropic', 'key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        $this->forgetSeparateAiRows();

        parent::tearDown();
    }

    /** Task 2 と同じトークン（単価 3・15・3.75・0.3、150円で 668,250 マイクロ円） */
    private static function usage(): TextUsage
    {
        return new TextUsage(inputTokens: 1000, outputTokens: 200, cacheReadInputTokens: 600, cacheWriteInputTokens: 100, reasoningTokens: 30);
    }

    private function stepCompleted(string $provider = 'claude-main', string $model = 'claude-test', ?string $responded = 'claude-test-20261101', ?TextUsage $usage = null): StepCompleted
    {
        return new StepCompleted(
            'inv-text', 2, new AnonymousAgent('You are a test.', [], []),
            app(AiManager::class)->textProvider($provider), $model, true,
            new StepResponse('ok', [], FinishReason::Stop, $usage ?? self::usage(), new Meta($provider, $responded)),
            12.5,
        );
    }

    private function classificationPrompt(string $provider, string $model): ClassificationPrompt
    {
        return new ClassificationPrompt('Buy cheap pills now', [], app(AiManager::class)->classificationProvider($provider), $model);
    }

    private function rows(): array
    {
        return DB::connection(AiStore::connectionName())->table('foundation_ai_usages')->orderBy('id')->get()->all();
    }

    private function dayCost(): int
    {
        return app(AiStore::class)->totals()['day_cost'];
    }

    public function test_送った後と失敗の知らせを聞く(): void
    {
        foreach ([StepCompleted::class, Classified::class, StepFailed::class, ClassificationFailed::class] as $event) {
            $this->assertTrue(Event::hasListeners($event), $event);
        }
    }

    public function test_送った段を1行残し合計に足す(): void
    {
        event($this->stepCompleted());

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('text', $row->kind);
        $this->assertSame('completed', $row->status);
        $this->assertSame('inv-text', $row->invocation_id);
        $this->assertSame(2, (int) $row->step_number);
        $this->assertSame(AnonymousAgent::class, $row->agent);
        $this->assertSame('claude-main', $row->provider_name);
        $this->assertSame('anthropic', $row->provider_driver);
        $this->assertSame('claude-test', $row->requested_model);
        $this->assertSame('claude-test-20261101', $row->responded_model);
        $this->assertSame(1000, (int) $row->input_tokens);
        $this->assertSame(200, (int) $row->output_tokens);
        $this->assertSame(600, (int) $row->cache_read_tokens);
        $this->assertSame(100, (int) $row->cache_write_tokens);
        $this->assertSame(30, (int) $row->reasoning_tokens);
        $this->assertSame(668_250, (int) $row->cost_micro_yen);
        $this->assertSame(668_250, (int) $row->counted_micro_yen);
        $this->assertTrue((bool) $row->priced);
        $this->assertNull($row->error);
        $this->assertNotNull($row->created_at);
        $this->assertSame(668_250, $this->dayCost());
    }

    public function test_単価のないモデルは見積もりで足し実際の原価は空(): void
    {
        event($this->stepCompleted(model: 'unknown-model', responded: null, usage: new TextUsage(inputTokens: 1000, outputTokens: 100)));

        $row = $this->rows()[0];
        $this->assertNull($row->cost_micro_yen);
        $this->assertFalse((bool) $row->priced);
        $this->assertNull($row->responded_model);
        // 正しい行の中でいちばん高い単価（入力 3・出力 15）で数える。0円にしない
        $estimate = (1000 * 3 + 100 * 15) * 150;
        $this->assertSame($estimate, (int) $row->counted_micro_yen);
        $this->assertSame($estimate, $this->dayCost());
    }

    public function test_分類も1行残す(): void
    {
        $prompt = $this->classificationPrompt('openai', 'gpt-test');
        event(new Classified(
            'inv-class', $prompt->provider, 'gpt-test', $prompt,
            new ClassificationResponse([], new TextUsage(inputTokens: 1000, outputTokens: 10), new Meta('openai', 'gpt-test-2026')),
        ));

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('classification', $row->kind);
        $this->assertSame('completed', $row->status);
        $this->assertSame('inv-class', $row->invocation_id);
        $this->assertNull($row->step_number);
        $this->assertNull($row->agent);
        $this->assertSame('openai', $row->provider_name);
        $this->assertSame('openai', $row->provider_driver);
        $this->assertSame('gpt-test', $row->requested_model);
        $this->assertSame('gpt-test-2026', $row->responded_model);
        $this->assertSame((1000 * 1 + 10 * 2) * 150, (int) $row->cost_micro_yen);
        $this->assertSame((1000 * 1 + 10 * 2) * 150, $this->dayCost());
    }

    public function test_失敗の段と分類の失敗は行を残し金額に足さない(): void
    {
        event(new StepFailed(
            'inv-text', 1, new AnonymousAgent('You are a test.', [], []),
            app(AiManager::class)->textProvider('claude-main'), 'claude-test', false,
            new RuntimeException('秘密の入りうる文'), 3.0,
        ));
        $prompt = $this->classificationPrompt('openai', 'gpt-test');
        event(new ClassificationFailed('inv-class', $prompt->provider, 'gpt-test', $prompt, new ProviderOverloadedException('混み合い')));

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        [$step, $class] = $rows;

        $this->assertSame('text', $step->kind);
        $this->assertSame(1, (int) $step->step_number);
        $this->assertSame(AnonymousAgent::class, $step->agent);
        $this->assertSame('classification', $class->kind);
        $this->assertNull($class->step_number);
        foreach ($rows as $row) {
            $this->assertSame('failed', $row->status);
            $this->assertSame(0, (int) $row->input_tokens);
            $this->assertSame(0, (int) $row->output_tokens);
            $this->assertNull($row->cost_micro_yen);
            $this->assertNull($row->counted_micro_yen);
            $this->assertFalse((bool) $row->priced);
            $this->assertNull($row->responded_model);
        }
        // 例外の文は残さず、クラス名だけ
        $this->assertSame(RuntimeException::class, $step->error);
        $this->assertSame(ProviderOverloadedException::class, $class->error);
        $this->assertSame(0, $this->dayCost());
    }

    public function test_上限で止めた呼び出しは失敗の行を残さない(): void
    {
        event(new StepFailed(
            'inv-text', 1, new AnonymousAgent('You are a test.', [], []),
            app(AiManager::class)->textProvider('anthropic'), 'claude-test', true,
            new AiLimitReached('day_cost'), 0.1,
        ));
        $prompt = $this->classificationPrompt('openai', 'gpt-test');
        event(new ClassificationFailed('inv-class', $prompt->provider, 'gpt-test', $prompt, new AiLimitReached('day_calls')));

        $this->assertSame([], $this->rows());
    }

    public function test_キャッシュの値が_null_の答えも記録できる(): void
    {
        event($this->stepCompleted(usage: new TextUsage(inputTokens: 1000, outputTokens: 0)));
        event($this->stepCompleted(usage: new TextUsage(inputTokens: 1000, outputTokens: 0, cacheReadInputTokens: 0, cacheWriteInputTokens: 0, reasoningTokens: 0)));

        [$unreported, $zero] = $this->rows();
        // 値がない（null）のと 0 を分けて持つ
        $this->assertNull($unreported->cache_read_tokens);
        $this->assertNull($unreported->cache_write_tokens);
        $this->assertNull($unreported->reasoning_tokens);
        $this->assertSame(0, (int) $zero->cache_read_tokens);
        $this->assertNotNull($zero->cache_read_tokens);
        $this->assertSame(0, (int) $zero->reasoning_tokens);
        $this->assertNotNull($zero->reasoning_tokens);
        $this->assertSame(450_000, (int) $unreported->cost_micro_yen);
        $this->assertSame(900_000, $this->dayCost());
    }

    public function test_記録に失敗しても本筋を止めない(): void
    {
        Exceptions::fake();
        $this->app->instance(AiStore::class, new class extends AiStore
        {
            public function record(array $row): void
            {
                throw new RuntimeException('書けません');
            }

            public function addCost(int $microYen): void
            {
                throw new RuntimeException('足せません');
            }
        });
        AnonymousAgent::fake(['ok']);

        $response = agent('You are a test.')->prompt('hello');

        $this->assertSame('ok', $response->text);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === '書けません');
        // 行を書けなくても、合計へ足すことは試す（上限が効くように）
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === '足せません');
    }

    public function test_足すのに失敗しても行は残る(): void
    {
        Exceptions::fake();
        $this->app->instance(AiStore::class, new class extends AiStore
        {
            public function addCost(int $microYen): void
            {
                throw new RuntimeException('足せません');
            }
        });

        event($this->stepCompleted());

        $this->assertCount(1, $this->rows());
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === '足せません');
    }

    public function test_切り替えた先の呼び出しを記録する(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529),
            'api.openai.com/*' => Http::response([
                'id' => 'resp_01',
                'object' => 'response',
                'status' => 'completed',
                'model' => 'gpt-test-2026',
                'output' => [[
                    'type' => 'message',
                    'id' => 'msg_01',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => 'ok', 'annotations' => []]],
                ]],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 10],
            ]),
        ]);

        $response = agent('You are a test.')->prompt('hello', provider: ['anthropic' => 'claude-test', 'openai' => 'gpt-test']);

        $this->assertSame('ok', $response->text);
        $rows = $this->rows();
        $this->assertCount(2, $rows);
        [$first, $second] = $rows;
        $this->assertSame('anthropic', $first->provider_name);
        $this->assertSame('failed', $first->status);
        $this->assertSame(ProviderOverloadedException::class, $first->error);
        $this->assertSame('openai', $second->provider_name);
        $this->assertSame('completed', $second->status);
        $this->assertSame('gpt-test', $second->requested_model);
        $this->assertSame('gpt-test-2026', $second->responded_model);
        $this->assertSame((1000 * 1 + 10 * 2) * 150, (int) $second->counted_micro_yen);
        $this->assertSame((1000 * 1 + 10 * 2) * 150, $this->dayCost());
    }

    public function test_外のトランザクションを巻き戻しても記録は残る(): void
    {
        if (config('database.connections.'.config('database.default').'.driver') === 'sqlite') {
            $this->markTestSkipped('SQLite は別の接続を使わない');
        }

        AiStore::$separateInTests = true;

        DB::beginTransaction();
        event($this->stepCompleted());
        DB::rollBack();

        $this->assertCount(1, $this->rows());
        $this->assertSame(668_250, $this->dayCost());
    }

    public function test_偽の答えでも記録する(): void
    {
        AnonymousAgent::fake(['ok']);

        agent('You are a test.')->prompt('hello');

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('completed', $rows[0]->status);
        $this->assertSame((int) $rows[0]->counted_micro_yen, $this->dayCost());
    }
}
