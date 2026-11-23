<?php

namespace Miraqceed\Foundation\Integrity;

use RuntimeException;

/**
 * 一覧が使えない（ない・頭の行が読めない・版や根が違う）
 *
 * @internal
 */
final class SnapshotUnavailable extends RuntimeException
{
    public function __construct(public readonly Gap $gap)
    {
        parent::__construct($gap->label());
    }
}
