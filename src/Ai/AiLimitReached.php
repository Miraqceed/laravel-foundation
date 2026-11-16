<?php

namespace Miraqceed\Foundation\Ai;

use RuntimeException;
use Throwable;

/**
 * AI の費用の上限に達した（または使った額を数えられない）ので、AI の会社へ送らずに止めた。
 * アプリは捕まえて「今は AI を使えません」と出せる。
 *
 * Laravel AI SDK の FailoverableException を実装しない：実装すると、SDK が次の会社へ切り替えて送ってしまう。
 */
class AiLimitReached extends RuntimeException
{
    /** @var array<string, string> */
    public const REASONS = [
        'day_cost' => '日の金額',
        'month_cost' => '月の金額',
        'day_calls' => '日の回数',
        'uncountable' => '使った額を数えられない',
    ];

    /** @param  string  $reason  day_cost・month_cost・day_calls・uncountable */
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('AI の上限に達したため送りませんでした（'.(self::REASONS[$reason] ?? $reason).'）', 0, $previous);
    }
}
