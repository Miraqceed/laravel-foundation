<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

class FailingCheck implements Check
{
    public function id(): string
    {
        return 'app_fail';
    }

    public function label(): string
    {
        return '落ちる調べ';
    }

    public function run(): Result
    {
        return Result::fail('形が違う');
    }
}
