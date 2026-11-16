<?php

namespace Miraqceed\Foundation\Redaction;

/**
 * 伏せた結果。伏せた後の文（入れ子の配列なら形を保った配列）と、伏せたもの
 */
final readonly class Redacted
{
    /**
     * @param  string|array<mixed>  $value
     * @param  list<Finding>  $findings
     */
    public function __construct(public string|array $value, public array $findings) {}

    public function found(): bool
    {
        return $this->findings !== [];
    }
}
