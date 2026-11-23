<?php

namespace Miraqceed\Foundation\Integrity;

/**
 * 命令の始めから数える1つの期限。全部の調べと一覧の命令で分け合う
 *
 * @internal
 */
final class Deadline
{
    private readonly float $end;

    public function __construct(float $seconds)
    {
        $this->end = microtime(true) + max(0.0, $seconds);
    }

    public static function fromConfig(): self
    {
        return new self((float) config('foundation.integrity.seconds', 240));
    }

    public function passed(): bool
    {
        return microtime(true) >= $this->end;
    }

    public function remaining(): float
    {
        return max(0.0, $this->end - microtime(true));
    }
}
