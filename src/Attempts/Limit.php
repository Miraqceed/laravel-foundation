<?php

namespace Miraqceed\Foundation\Attempts;

/**
 * 窓のある枠1つ
 */
final readonly class Limit
{
    /**
     * @param  string  $key  数えの鍵（AttemptKey::for() で作る）
     * @param  int  $max  窓の中で照合する上限
     * @param  int  $decaySeconds  窓の秒数
     */
    public function __construct(
        public string $key,
        public int $max,
        public int $decaySeconds,
        public Counting $counting = Counting::FailuresOwn,
    ) {}
}
