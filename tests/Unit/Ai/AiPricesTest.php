<?php

namespace Miraqceed\Foundation\Tests\Unit\Ai;

use Laravel\Ai\Responses\Data\TextUsage;
use Miraqceed\Foundation\Ai\AiPrices;
use Miraqceed\Foundation\Ai\Cost;
use Miraqceed\Foundation\Tests\TestCase;

class AiPricesTest extends TestCase
{
    private function prices(array $prices, mixed $yen = 150): AiPrices
    {
        config(['foundation.ai.prices' => $prices, 'foundation.ai.yen_per_usd' => $yen]);

        return AiPrices::fromConfig();
    }

    private function table(): array
    {
        return ['anthropic' => [
            'sonnet' => ['input' => 3, 'output' => 15, 'cache_write' => 3.75, 'cache_read' => 0.3],
            'haiku' => ['input' => 5, 'output' => 10, 'cache_write' => 3.75, 'cache_read' => 0.3],
        ]];
    }

    public function test_キャッシュを二重に数えない(): void
    {
        $usage = new TextUsage(inputTokens: 1000, outputTokens: 200, cacheReadInputTokens: 600, cacheWriteInputTokens: 100);
        $cost = $this->prices($this->table())->cost('anthropic', 'sonnet', null, $usage);

        $this->assertEquals(new Cost(668_250, 668_250, true), $cost);
    }

    public function test_キャッシュの値が_null_なら_0_として数える(): void
    {
        $cost = $this->prices($this->table())->cost('anthropic', 'sonnet', null, new TextUsage(inputTokens: 1000, outputTokens: 0));

        $this->assertSame(450_000, $cost->actualMicroYen);
    }

    public function test_頼んだ名前になく答えた名前にあれば答えた名前で数える(): void
    {
        $cost = $this->prices($this->table())->cost('anthropic', 'alias', 'sonnet', new TextUsage(inputTokens: 1000));

        $this->assertTrue($cost->priced);
        $this->assertSame(450_000, $cost->actualMicroYen);
    }

    public function test_単価のないモデルは最高の単価で数える(): void
    {
        $cost = $this->prices($this->table())->cost('anthropic', 'unknown', null, new TextUsage(inputTokens: 1000, outputTokens: 100));

        $this->assertNull($cost->actualMicroYen);
        $this->assertFalse($cost->priced);
        // input 5・output 15 で数える
        $this->assertSame((1000 * 5 + 100 * 15) * 150, $cost->countedMicroYen);
    }

    public function test_欠けた単価の行は単価なしで高い単価にも入れない(): void
    {
        $table = $this->table();
        $table['anthropic']['broken'] = ['input' => 100, 'output' => 100, 'cache_write' => 100];
        $prices = $this->prices($table);

        $cost = $prices->cost('anthropic', 'broken', null, new TextUsage(inputTokens: 1000));
        $this->assertFalse($cost->priced);
        $this->assertNull($cost->actualMicroYen);
        $this->assertSame(1000 * 5 * 150, $cost->countedMicroYen);
    }

    public function test_表が空か為替が正でなければ数えられない(): void
    {
        foreach ([[[], 150], [$this->table(), 0], [$this->table(), 'abc'], [$this->table(), -1]] as [$table, $yen]) {
            $prices = $this->prices($table, $yen);
            $this->assertFalse($prices->countable());
            $this->assertEquals(new Cost(null, 0, false), $prices->cost('anthropic', 'sonnet', null, new TextUsage(inputTokens: 10)));
        }
        $this->assertTrue($this->prices($this->table())->countable());
    }

    public function test_有限でない数の単価と為替は正しくない(): void
    {
        // "1e999" は数の文字列だが float にすると INF になる
        $table = $this->table();
        $table['anthropic']['huge'] = ['input' => '1e999', 'output' => 1, 'cache_write' => 1, 'cache_read' => 1];
        $table['anthropic']['nan'] = ['input' => NAN, 'output' => 1, 'cache_write' => 1, 'cache_read' => 1];
        $table['anthropic']['inf'] = ['input' => 1, 'output' => INF, 'cache_write' => 1, 'cache_read' => 1];
        $prices = $this->prices($table);

        foreach (['huge', 'nan', 'inf'] as $model) {
            $cost = $prices->cost('anthropic', $model, null, new TextUsage(inputTokens: 1000, outputTokens: 100));
            $this->assertFalse($cost->priced, $model);
            // 正しい行の中の最高の単価（input 5・output 15）で数える。INF を最高の単価にしない
            $this->assertSame((1000 * 5 + 100 * 15) * 150, $cost->countedMicroYen, $model);
        }

        foreach (['1e999', INF, NAN] as $yen) {
            $this->assertFalse($this->prices($this->table(), $yen)->countable(), var_export($yen, true));
        }
    }
}
