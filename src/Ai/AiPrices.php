<?php

namespace Miraqceed\Foundation\Ai;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * 単価の表（ドライバー → モデル → 100万トークンあたりのドル）から、呼び出しの原価を求める。
 * 単価のないモデルを0円にしない：正しい行の中の最高の単価で数え、実際の原価は null にする。
 */
final class AiPrices
{
    private const KEYS = ['input', 'output', 'cache_write', 'cache_read'];

    /** @var array<string, array<string, array<string, float>>> 正しい行だけ */
    private array $rows = [];

    /** @var array<string, float> 正しい行の中の単価ごとの最大 */
    private array $max = [];

    private function __construct(array $prices, private readonly mixed $yenPerUsd)
    {
        foreach ($prices as $driver => $models) {
            if (! is_array($models)) {
                continue;
            }
            foreach ($models as $model => $row) {
                $valid = $this->validRow($row);
                if ($valid === null) {
                    continue;
                }
                $this->rows[(string) $driver][(string) $model] = $valid;
                foreach ($valid as $key => $price) {
                    $this->max[$key] = max($this->max[$key] ?? 0.0, $price);
                }
            }
        }
    }

    public static function fromConfig(): self
    {
        $prices = config('foundation.ai.prices', []);

        return new self(is_array($prices) ? $prices : [], config('foundation.ai.yen_per_usd'));
    }

    /** 4つの単価がすべて0以上の有限の数の行だけ正しい。 */
    private function validRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $valid = [];
        foreach (self::KEYS as $key) {
            $value = $row[$key] ?? null;
            $number = self::finite($value);
            if ($number === null || $number < 0) {
                return null;
            }
            $valid[$key] = $number;
        }

        return $valid;
    }

    private function yen(): ?float
    {
        $yen = self::finite($this->yenPerUsd);

        return $yen !== null && $yen > 0 ? $yen : null;
    }

    /** 有限の数（文字列の数を含む）なら float、それ以外は null。"1e999" は INF になるので数とみなさない */
    private static function finite(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    public function countable(): bool
    {
        return $this->max !== [] && $this->yen() !== null;
    }

    public function cost(string $driver, string $requestedModel, ?string $respondedModel, TextUsage $usage): Cost
    {
        if (! $this->countable()) {
            return new Cost(null, 0, false);
        }

        $row = $this->rows[$driver][$requestedModel]
            ?? ($respondedModel !== null ? ($this->rows[$driver][$respondedModel] ?? null) : null);

        if ($row !== null) {
            $micro = $this->micro($row, $usage);

            return new Cost($micro, $micro, true);
        }

        return new Cost(null, $this->micro($this->max, $usage), false);
    }

    /** トークン × 単価（ドル/100万） × 為替 がそのままマイクロ円になる。 */
    private function micro(array $price, TextUsage $usage): int
    {
        $sum = $usage->uncachedInputTokens() * $price['input']
            + ($usage->cacheWriteInputTokens ?? 0) * $price['cache_write']
            + ($usage->cacheReadInputTokens ?? 0) * $price['cache_read']
            + $usage->outputTokens * $price['output'];

        return (int) round($sum * $this->yen());
    }
}
