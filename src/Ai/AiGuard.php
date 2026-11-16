<?php

namespace Miraqceed\Foundation\Ai;

use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\StartingStep;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
use RuntimeException;
use Throwable;

/**
 * 送る前の守り。AI の会社へ1回送る直前（文の生成の段・分類）に、今日・今月の合計が上限以上なら
 * AiLimitReached を投げて送らせない。通ったら今日の回数を1つ足す。
 *
 * 回数を足せないときは、回数の上限があれば数えられないときと同じに扱い、なければ report() して送る。
 *
 * 合計を見てから送るので、最後の1回の分と、同時に走る呼び出しの分だけ上限を超えうる。
 * 日・月の区切りは呼ぶたびに now()（アプリのタイムゾーン）で決める（長く動くワーカーでも日付を覚えない）。
 */
class AiGuard
{
    public function __construct(
        private readonly AiStore $store,
        private readonly AlertSender $alerts,
    ) {}

    public function handleStartingStep(StartingStep $event): void
    {
        $this->guard();
    }

    public function handleClassifying(Classifying $event): void
    {
        $this->guard();
    }

    private function guard(): void
    {
        $this->check();

        try {
            $this->store->addCall();
        } catch (Throwable $e) {
            if (self::blank(config('foundation.ai.daily_call_limit'))) {
                // 回数の上限がなければ、回数を足せなくても効かなくなる上限はない（金額の合計は読めた）
                report($e);

                return;
            }

            // 回数を足せないと回数の上限が効かない。数えられないときと同じに扱う
            $this->uncountable('呼び出しの回数を足せません', $e);
        }
    }

    /** 上限の設定が空（上限なし）か */
    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * 上限以上なら AiLimitReached を投げる。使った額を数えられないときは when_uncountable に従う。
     */
    public function check(): void
    {
        $limits = $this->limits();
        if (is_string($limits)) {
            $this->uncountable($limits);

            return;
        }

        if ($limits['day_cost'] === null && $limits['month_cost'] === null && $limits['day_calls'] === null) {
            return;
        }

        if (($limits['day_cost'] !== null || $limits['month_cost'] !== null) && ! AiPrices::fromConfig()->countable()) {
            $this->uncountable('単価の表（foundation.ai.prices）が空か、為替（yen_per_usd）が正しくないため、金額を数えられません');

            return;
        }

        try {
            $totals = $this->store->totals();
        } catch (Throwable $e) {
            $this->uncountable('使った額の合計を読めません', $e);

            return;
        }

        foreach ($limits as $reason => $limit) {
            if ($limit !== null && $totals[$reason] >= $limit) {
                $this->notify($reason, $this->describe($limits, $totals));

                throw new AiLimitReached($reason);
            }
        }
    }

    /**
     * 上限の設定（金額はマイクロ円、回数は回）。null は上限なし。読めない値があれば理由の文。
     *
     * @return array{day_cost: ?int, month_cost: ?int, day_calls: ?int}|string
     */
    private function limits(): array|string
    {
        $limits = [];
        foreach (['day_cost' => 'daily_limit_yen', 'month_cost' => 'monthly_limit_yen', 'day_calls' => 'daily_call_limit'] as $reason => $key) {
            $value = config("foundation.ai.{$key}");
            if (self::blank($value)) {
                $limits[$reason] = null;

                continue;
            }

            $number = self::number($value);
            if ($number === null) {
                return "上限の設定 foundation.ai.{$key} を0以上の数として読めません";
            }

            $limits[$reason] = $reason === 'day_calls'
                ? self::toInt(floor($number))
                : self::toInt(round($number * 1_000_000));
        }

        return $limits;
    }

    /** 0以上の有限の数（文字列の数を含む）なら float、それ以外は null */
    private static function number(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number >= 0 ? $number : null;
    }

    private static function toInt(float $number): int
    {
        return $number >= PHP_INT_MAX ? PHP_INT_MAX : (int) $number;
    }

    /**
     * 使った額を数えられない。report() し、block（既定。allow 以外）なら止める。
     */
    private function uncountable(string $why, ?Throwable $cause = null): void
    {
        $problem = new RuntimeException("AI の使った額を数えられません：{$why}", 0, $cause);
        report($problem);

        if (config('foundation.ai.when_uncountable') === 'allow') {
            return;
        }

        $detail = $cause === null ? '' : "\n".get_class($cause).': '.mb_substr($cause->getMessage(), 0, 300);
        $this->notify('uncountable', "{$why}。AI を呼ばずに止めました（foundation.ai.when_uncountable = block）。{$detail}");

        throw new AiLimitReached('uncountable', $problem);
    }

    /**
     * 運営へ知らせる。日（月の上限は月）・理由ごとに1回だけ。印は巻き戻らない接続に置く
     * （知らせの行は既定の接続にあり、アプリのトランザクションの中で止めると巻き戻って、やり直すたびに届くため）。
     * 知らせの失敗で止め方を変えない。
     */
    private function notify(string $reason, string $body): void
    {
        if (! config('foundation.monitoring.enabled')) {
            return;
        }

        try {
            $period = $reason === 'month_cost' ? now()->format('Y-m') : now()->format('Y-m-d');
            $key = "foundation.ai.limit:{$reason}:{$period}";

            if ($this->store->claimNotice($key)) {
                $this->alerts->send($key, 'AI の上限に達しました（'.AiLimitReached::REASONS[$reason].'）', $body);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array{day_cost: ?int, month_cost: ?int, day_calls: ?int}  $limits
     * @param  array{day_cost: int, month_cost: int, day_calls: int}  $totals
     */
    private function describe(array $limits, array $totals): string
    {
        $limit = fn (?int $value, bool $yen) => $value === null ? 'なし' : ($yen ? self::yen($value) : number_format($value).' 回');

        return implode("\n", [
            '上限：日の金額 '.$limit($limits['day_cost'], true)
                .'・月の金額 '.$limit($limits['month_cost'], true)
                .'・日の回数 '.$limit($limits['day_calls'], false),
            '今の合計：日の金額 '.self::yen($totals['day_cost'])
                .'・月の金額 '.self::yen($totals['month_cost'])
                .'・日の回数 '.number_format($totals['day_calls']).' 回',
            '日の上限と回数は翌日、月の上限は翌月に数え直します（アプリのタイムゾーン '.now()->tzName.'）。',
        ]);
    }

    private static function yen(int $microYen): string
    {
        $text = rtrim(rtrim(number_format($microYen / 1_000_000, 6), '0'), '.');

        return "{$text} 円";
    }
}
