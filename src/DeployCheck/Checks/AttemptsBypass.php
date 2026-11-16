<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * foundation.attempts.bypass は production では効かないが、効かないことの知らせが1時間に1回出続ける
 */
final class AttemptsBypass implements Check
{
    public function id(): string
    {
        return 'attempts_bypass';
    }

    public function label(): string
    {
        return '試みの数えを外す設定（foundation.attempts.bypass）が偽';
    }

    public function run(): Result
    {
        return config('foundation.attempts.bypass') ? Result::fail() : Result::ok();
    }
}
